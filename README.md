# UAMS Media Reference Audit

Finds media that is not attached to any post, and media that nothing in your site actually references. Reports first. Deletes only when you tell it to.

Adds **Media > Media Audit** to the admin menu.

## What it counts as a reference

**Strong references** (the file is definitely in use):

| Source | What it catches |
|---|---|
| `post_content` | Upload URLs, `wp-image-123` classes, `[caption id="attachment_123"]`, `[gallery ids="1,2"]`, block JSON (`<!-- wp:image {"id":123} -->`), `?attachment_id=123` |
| `_thumbnail_id` | Featured images |
| Post meta | Any upload URL, plus JSON keys `id`, `ids`, `image_id`, `attachment_id`, `thumbnail_id`, `media_id`. Covers Elementor, Beaver Builder, Divi, and most block-based builders |
| Options | Theme mods, custom logo, site icon, media widgets, plugin settings |
| Term, user, and comment meta | Taxonomy images, profile images, attachment fields |
| Nav menus | Menu items pointing at an attachment |
| Post revisions | Optional. On by default so restoring a revision does not break |
| Other network sites | Optional. Catches an image this site hosts that a sibling site displays |

**Weak references** (`Referenced by ID only`): a custom field storing a bare attachment ID with no surrounding context. This is the standard ACF image and gallery field pattern, so it is on by default. These files show as in use but are listed separately under the **Referenced by ID only** filter so you can spot-check them.

A bare number is weak evidence, and numbers collide. A field named
`source_user_id` holding `1215` means user 1215 on some other system, not
attachment 1215 here. So keys whose name states they hold another object's ID —
matching `(^|_)(user|author|customer|post|page|parent|term|category|tag|comment|order|site|blog|form|entry)_id$`
— are refused the weak match. Adjust the list through the
`uams_mra_foreign_id_key_pattern` filter; return `''` to disable the check.

This withholds only the *weak* match. If one of those fields really does hold an
upload URL or a JSON `"id"` key, that is strong evidence and still counts. Keys
that could legitimately hold a media ID — `image_id`, `media_id`,
`attachment_id`, `thumbnail_id` — are deliberately absent from the pattern, and
anything you add to it risks a live file reporting as unreferenced.

Weak matching is also off for **user meta** entirely. WordPress and its plugins
park screen preferences, counters, and progress blobs there — `gform_recent_forms`,
`_sfwd-quizzes`, `users_network_per_page` — and a bare number in one of those
means nothing to the media library. Measured against a real UAMS network: of 69
weak user-meta matches, none was a genuine reference, while every genuine one
came from post meta or an option. Strong evidence in user meta — an actual
upload URL, a JSON `"id"` key — still counts. If a site stores a user's photo as
a bare attachment ID in an ACF user field, switch it back on:

```php
add_filter( 'uams_mra_numeric_match_sources', function ( $sources ) {
	$sources['usermeta'] = true;
	return $sources;
} );
```

Meta keys that describe the attachment itself (`_wp_attached_file` and friends)
never count as a reference, in any of the four meta passes. Extend that list with
the `uams_mra_skipped_meta_keys` filter.

URL matching normalizes away every generated size, so `hero-1024x768.jpg`, `hero-scaled.jpg`, and `hero-e1712345678901.jpg` all resolve back to attachment `hero.jpg`.

## Triage

A scan tells you what nothing points at. It cannot tell you what is safe to
delete. The **Triage** panel closes that gap: every file gets a *tier* from the
scan and a *decision* from you.

| Tier | What lands there | Why |
|---|---|---|
| Verify | Attached to a post | The parent page may display it in a way a database scan cannot see |
| Ask team | Unattached documents, video, audio | Handed out by email, print, and QR code, where no reference ever reaches the database |
| Low risk | Unattached images | An image nothing embeds is genuinely unused — the case the scan is reliable for |

Tiers are derived on every render and stored nowhere, so they stay correct after
a rescan. The tier counts cover whichever filter is selected, not the whole
library, so they always describe the list underneath them. Adjust which file types count as distributable with the
`uams_mra_distributable_mimes` filter.

