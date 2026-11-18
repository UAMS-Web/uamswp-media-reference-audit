# Troubleshooting

Written against plugin version 1.11.0.

- [A file I can see on a page reports as unreferenced](#a-file-i-can-see-on-a-page-reports-as-unreferenced)
- [A file shows as in use and I expected it to be unused](#a-file-shows-as-in-use-and-i-expected-it-to-be-unused)
- [The page check says a file is absent but it is on the page](#the-page-check-says-a-file-is-absent-but-it-is-on-the-page)
- [The Build archive button is replaced by a warning](#the-build-archive-button-is-replaced-by-a-warning)
- [Delete skipped a file](#delete-skipped-a-file)

## A file I can see on a page reports as unreferenced

**Cause:** the page prints the URL at render time (a template, a shortcode or a stylesheet), or a theme names derivatives in a shape the matcher does not collapse (`hero-768x432-cc.jpg`).

**Fix:** run the page check ([Reading the page itself](Reading-the-page-itself.md)). For an unusual derivative name, extend `uams_mra_derivative_patterns` ([Filters](Filters.md)). For offloaded media served from another path, add that path under **Scan settings > Extra upload paths**.

## A file shows as in use and I expected it to be unused

**Cause:** a weak match, or two attachments sharing a filename. Matching by name is deliberately conservative, so both count as referenced when either is found.

**Fix:** open the row's reference list and follow the link to the source. Weak matches are listed under the **Referenced by ID only** filter ([How references are detected](How-references-are-detected.md)).

## The page check says a file is absent but it is on the page

**Cause:** `absent` means that page at that moment. A page whose output varies by date or visitor can render a file today and not tomorrow.

**Fix:** the badge links to the URL that was read and carries the timestamp, so repeat the check ([Reading the page itself](Reading-the-page-itself.md)).

## The Build archive button is replaced by a warning

**Cause:** the host's PHP has no `zip` extension.

**Fix:** enable the extension, or take your own backup. Deletion still works without it ([Archive and delete](Archive-and-delete.md)).

## Delete skipped a file

**Cause:** the file has a recorded reference, and the reference guard skips it unless the override box is ticked.

**Fix:** open the row, confirm the reference, and tick the override only when it is wrong ([Triage](Triage.md)).
