<?php
/**
 * Zipping a working set before it is deleted.
 *
 * Deleting media is permanent and removes every generated size, so the archive
 * exists to make the decision reversible: build the zip, download it, then
 * delete. If someone turns out to have needed a file, it came back from the zip
 * rather than from a database restore.
 *
 * Built in batches over AJAX for the same reason the scan is -- a 165 MB
 * selection will not zip inside one request on shared hosting -- and split into
 * parts, so a failed download costs one part rather than the whole archive.
 * Each part closes cleanly at the end of every step, so an interrupted job
 * leaves valid zips rather than a truncated one.
 *
 * Only the original file goes in, not the generated sizes: WordPress can
 * rebuild those, and on a library that is mostly PDFs and video they would be
 * dead weight. The manifest carries what a restore needs.
 *
 * @package UAMS_Media_Reference_Audit
 */

defined( 'ABSPATH' ) || exit;

/**
 * Builds, serves, and cleans up zip archives of a working set.
 */
class UAMS_MRA_Archive {

	/**
	 * User meta holding the current job.
	 */
	const META_KEY = '_uams_mra_archive';

	/**
	 * Directory under uploads that holds every job.
	 */
	const DIR = 'uams-mra-archives';

	/**
	 * Hook name for the cleanup event.
	 */
	const CLEANUP_HOOK = 'uams_mra_cleanup_archives';

	/**
	 * How long a finished archive survives before cleanup removes it.
	 */
	const TTL = DAY_IN_SECONDS;

	/**
	 * Is zipping possible on this host.
	 *
	 * @return bool
	 */
	public static function available() {
		return class_exists( 'ZipArchive' );
	}

	/**
	 * Largest a single part may grow.
	 *
	 * @return int Bytes.
	 */
	public static function part_max_bytes() {
		return (int) apply_filters( 'uams_mra_archive_part_bytes', 200 * MB_IN_BYTES );
	}

	/**
	 * Work budget for one AJAX step.
	 *
	 * @return array {bytes, files}
	 */
	public static function step_budget() {
		return (array) apply_filters(
			'uams_mra_archive_step_budget',
			array(
				'bytes' => 48 * MB_IN_BYTES,
				'files' => 25,
			)
		);
	}

	/* ---------------------------------------------------------------------
	 * Job state
	 * ------------------------------------------------------------------ */

	/**
	 * Read the current job.
	 *
	 * @return array|null
	 */
	public static function get_job() {
		$job = get_user_meta( get_current_user_id(), self::META_KEY, true );

		return is_array( $job ) && ! empty( $job['id'] ) ? $job : null;
	}

	/**
	 * Store the current job.
	 *
	 * @param array $job Job.
	 */
	protected static function put_job( array $job ) {
		update_user_meta( get_current_user_id(), self::META_KEY, $job );
	}

	/**
	 * Throw away the current job and its files.
	 */
	public static function discard() {
		$job = self::get_job();

		if ( $job && ! empty( $job['dir'] ) ) {
			self::rmdir( $job['dir'] );
		}

		delete_user_meta( get_current_user_id(), self::META_KEY );
	}

	/**
	 * Begin a job for a set of attachments.
	 *
	 * @param array $ids Attachment IDs.
	 * @return array|WP_Error The job.
	 */
	public static function start( array $ids ) {
		if ( ! self::available() ) {
			return new WP_Error(
				'uams_mra_no_zip',
				__( 'This server has no ZipArchive support, so the plugin cannot build an archive here. Delete without one only if you have a backup.', 'uams-mra' )
			);
		}

		self::discard();

		$ids = array_values( array_unique( array_filter( array_map( 'intval', $ids ) ) ) );

		if ( empty( $ids ) ) {
			return new WP_Error( 'uams_mra_empty', __( 'Nothing is selected, so there is nothing to archive.', 'uams-mra' ) );
		}

		$id  = wp_generate_password( 20, false, false );
		$dir = self::base_dir() . '/' . $id;

		if ( ! wp_mkdir_p( $dir ) ) {
			return new WP_Error( 'uams_mra_mkdir', __( 'Could not create the archive folder inside uploads. Check the folder is writable.', 'uams-mra' ) );
		}

		self::protect( self::base_dir() );

		$job = array(
			'id'      => $id,
			'dir'     => $dir,
			'ids'     => $ids,
			'cursor'  => 0,
			'part'    => 1,
			'parts'   => array(),
			'files'   => 0,
			'bytes'   => 0,
			'skipped' => array(),
			'created' => time(),
			'done'    => false,
		);

		self::put_job( $job );

		return $job;
	}