Each row then takes a decision — **Keep**, **Ask**, or **Delete** — plus an
optional note. These save as you click, and live in post meta on the attachment
rather than in the results tables, which are truncated at the start of every
scan. That means a decision survives rescanning, is shared with everyone who can
reach the screen, and disappears by itself when the file is deleted.

Filter by tier and by decision (including **Undecided**) to work the list down.
When you are ready, **Delete the N files marked Delete** acts on the marks
rather than the checkboxes — so the triage is the work queue, not a document you
transcribe from. The reference guard still applies: a file with recorded
references is skipped unless you tick the override box.

The CSV export carries `tier`, `decision`, and `note` for every row. That is
also the backup: uninstalling the plugin deletes the marks, since orphaned post
meta would be unreachable on a host with no database access.

## Filters on the results screen

| Filter | Meaning |
|---|---|
| Unattached and unreferenced | The safest deletion candidates. Start here |
| Not referenced anywhere | Includes files still attached to a post but never displayed |
| Not attached to a post | `post_parent = 0`. Common for anything uploaded through the Media Library directly, so a file here is not necessarily unused |
| Referenced by ID only | Weak matches worth spot-checking |
| In use | Everything with at least one reference |

Each row shows where the reference was found, with a link to the source, so you can verify before deleting.

## Archive and delete

Deletion is permanent and takes every generated size with it, so the archive is
what makes the decision reversible. Build the zip, download it, then delete.

Gather a set two ways, both under **Archive and delete** on the results screen:

- **Everything matching this filter** — every file the current view, tier,
  decision, and search select, not just the page on screen.
- **A list from somewhere else** — paste attachment IDs, filenames, or URLs, one
  per line or comma separated. Useful when the decision came out of a content
  audit spreadsheet rather than out of this plugin. Entries that match nothing
  are reported back rather than quietly dropped.

Either way you land on a review screen: one list, with each file's tier,
decision, note, reference count, and size, plus totals for how many are still
referenced or still attached. Nothing happens until you act on it.

**Build archive** zips the originals in batches over AJAX — a 165 MB selection
will not zip inside one request — and splits the result into parts of about
200 MB, so a failed download costs one part rather than the whole set. Each part
carries a `manifest.csv` listing every file's ID, path in the zip, title, mime
type, upload date, parent, alt text, decision, and note: enough to put a file
back deliberately. Generated image sizes are left out because WordPress rebuilds
those, and on a library of PDFs and video they would be dead weight.

Archives live in `wp-content/uploads/uams-mra-archives/` behind an unguessable
folder name, an `.htaccess` deny, and a capability check on the download itself —
they are never served directly. They are deleted automatically after a day, when
the plugin is deactivated, or when you click **Discard this archive**. Adjust the
sizes with `uams_mra_archive_part_bytes` and `uams_mra_archive_step_budget`.

Hosts without the PHP `zip` extension get a clear warning on the review screen
instead of a broken button; deletion still works, but take your own backup.

## What it cannot see

Read this before you delete anything.

1. **Hardcoded URLs in theme or plugin files.** The scan reads the database, not PHP or template files. An image referenced in `header.php` or a plugin's CSS will show as unreferenced. Grep your theme before a bulk delete.
2. **Media rendered from an external system.** Content pulled in through an API, a feed, a headless front end, or an iframe from another application.
3. **Files someone linked to from outside your site.** A PDF linked from an email campaign, a print piece, or a partner site has no in-database reference.
4. **Object-cached or offloaded media.** If a plugin rewrites upload URLs to a path this plugin does not know, add that path under Scan settings > Extra upload paths.
5. **Anything added after the last scan.** Results are a snapshot. Rescan before deleting.

Basename matching is deliberately conservative. If two attachments share a filename, both are marked referenced when either is found. Overcounting usage is safer than deleting a live file.

## Deleting

Deletion is permanent and removes every generated size. The plugin refuses to delete a file with any recorded reference unless you tick the override box.

**Take a database and uploads backup first.** There is no undo.

## WP-CLI

