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

URL matching normalizes away every generated size, so `hero-1024x768.jpg`, `hero-scaled.jpg`, and `hero-e1712345678901.jpg` all resolve back to attachment `hero.jpg`. Filenames containing their own dots (`State-Plan-2.11.25-Final.pdf`) match whole.

A size suffix may carry a short crop marker after it (`hero-768x432-cc.jpg`),
which is how themes that build their own derivatives name them. Those collapse
too. The marker is letters only and at most four, so a real filename ending in
`-1920x1080-v2` keeps its name. Extend the list with the
`uams_mra_derivative_patterns` filter if a theme uses a shape this misses; the
symptom is a file you can see on the page reporting as unreferenced.

## Reading the page itself

Everything above reads the database. Some of the most common ways a file reaches
a visitor never touch it:

- a page template that lists its own attachments (`get_children()`,
  `get_attached_media()`, a "documents on this page" loop)
- a shortcode or a block that builds its markup at render time
- a URL hardcoded in a theme file or a stylesheet

A file used that way has no row anywhere naming it, so every database pass will
report it as unreferenced no matter how thorough the pass is. This is the reason
the **Verify** tier exists, and on its own the tier is a dead end: it hands you a
list of pages to open by hand.

The render step closes it. After `finalize`, the scan takes every file that
finished with **no reference and a published parent**, fetches that parent's
permalink over HTTP, and looks for the file in the HTML the page actually
returns. That is usually a handful of pages rather than the whole site.

Each file comes back in one of five states, shown as a badge on the results
screen and exported as `page_check`:

| State | Meaning | Effect |
|---|---|---|
| `found` | The page renders this file from this site's uploads | Recorded as a strong reference. The file is in use |
| `offsite` | The page renders a file of the same name from **another host** | Stays in Verify, and the report names the host |
| `absent` | The page was fetched and renders no file by this name | Leaves the Verify tier for `ask` or `clear`, by file type |
| `error` | The request failed: timeout, redirect, non-200 | Stays in Verify. Nothing was learned |
| `skipped` | No public page to fetch, or past the per-scan page ceiling | Stays in Verify. Nothing was learned |

Precedence runs `found` → `offsite` → `absent`, so a file served from both this
site and somewhere else counts as in use.

### Why `offsite` exists

The database passes match this site's own upload prefix, which is the right rule
for stored content: a URL pointing at another site's uploads is a reference to
*that* site's file. A rendered page is a different question. A site part-way
through a migration keeps serving `Foo.pdf` from its old host while a copy of
`Foo.pdf` sits in the new library, referenced by nothing. Calling that copy
unused is how a half-finished migration turns into deleted originals.

So the render step reads uploads URLs on **any** host, and reports a
same-filename match elsewhere as its own state rather than counting it as a
reference. Nothing is claimed to be in use here that isn't; the file simply keeps
its place in Verify with the host recorded, for a person to decide whether the
two files are the same document.

The three states that are *not* evidence are kept distinct from the one that is,
on screen and in the export. A failed request must never read as "unused", or a
site being briefly unreachable would mark live files deletable.

Two details worth knowing:

- Each page is parsed whole, so a file that belongs to a *different* parent but
  is displayed on the page being read gets cleared too, at no extra cost.
- Requests carry a per-scan cache-busting token. A stale cached page that still
  shows a since-removed file only keeps a file alive, but a stale page missing a
  just-added one would mark a live file absent. Turn it off with the
  `uams_mra_render_cache_bust` filter if a WAF objects.

`absent` means *that page, at that moment*. A page whose output varies by date
or by visitor (an events list that hides past entries, anything behind a login),
can render a file today and not tomorrow. The badge links to the URL that was
read and carries the timestamp, so the check is yours to repeat.

Filters: `uams_mra_render_request_args` (whole `wp_remote_get` argument array),
`uams_mra_render_sslverify`, `uams_mra_render_cache_bust`. Switch the step off
entirely under **Scan settings > Read the page itself**, where the per-batch and
per-scan page limits also live.

## Offsite media

**Media > Offsite Media** answers the other half of the question. The audit finds
files this site hosts that nothing points at; this finds media the site
*displays but does not host* -- uploads URLs stored in content, custom fields, or
options that point at another site.

It fills in during the normal scan, reading the same content the reference
passes already read, so it costs nothing extra. Switch it off under
**Scan settings > Offsite media**.

