# WP-CLI

Written against plugin version 1.11.0.

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

