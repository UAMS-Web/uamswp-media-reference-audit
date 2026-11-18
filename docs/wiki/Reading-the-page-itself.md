# Reading the page itself

Written against plugin version 1.11.0.

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

## Why `offsite` exists

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

