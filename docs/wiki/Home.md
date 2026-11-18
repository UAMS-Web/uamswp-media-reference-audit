# UAMSWP Media Reference Audit wiki

This wiki holds the long-form documentation for the UAMSWP Media Reference Audit plugin: how it decides a file is in use, how to work through the results, and the reference for its commands and filters. The [README](https://github.com/UAMS-Web/uamswp-media-reference-audit#readme) keeps the overview, installation, development notes and the warnings to read before deleting.

Written against plugin version 1.11.0.

## Getting started

- [Your first scan](Your-first-scan.md): run a scan and read the first results.

## Guides

- [Reading the page itself](Reading-the-page-itself.md): the render check that catches files no database row names.
- [Offsite media](Offsite-media.md): media the site displays but does not host.
- [Repointing and pulling files over](Repointing-and-pulling-files-over.md): rewrite offsite URLs and bring missing files in.
- [Triage](Triage.md): tiers, decisions and the work queue.
- [Archive and delete](Archive-and-delete.md): build a downloadable archive, then delete.

## Reference

- [How references are detected](How-references-are-detected.md): strong and weak references, size suffixes and skipped keys.
- [Results screen filters](Results-screen-filters.md): what each filter selects.
- [WP-CLI commands](WP-CLI-commands.md): `scan`, `list`, `why` and `delete`.
- [Filters](Filters.md): every `uams_mra_` filter with its default and effect.

## Troubleshooting

- [Troubleshooting](Troubleshooting.md): symptoms, causes and fixes.
