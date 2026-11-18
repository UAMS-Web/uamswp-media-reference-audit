# Offsite media

Written against plugin version 1.11.0.

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

