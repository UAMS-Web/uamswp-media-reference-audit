# Your first scan

Written against plugin version 1.11.0.

By the end of this page you will have run one scan and know which filter to open first. Installation is in the [README](https://github.com/UAMS-Web/uamswp-media-reference-audit#install) (Installation, README).

1. Activate the plugin, then open **Media > Media Audit**. Each site on a network is scanned on its own, so open the screen on the site you want.
2. Choose **Scan**. The scan runs in batches and can be closed and restarted. On a large library or a slow host, lower the batch sizes under **Scan settings** first.
3. Wait for the progress bar to finish. The page check at the end fetches the published parent of each file that still has no reference, so it can take longer than the database passes ([Reading the page itself](Reading-the-page-itself.md)).
4. Read the first filter the screen opens on. It opens on the first filter that has something in it, in this order: orphaned, unreferenced, unattached, referenced by ID only ([Results screen filters](Results-screen-filters.md)).
5. Open a row's reference list before acting on it. Each reference links to its source.

**Warning:** nothing is deleted by a scan. Deleting is permanent, so read [What it cannot see](https://github.com/UAMS-Web/uamswp-media-reference-audit#what-it-cannot-see) (README) and build an archive first ([Archive and delete](Archive-and-delete.md)).

## Next

- Work the list down with [Triage](Triage.md).
- If the site is part-way through a migration, read [Offsite media](Offsite-media.md).