	/**
	 * Add the next batch of files to the archive.
	 *
	 * @return array|WP_Error The job.
	 */
	public static function step() {
		$job = self::get_job();

		if ( ! $job ) {
			return new WP_Error( 'uams_mra_no_job', __( 'No archive is being built.', 'uams-mra' ) );
		}

		if ( ! empty( $job['done'] ) ) {
			return $job;
		}

		$budget    = self::step_budget();
		$part_max  = self::part_max_bytes();
		$spent     = 0;
		$added     = 0;
		$part_full = false;
		$part_path = self::part_path( $job, $job['part'] );

		$zip = new ZipArchive();
		if ( true !== $zip->open( $part_path, ZipArchive::CREATE ) ) {
			return new WP_Error( 'uams_mra_zip_open', __( 'Could not open the archive file for writing.', 'uams-mra' ) );
		}

		$manifest = self::read_manifest( $job, $job['part'] );

		// An open zip does not grow on disk until close(), so the running total
		// is tracked here. Reading filesize() inside the loop would report the
		// size as of the previous step and let the part sail past the cap.
		$part_bytes = self::part_bytes( $job, $job['part'] );

		while ( $job['cursor'] < count( $job['ids'] ) ) {
			if ( $added >= (int) $budget['files'] || $spent >= (int) $budget['bytes'] ) {
				break;
			}

			$id   = (int) $job['ids'][ $job['cursor'] ];
			$path = get_attached_file( $id );

			if ( ! $path || ! file_exists( $path ) ) {
				$job['skipped'][] = $id;
				++$job['cursor'];
				continue;
			}

			$size = (int) filesize( $path );

			// Start a new part rather than push this one past the cap -- unless
			// the part is empty, in which case a single oversized file has to go
			// somewhere and this is the only part that will take it.
			if ( $part_bytes + $size > $part_max && $manifest ) {
				$part_full = true;
				break;
			}

			$local = self::local_name( $id, $path );

			if ( $zip->addFile( $path, $local ) ) {
				// These formats are already compressed; deflating them again buys
				// nothing and costs the time budget.
				if ( method_exists( $zip, 'setCompressionName' ) && self::is_precompressed( $path ) ) {
					$zip->setCompressionName( $local, ZipArchive::CM_STORE );
				}

				$manifest[] = self::manifest_row( $id, $local, $size );

				$spent        += $size;
				$part_bytes   += $size;
				$added        += 1;
				$job['files'] += 1;
				$job['bytes'] += $size;
			} else {
				$job['skipped'][] = $id;
			}

			++$job['cursor'];
		}

		self::write_manifest( $zip, $manifest );
		$zip->close();

		self::record_part( $job, $job['part'], count( $manifest ) );

		$exhausted = $job['cursor'] >= count( $job['ids'] );

		// Roll to a new part only when THIS part refused a file. Testing the
		// part's size instead would stall: a 190 MB part that cannot fit a 20 MB
		// file is still under a 200 MB cap, so every later step would reopen it,
		// refuse the same file, and make no progress.
		if ( ! $exhausted && $part_full ) {
			++$job['part'];
		}

		$job['done'] = $exhausted;

		self::put_job( $job );

		return $job;
	}

	/* ---------------------------------------------------------------------
	 * Parts
	 * ------------------------------------------------------------------ */