Each row is one distinct offsite file:

| Column | Meaning |
|---|---|
| File | Filename, linked to the URL that is stored |
| Host | Where it is served from |
| Local copy by name | Whether this library already holds a file of that name. Same name is not proof of same document |
| Stored in | Every post, meta key, or option holding that URL |

This is the report a half-finished migration shows up in. A site moved from one
host to another keeps serving files from the host it moved off, and the copies
sitting in the new library are referenced by nothing -- so the audit reports them
as unused while the pages quietly depend on the old host staying up.

Two limits worth knowing:

- **A URL here is in the database, so it could be rewritten.** A URL a theme
  prints at render time never appears in this report; the page check on the
  audit screen is the only thing that can see those.
- **The report changes nothing.** Nothing is fetched, rewritten, or deleted.

### Repointing

Where a file of the same name is already in this library, the stored URL can be
rewritten to point at it. Nothing is downloaded and no attachment is created,
so this half of a migration adds no duplicates. It runs in two passes from the
Offsite Media screen.

**Compare** reads only. Each offsite file is fetched with a HEAD request and its
size checked against the local copy, giving one of:

| Verdict | Meaning | Eligible |
|---|---|---|
| `identical` | Same file name and the same size; the contents are not compared | Yes |
| `resized` | The library has this image, but not at the same byte size | Opt-in |
| `differs` | Same filename, different file | No |
| `ambiguous` | More than one local attachment shares the filename | No |
| `nolocal` | Nothing local has this filename -- it needs pulling over | No |
| `unreachable` | The other host did not return the file | No |

**Repoint** rewrites the rows the comparison cleared. Each value is re-read
first, so a page edited since the scan is left alone rather than written from a
stale plan.

A stored value is read without building any class it names (only `stdClass` is
built). A value whose URL sits where the rewrite cannot reach, such as an object
of a class that is not loaded or a private property, is reported as **failed** and
left as it was, never as skipped and never half rewritten.

What it writes, and what that costs:

- **Posts and pages** go through `wp_update_post()`, so the previous content is
  kept as a revision and the change can be undone from the editor.
- **Custom fields and options** are written directly. There is no revision.
  Take a database backup first.
- **Revisions are never rewritten.** They are the history a page gets restored
  *from*, which is not this tool's business to edit. They are also left out of
  the offsite report itself: every repoint saves a revision holding the URLs it
  has just removed, so counting them would make the report grow each time it was
  acted on and never reach zero.

Serialized values are unpicked before replacing and re-serialized afterwards.
Replacing inside the serialized string would leave the byte-length prefixes
describing the old URL, which is how a careless search and replace corrupts a
database. Every stored form of the URL is matched -- either scheme, no scheme,
and the slash-escaped form block attributes use -- and all of them are rewritten
to the URL WordPress gives for the local attachment, so an old `http://` link
does not stay `http://` on a site served over https.

### Pulling files over

For everything the comparison marked `nolocal`, **Pull missing files into this
library** downloads the file and adds it as an attachment. It rewrites nothing;
you repoint afterwards, through the same path as everything else.

Three things it does that a naive importer would get wrong:

- **It fetches the original, not the thumbnail.** A page asking for
  `hero-768x432-cc.jpg` gets `hero.jpg` downloaded and lets WordPress regenerate
  the sizes. Importing the derivative would put a 768px image in the library as
  though it were the upload.
- **It groups by original.** One photo shown at three sizes is three stored URLs
  and one download, not three attachments of the same picture.
- **It repoints each URL at the right size.** After the original lands, a URL
  that asked for a thumbnail is pointed at the regenerated size matching those
  dimensions, so pages keep their layout.

Files go through `media_handle_sideload()`, the same upload checks as the media
screen, so a file type this site does not permit is refused rather than written
anywhere. A new attachment is hung off the first published post whose *content*
shows it -- what the library would have recorded had someone uploaded it from
that page -- and left unattached when only a custom field or option refers to
it, since inventing a parent would be a claim nothing supports.

Resizing an image decompresses the whole thing into memory, which bears no
relation to its file size -- a 2 MB JPEG can be 90 MB of pixels -- and PHP
running out part-way through is a fatal error no `try`/`catch` can see. So the
cost is worked out from the image's dimensions before any editor is opened, and
weighed against the memory actually still free. When it will not fit, the file is
still brought in and used at full size; only the generated sizes are skipped.
Those can be rebuilt later with a regeneration plugin or a raised memory limit,
neither of which needs the old host to still exist.

