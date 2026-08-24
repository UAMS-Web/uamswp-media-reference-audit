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

URL matching normalizes away every generated size, so `hero-1024x768.jpg`, `hero-scaled.jpg`, and `hero-e1712345678901.jpg` all resolve back to attachment `hero.jpg`.

## Filters on the results screen

| Filter | Meaning |
|---|---|
| Unattached and unreferenced | The safest deletion candidates. Start here |
| Not referenced anywhere | Includes files still attached to a post but never displayed |
| Not attached to a post | `post_parent = 0`. Common for anything uploaded through the Media Library directly, so a file here is not necessarily unused |
| Referenced by ID only | Weak matches worth spot-checking |
| In use | Everything with at least one reference |

Each row shows where the reference was found, with a link to the source, so you can verify before deleting.

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
  -x '*.git*' -x '*.DS_Store'
```

On multisite, activate per site rather than network-wide unless every site needs
it. Tables are created per site, either on activation or on the first admin
request after that.

Then go to **Media > Media Audit** and run a scan.

Requires WordPress 6.0 and PHP 7.4.

## Performance notes

The scan runs in batches over AJAX and can be closed and restarted. On a large library or a slow host, lower the batch sizes under Scan settings. The postmeta pass is usually the slowest step because it reads every row in `wp_postmeta`.

Three tables are created per site: `{prefix}uams_mra_index`, `{prefix}uams_mra_refs`, and `{prefix}uams_mra_results`. All three are truncated at the start of each scan.

## Uninstall

Deleting the plugin from **Plugins** runs `uninstall.php`, which drops all three
tables and removes the plugin's options. On multisite it walks every site, since
the tables are per site. Nothing worth keeping is lost — every row is derived
from a scan and is rebuilt the next time one runs. Your media files are not
touched.
