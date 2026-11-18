# UAMSWP Media Reference Audit

Finds media that is not attached to any post, and media that nothing in your site actually references. Reports first. Deletes only when you tell it to.

Adds **Media > Media Audit** to the admin menu.

## Documentation

The long-form documentation is in the [wiki](https://github.com/UAMS-Web/uamswp-media-reference-audit/wiki). Its pages are written in `docs/wiki/` and published from there, so change them through a pull request.

- [Your first scan](https://github.com/UAMS-Web/uamswp-media-reference-audit/wiki/Your-first-scan): run a scan and read the first results.
- [How references are detected](https://github.com/UAMS-Web/uamswp-media-reference-audit/wiki/How-references-are-detected): strong and weak references, size suffixes and skipped keys.
- [Reading the page itself](https://github.com/UAMS-Web/uamswp-media-reference-audit/wiki/Reading-the-page-itself): the render check for files no database row names.
- [Offsite media](https://github.com/UAMS-Web/uamswp-media-reference-audit/wiki/Offsite-media): media the site displays but does not host.
- [Repointing and pulling files over](https://github.com/UAMS-Web/uamswp-media-reference-audit/wiki/Repointing-and-pulling-files-over): rewrite offsite URLs and bring missing files in.
- [Triage](https://github.com/UAMS-Web/uamswp-media-reference-audit/wiki/Triage): tiers, decisions and the work queue.
- [Results screen filters](https://github.com/UAMS-Web/uamswp-media-reference-audit/wiki/Results-screen-filters): what each filter selects.
- [Archive and delete](https://github.com/UAMS-Web/uamswp-media-reference-audit/wiki/Archive-and-delete): build a downloadable archive, then delete.
- [WP-CLI commands](https://github.com/UAMS-Web/uamswp-media-reference-audit/wiki/WP-CLI-commands): `scan`, `list`, `why` and `delete`.
- [Filters](https://github.com/UAMS-Web/uamswp-media-reference-audit/wiki/Filters): every `uams_mra_` filter.
- [Troubleshooting](https://github.com/UAMS-Web/uamswp-media-reference-audit/wiki/Troubleshooting): symptoms, causes and fixes.

## What it cannot see

Read this before you delete anything.

1. **Hardcoded URLs on pages nothing is attached to.** The database passes read tables, not PHP or template files. The render step covers this wherever the file is attached to a published page, since it reads the finished HTML, but a file hardcoded in `header.php` and attached to nothing has no page to fetch and still shows as unreferenced. Grep your theme before a bulk delete.
2. **Media rendered from an external system.** Content pulled in through an API, a feed, a headless front end, or an iframe from another application.
3. **Files someone linked to from outside your site.** A PDF linked from an email campaign, a print piece, or a partner site has no in-database reference.
4. **Object-cached or offloaded media.** If a plugin rewrites upload URLs to a path this plugin does not know, add that path under Scan settings > Extra upload paths.
5. **Anything added after the last scan.** Results are a snapshot. Rescan before deleting.

Basename matching is deliberately conservative. If two attachments share a filename, both are marked referenced when either is found. Overcounting usage is safer than deleting a live file.

## Deleting

Deletion is permanent and removes every generated size. The plugin refuses to delete a file with any recorded reference unless you tick the override box.

**Take a database and uploads backup first.** There is no undo.

## Install

Drop the `uamswp-media-reference-audit` directory into `wp-content/plugins/` and
activate it. No build step, no Composer dependencies, no options to configure
before first use.

```bash
cd wp-content/plugins
git clone git@github.com:UAMS-Web/uamswp-media-reference-audit.git
```

To deploy to a UAMS host, zip the plugin directory and upload it through
**Plugins > Add New > Upload Plugin**:

```bash
cd ..
zip -r uamswp-media-reference-audit.zip uamswp-media-reference-audit \
  -x '*.git*' -x '*.DS_Store' \
  -x 'uamswp-media-reference-audit/vendor/*' -x 'uamswp-media-reference-audit/tests/*' \
  -x 'uamswp-media-reference-audit/.phpstan/*' -x 'uamswp-media-reference-audit/composer.*' \
  -x 'uamswp-media-reference-audit/rector*.php' -x 'uamswp-media-reference-audit/phpstan.neon.dist' \
  -x 'uamswp-media-reference-audit/pint.json'
```

The extra exclusions keep the development tooling below out of the upload.

On multisite, activate per site rather than network-wide unless every site needs
it. Tables are created per site, either on activation or on the first admin
request after that.

Then go to **Media > Media Audit** and run a scan.

Requires WordPress 6.8 and PHP 8.2.

## Development

The plugin has no runtime dependencies. `composer.json` holds development tools
only: Rector, PHPStan with the WordPress extension and stubs, and Pint, set up
from the shared `code-quality` skill in UAMS-Web/uams-claude-skills. They run on
your PHP (Pint needs 8.3 or later); `rector.php` and `phpstan.neon.dist` are
pinned to the plugin's floor, PHP 8.2, so neither suggests syntax 8.2 cannot
parse.

```bash
composer install

composer test:pint       # formatting check; `composer pint` fixes
composer test:refactor   # Rector's gating profile, dry run; `composer refactor` applies
composer stan            # PHPStan, at the level in phpstan.neon.dist
```

`composer refactor:sweep` runs Rector's sweep profile, which adds the rules held
back from the gate; review its diff rather than applying it wholesale.

The commit that first formatted the code with Pint is listed in
`.git-blame-ignore-revs`. Tell `git blame` to skip it, once per clone:

```bash
git config blame.ignoreRevsFile .git-blame-ignore-revs
```

## Performance notes

The scan runs in batches over AJAX and can be closed and restarted. On a large library or a slow host, lower the batch sizes under Scan settings. The postmeta pass is usually the slowest step because it reads every row in `wp_postmeta`.

Four tables are created per site: `{prefix}uams_mra_index`, `{prefix}uams_mra_refs`, `{prefix}uams_mra_results`, and `{prefix}uams_mra_offsite`. All four are truncated at the start of each scan.

## Uninstall

Deleting the plugin from **Plugins** runs `uninstall.php`, which drops all four
tables, removes the plugin's options, and deletes the triage meta from every
attachment. On multisite it walks every site, since the tables are per site.

The tables and options rebuild themselves on the next scan. **The triage marks do
not** — they are human judgement, and this deletes them. Export the CSV first if
they matter. The alternative, leaving the meta behind, would strand rows nobody
on a shell-less host could ever clean up.

Your media files are not touched.

## Credits

Developed and maintained by UAMS Web Services.

Developers:

- Josh Daugherty
- Brent Passmore

## Tests

```bash
composer install
composer test:unit
```

The Unit suite runs on Pest 5 with no WordPress and no database: WordPress functions are mocked with Brain Monkey. The Integration suite runs on PHPUnit 9.6 from its own Composer setup, because WordPress' test suite supports PHPUnit 9 only:

```bash
composer setup:integration
composer test:integration
```

It needs a WordPress core tree and a test database that nothing else uses; `tests/Integration/wp-tests-config.php` lists the `WP_TESTS_*` environment variables. `composer test` runs both suites, each in its own process.