	/**
	 * Absolute path of one part.
	 *
	 * @param array $job  Job.
	 * @param int   $part Part number.
	 * @return string
	 */
	protected static function part_path( array $job, $part ) {
		return $job['dir'] . '/' . self::part_name( $part );
	}

	/**
	 * Filename of one part.
	 *
	 * @param int $part Part number.
	 * @return string
	 */
	public static function part_name( $part ) {
		$host = sanitize_file_name( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );

		return sprintf( 'uams-media-%s-part-%d.zip', $host, (int) $part );
	}

	/**
	 * Size of one part on disk.
	 *
	 * @param array $job  Job.
	 * @param int   $part Part number.
	 * @return int Bytes.
	 */
	protected static function part_bytes( array $job, $part ) {
		$path = self::part_path( $job, $part );

		return file_exists( $path ) ? (int) filesize( $path ) : 0;
	}

	/**
	 * Note a part's current size and contents on the job.
	 *
	 * @param array $job   Job, by reference.
	 * @param int   $part  Part number.
	 * @param int   $files File count.
	 */
	protected static function record_part( array &$job, $part, $files ) {
		$job['parts'][ (int) $part ] = array(
			'part'  => (int) $part,
			'name'  => self::part_name( $part ),
			'bytes' => self::part_bytes( $job, $part ),
			'files' => (int) $files,
		);

		ksort( $job['parts'] );
	}

	/**
	 * Path inside the zip, mirroring the uploads layout.
	 *
	 * @param int    $id   Attachment ID.
	 * @param string $path Absolute path.
	 * @return string
	 */
	protected static function local_name( $id, $path ) {
		$uploads = wp_get_upload_dir();
		$base    = trailingslashit( $uploads['basedir'] );

		if ( 0 === strpos( $path, $base ) ) {
			return substr( $path, strlen( $base ) );
		}

		return $id . '-' . basename( $path );
	}

	/**
	 * Is this format already compressed.
	 *
	 * @param string $path File path.
	 * @return bool
	 */
	protected static function is_precompressed( $path ) {
		$ext = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );

		return in_array(
			$ext,
			array( 'jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'mp4', 'mov', 'mp3', 'zip', 'pdf', 'docx', 'pptx', 'xlsx' ),
			true
		);
	}

	/* ---------------------------------------------------------------------
	 * Manifest
	 * ------------------------------------------------------------------ */

	/**
	 * One manifest line.
	 *
	 * @param int    $id    Attachment ID.
	 * @param string $local Path inside the zip.
	 * @param int    $size  Bytes.
	 * @return array
	 */
	protected static function manifest_row( $id, $local, $size ) {
		$post = get_post( $id );
		$mark = UAMS_MRA_Triage::get( $id );

		return array(
			$id,
			$local,
			$size,
			$post ? $post->post_title : '',
			$post ? $post->post_mime_type : '',
			$post ? $post->post_date : '',
			$post ? (int) $post->post_parent : 0,
			(string) get_post_meta( $id, '_wp_attachment_image_alt', true ),
			$mark['d'],
			$mark['n'],
		);
	}

	/**
	 * Read back the manifest already inside a part.
	 *
	 * @param array $job  Job.
	 * @param int   $part Part number.
	 * @return array Rows.
	 */
	protected static function read_manifest( array $job, $part ) {
		$path = self::part_path( $job, $part );

		if ( ! file_exists( $path ) ) {
			return array();
		}

		$zip = new ZipArchive();
		if ( true !== $zip->open( $path ) ) {
			return array();
		}

		$csv = $zip->getFromName( 'manifest.csv' );
		$zip->close();

		if ( ! $csv ) {
			return array();
		}

		$rows  = array();
		$lines = preg_split( '#\r\n|\n#', trim( $csv ) );

		foreach ( (array) $lines as $i => $line ) {
			if ( 0 === $i || '' === trim( (string) $line ) ) {
				continue; // Header.
			}
			$rows[] = str_getcsv( $line, ',', '"', '' );
		}

		return $rows;
	}

