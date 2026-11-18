# Filters on the results screen

Written against plugin version 1.11.0.

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

