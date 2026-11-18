# Triage

Written against plugin version 1.11.0.

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

Each row then takes a decision (**Keep**, **Ask**, or **Delete**) plus an
optional note. These save as you click, and live in post meta on the attachment
rather than in the results tables, which are truncated at the start of every
scan. That means a decision survives rescanning, is shared with everyone who can
reach the screen, and disappears by itself when the file is deleted.

Filter by tier and by decision (including **Undecided**) to work the list down.
When you are ready, **Delete the N files marked Delete** acts on the marks
rather than the checkboxes, so the triage is the work queue, not a document you
transcribe from. The reference guard still applies: a file with recorded
references is skipped unless you tick the override box.

The CSV export carries `page_check`, `page_check_note`, `page_checked_url`,
`page_checked_at`, `tier`, `decision`, and `note` for every row. That is
also the backup: uninstalling the plugin deletes the marks, since orphaned post
meta would be unreachable on a host with no database access.

