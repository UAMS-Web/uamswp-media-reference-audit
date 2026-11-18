# Archive and delete

Written against plugin version 1.11.0.

Deletion is permanent and takes every generated size with it, so the archive is
what makes the decision reversible. Build the zip, download it, then delete.

Gather a set two ways, both under **Archive and delete** on the results screen:

- **Everything matching this filter** : every file the current view, tier,
  decision, and search select, not just the page on screen.
- **A list from somewhere else** : paste attachment IDs, filenames, or URLs, one
  per line or comma separated. Useful when the decision came out of a content
  audit spreadsheet rather than out of this plugin. Entries that match nothing
  are reported back rather than quietly dropped.

Either way you land on a review screen: one list, with each file's tier,
decision, note, reference count, and size, plus totals for how many are still
referenced or still attached. Nothing happens until you act on it.

**Build archive** zips the originals in batches over AJAX (a 165 MB selection
will not zip inside one request) and splits the result into parts of about
200 MB, so a failed download costs one part rather than the whole set. Each part
carries a `manifest.csv` listing every file's ID, path in the zip, title, mime
type, upload date, parent, alt text, decision, and note: enough to put a file
back deliberately. Generated image sizes are left out because WordPress rebuilds
those, and on a library of PDFs and video they would be dead weight.

Archives live in `wp-content/uploads/uams-mra-archives/` behind an unguessable
folder name, an `.htaccess` deny, and a capability check on the download itself,
they are never served directly. They are deleted automatically after a day, when
the plugin is deactivated, or when you click **Discard this archive**. Adjust the
sizes with `uams_mra_archive_part_bytes` and `uams_mra_archive_step_budget`.

Hosts without the PHP `zip` extension get a clear warning on the review screen
instead of a broken button; deletion still works, but take your own backup.