The UAMS WordPress hosts have no shell, so these are for local and sandbox work
only. Everything below is also available on the **Media > Media Audit** screen.

```bash
# Scan one site
wp uams-media-audit scan

# Scan every site in a network
wp site list --field=url | xargs -n1 -I% wp uams-media-audit scan --url=%

# Review before acting
wp uams-media-audit list --status=orphaned --limit=200
wp uams-media-audit list --status=unreferenced --format=csv > unreferenced.csv

# See why a specific file is considered in use
wp uams-media-audit why 4821

# Delete, dry run first
wp uams-media-audit delete --status=orphaned --dry-run
wp uams-media-audit delete --status=orphaned --limit=100
```

## Install

Drop the `uamswp-media-reference-audit` directory into `wp-content/plugins/` and
activate it. No build step, no Composer dependencies, no options to configure
before first use.

```bash
cd wp-content/plugins
git clone git@github.com:UAMS-Web/uamswp-media-reference-audit.git
```

To deploy to a UAMS host, zip the plugin directory and upload it through
**Plugins > Add New > Upload Plugin**:

```bash
cd ..
zip -r uamswp-media-reference-audit.zip uamswp-media-reference-audit \
  -x '*.git*' -x '*.DS_Store' \
  -x 'uamswp-media-reference-audit/vendor/*' -x 'uamswp-media-reference-audit/tests/*' \
  -x 'uamswp-media-reference-audit/.phpstan/*' -x 'uamswp-media-reference-audit/composer.*' \
  -x 'uamswp-media-reference-audit/rector*.php' -x 'uamswp-media-reference-audit/phpstan.neon.dist' \
  -x 'uamswp-media-reference-audit/pint.json'
```

The extra exclusions keep the development tooling below out of the upload.

On multisite, activate per site rather than network-wide unless every site needs
it. Tables are created per site, either on activation or on the first admin
request after that.

Then go to **Media > Media Audit** and run a scan.

Requires WordPress 6.0 and PHP 7.4.

## Development

The plugin has no runtime dependencies. `composer.json` holds development tools
only: Rector, PHPStan with the WordPress extension and stubs, and Pint, set up
from the shared `code-quality` skill in UAMS-Web/uams-claude-skills. They run on
your PHP (Pint needs 8.3 or later); `rector.php` and `phpstan.neon.dist` are
pinned to the plugin's floor, PHP 7.4, so neither suggests syntax 7.4 cannot
parse.

```bash
composer install

composer test:pint       # formatting check; `composer pint` fixes
composer test:refactor   # Rector's gating profile, dry run; `composer refactor` applies
composer stan            # PHPStan, at the level in phpstan.neon.dist
```

`composer refactor:sweep` runs Rector's sweep profile, which adds the rules held
back from the gate; review its diff rather than applying it wholesale.

The commit that first formatted the code with Pint is listed in
`.git-blame-ignore-revs`. Tell `git blame` to skip it, once per clone:

```bash
git config blame.ignoreRevsFile .git-blame-ignore-revs
```

## Performance notes

The scan runs in batches over AJAX and can be closed and restarted. On a large library or a slow host, lower the batch sizes under Scan settings. The postmeta pass is usually the slowest step because it reads every row in `wp_postmeta`.

Three tables are created per site: `{prefix}uams_mra_index`, `{prefix}uams_mra_refs`, and `{prefix}uams_mra_results`. All three are truncated at the start of each scan.

## Uninstall

Deleting the plugin from **Plugins** runs `uninstall.php`, which drops all three
tables, removes the plugin's options, and deletes the triage meta from every
attachment. On multisite it walks every site, since the tables are per site.

The tables and options rebuild themselves on the next scan. **The triage marks do
not** — they are human judgement, and this deletes them. Export the CSV first if
they matter. The alternative, leaving the meta behind, would strand rows nobody
on a shell-less host could ever clean up.

Your media files are not touched.


## Tests

```bash
composer install
composer test:unit
```

The supported PHP floor is 7.4, so this repository uses PHPUnit 9 from the shared `tests` skill templates (Pest requires PHP 8.2+).