Size ceiling and batch size live under **Scan settings > Read the page itself**.
The limit is checked on the HEAD response and again on what actually arrived, so
a host that answers without a `content-length` cannot slip past it.

If an "offsite" host turns out to be serving *this site's own files* under an old
path -- common after a site is renamed, where `example.com/old-slug/wp-content/uploads/sites/46/`
and `example.com/new-slug/wp-content/uploads/sites/46/` are the same files -- add
the old prefix under **Scan settings > Extra upload paths**. Those URLs then count
as real references instead, which is what they are.

## Triage

A scan tells you what nothing points at. It cannot tell you what is safe to
delete. The **Triage** panel closes that gap: every file gets a *tier* from the
scan and a *decision* from you.

| Tier | What lands there | Why |
|---|---|---|
| Verify | Attached to a post, and its page has not been read | The parent page may display it in a way a database scan cannot see |
| Ask team | Documents, video, audio that are unattached or `absent` | Handed out by email, print, and QR code, where no reference ever reaches the database |
| Low risk | Images that are unattached or `absent` | Nothing embeds it, and where it had a page, that page was opened and does not show it |

A file only leaves **Verify** on evidence: either it never had a parent, or its
parent page was fetched and does not render it. A page that could not be read
leaves the file exactly where it was.

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

The CSV export carries `page_check`, `page_check_note`, `page_checked_url`,
`page_checked_at`, `tier`, `decision`, and `note` for every row. That is
also the backup: uninstalling the plugin deletes the marks, since orphaned post
meta would be unreachable on a host with no database access.

## Filters on the results screen

The screen opens on the first filter that has something in it -- orphaned, then
unreferenced, then unattached, then referenced-by-ID-only -- and falls back to
**All media** when none of them do. The tier counts are scoped to the filter, so
a fixed landing view that happened to be empty would show a blank table under
three zeroed cards and read as a broken scan. A `view` in the URL always wins.

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

1. **Hardcoded URLs on pages nothing is attached to.** The database passes read tables, not PHP or template files. The render step covers this wherever the file is attached to a published page, since it reads the finished HTML, but a file hardcoded in `header.php` and attached to nothing has no page to fetch and still shows as unreferenced. Grep your theme before a bulk delete.
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

Requires WordPress 6.0 and PHP 8.2.

## Development

The plugin has no runtime dependencies. `composer.json` holds development tools
only: Rector, PHPStan with the WordPress extension and stubs, and Pint, set up
from the shared `code-quality` skill in UAMS-Web/uams-claude-skills. They run on
your PHP (Pint needs 8.3 or later); `rector.php` and `phpstan.neon.dist` are
pinned to the plugin's floor, PHP 8.2, so neither suggests syntax 8.2 cannot
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

Four tables are created per site: `{prefix}uams_mra_index`, `{prefix}uams_mra_refs`, `{prefix}uams_mra_results`, and `{prefix}uams_mra_offsite`. All four are truncated at the start of each scan.

## Uninstall

Deleting the plugin from **Plugins** runs `uninstall.php`, which drops all four
tables, removes the plugin's options, and deletes the triage meta from every
attachment. On multisite it walks every site, since the tables are per site.

The tables and options rebuild themselves on the next scan. **The triage marks do
not** — they are human judgement, and this deletes them. Export the CSV first if
they matter. The alternative, leaving the meta behind, would strand rows nobody
on a shell-less host could ever clean up.

Your media files are not touched.

## Credits

Developed and maintained by UAMS Web Services.

Developers:

- Josh Daugherty
- Brent Passmore

## Tests

```bash
composer install
composer test:unit
```

The Unit suite runs on Pest 5 with no WordPress and no database: WordPress functions are mocked with Brain Monkey. The Integration suite runs on PHPUnit 9.6 from its own Composer setup, because WordPress' test suite supports PHPUnit 9 only:

```bash
composer setup:integration
composer test:integration
```

It needs a WordPress core tree and a test database that nothing else uses; `tests/Integration/wp-tests-config.php` lists the `WP_TESTS_*` environment variables. `composer test` runs both suites, each in its own process.