	/**
	 * Write the manifest into an open zip.
	 *
	 * @param ZipArchive $zip  Open archive.
	 * @param array      $rows Manifest rows.
	 */
	protected static function write_manifest( $zip, array $rows ) {
		$out = fopen( 'php://temp', 'r+' ); // phpcs:ignore WordPress.WP.AlternativeFunctions

		$header = array( 'attachment_id', 'path_in_zip', 'bytes', 'title', 'mime', 'uploaded', 'parent_id', 'alt_text', 'decision', 'note' );

		// $escape is passed explicitly: PHP 8.4 deprecates leaving it out and
		// PHP 9 changes its default. '' is the RFC 4180 behaviour and is
		// accepted back to PHP 7.0. Left implicit, the deprecation notice
		// prints into the AJAX response and breaks the JSON the progress bar
		// reads -- the archive would look like it failed when it had not.
		fputcsv( $out, $header, ',', '"', '' );

		foreach ( $rows as $row ) {
			fputcsv( $out, $row, ',', '"', '' );
		}

		rewind( $out );
		$csv = stream_get_contents( $out );
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions

		$zip->addFromString( 'manifest.csv', $csv );
	}

	/* ---------------------------------------------------------------------
	 * Storage
	 * ------------------------------------------------------------------ */

	/**
	 * Directory holding every job.
	 *
	 * @return string
	 */
	public static function base_dir() {
		$uploads = wp_get_upload_dir();

		return trailingslashit( $uploads['basedir'] ) . self::DIR;
	}

	/**
	 * Keep the archive folder off the web.
	 *
	 * Archives are served through admin-post.php behind a capability check, so
	 * nothing here should ever answer a direct request. Belt and braces: hosts
	 * that ignore .htaccess still get an index.php, and the job folder name is
	 * unguessable.
	 *
	 * @param string $dir Directory.
	 */
	protected static function protect( $dir ) {
		if ( ! file_exists( $dir . '/.htaccess' ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions
			@file_put_contents( $dir . '/.htaccess', "Order Deny,Allow\nDeny from all\n<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n" );
		}

		if ( ! file_exists( $dir . '/index.php' ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions
			@file_put_contents( $dir . '/index.php', "<?php\n// Silence is golden.\n" );
		}
	}

	/**
	 * Remove a directory and everything in it.
	 *
	 * @param string $dir Directory.
	 */
	protected static function rmdir( $dir ) {
		$base = self::base_dir();

		// Never walk outside the plugin's own folder.
		if ( ! $dir || 0 !== strpos( $dir, $base ) || $dir === $base ) {
			return;
		}

		$items = glob( $dir . '/*' );

		foreach ( (array) $items as $item ) {
			if ( is_dir( $item ) ) {
				self::rmdir( $item );
			} else {
				wp_delete_file( $item );
			}
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions
		@rmdir( $dir );
	}

	/**
	 * Remove every archive, regardless of age.
	 *
	 * Used on deactivation: leaving zips of deleted media in uploads after the
	 * plugin is switched off is exactly the kind of thing nobody finds again.
	 */
	public static function cleanup_all() {
		$base = self::base_dir();

		if ( ! is_dir( $base ) ) {
			return;
		}

		foreach ( (array) glob( $base . '/*', GLOB_ONLYDIR ) as $dir ) {
			self::rmdir( $dir );
		}
	}

	/**
	 * Delete job folders older than the archive lifetime.
	 *
	 * Runs on a daily event. Archives are a safety net for a decision made in
	 * one sitting, not long-term storage, and a library's worth of zips sitting
	 * in uploads is its own disk problem.
	 */
	public static function cleanup() {
		$base = self::base_dir();

		if ( ! is_dir( $base ) ) {
			return;
		}

		$cutoff = time() - self::TTL;

		foreach ( (array) glob( $base . '/*', GLOB_ONLYDIR ) as $dir ) {
			if ( filemtime( $dir ) < $cutoff ) {
				self::rmdir( $dir );
			}
		}
	}
}
