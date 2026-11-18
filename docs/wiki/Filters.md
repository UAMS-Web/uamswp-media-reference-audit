# Filters

Written against plugin version 1.11.0.

Every filter the plugin applies, with what it changes. Add one from a theme or a small plugin with `add_filter()`. Defaults are read from the code at the version above.

| Filter | Effect | Page |
|---|---|---|
| `uams_mra_foreign_id_key_pattern` | Regular expression of meta keys that name another object's ID and so are refused the weak match. Return `''` to disable the check | [How references are detected](How-references-are-detected.md) |
| `uams_mra_numeric_match_sources` | Which sources allow weak (bare ID) matching. User meta is off by default | [How references are detected](How-references-are-detected.md) |
| `uams_mra_skipped_meta_keys` | List of meta keys that describe the attachment itself and never count as a reference, such as `_wp_attached_file` and `_wp_attachment_metadata` | [How references are detected](How-references-are-detected.md) |
| `uams_mra_derivative_patterns` | Array of regular expressions applied to a filename (extension removed) to collapse generated sizes back to the original. Defaults cover `-WxH` with an optional short letters-only marker, and `-scaled`, among others | [How references are detected](How-references-are-detected.md) |
| `uams_mra_render_request_args` | The whole `wp_remote_get()` argument array for the page check | [Reading the page itself](Reading-the-page-itself.md) |
| `uams_mra_render_sslverify` | Whether the page check verifies TLS certificates | [Reading the page itself](Reading-the-page-itself.md) |
| `uams_mra_render_cache_bust` | Whether page-check requests carry a per-scan cache-busting token | [Reading the page itself](Reading-the-page-itself.md) |
| `uams_mra_repoint_request_args` | Arguments for the HEAD request that reads an offsite file's size during Compare. Default: `timeout` 15, `redirection` 3, `sslverify`, `user-agent` | [Repointing and pulling files over](Repointing-and-pulling-files-over.md) |
| `uams_mra_repoint_time_budget` | Seconds one repoint batch may run. Default: 60 percent of `max_execution_time`, at least 5 (30 is assumed when the limit is unlimited) | [Repointing and pulling files over](Repointing-and-pulling-files-over.md) |
| `uams_mra_repoint_stall_timeout` | Seconds a repoint job may sit untouched before it stops counting as running (a closed tab or a dead request). Default: 300 | [Repointing and pulling files over](Repointing-and-pulling-files-over.md) |
| `uams_mra_distributable_mimes` | Which file types count as distributable for the Ask team tier | [Triage](Triage.md) |
| `uams_mra_archive_part_bytes` | Bytes in each archive part. Default: 200 MB | [Archive and delete](Archive-and-delete.md) |
| `uams_mra_archive_step_budget` | Array with `bytes` and `files`: the work one archive batch may do. Default: 48 MB and 25 files | [Archive and delete](Archive-and-delete.md) |
