# Repointing

Written against plugin version 1.11.0.

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

## Pulling files over

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

