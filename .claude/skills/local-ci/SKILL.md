---
name: local-ci
description: >-
  Run this repository's checks locally with the shared local CI runner
  (.claude/skills/local-ci/local-ci.mjs, configured by local-ci.json at the
  repository root): no Docker, no act, no GitHub Actions minutes. The runner
  selects jobs from the branch's diff, runs them on the local PHP toolchain,
  prints a PASS / FAIL / SKIPPED summary whose verdict agrees with its exit
  status, and with --comment records that verdict on the pull request naming
  the commit it validated. Activate when validating a branch before a pull
  request, when asked to "run CI locally" or "check this without using Actions
  minutes", when adding local-ci.json or a job to a repository, when setting up
  the pre-commit guard against secret and personal-data files, when gating an
  Actions workflow behind RUN_REMOTE_CI, or when someone asks why the Actions
  jobs on a pull request say `skipped`.
---
<!--
  Synced from UAMS-Web/uams-claude-skills: shared/.claude/skills/local-ci/SKILL.md
  Edit it there, not here; the next sync overwrites this copy.
  Delivered to this repository through the profile(s): local-ci.
-->
# Local CI

Private repositories run their checks on the developer's machine, not in GitHub Actions: Actions minutes on private repositories bill to the organization's shared pool, and that budget is not spent on remote CI. The shared runner is what runs them.

- **`.claude/skills/local-ci/local-ci.mjs`** is the runner: one file, no dependencies, Node 20 or newer, the same on macOS, Linux and Windows. It is synced from `UAMS-Web/uams-claude-skills` and is identical in every repository that has it. Do not edit it here; change it in the source repository.
- **`watch-github.mjs`** is the pull request watcher, with `watch-github.test.mjs` beside it: see [Watching pull requests](#watching-pull-requests-watch-githubmjs). **`glyphs.mjs`** (with `glyphs.test.mjs`) is the banned-glyph scanner its opt-in scan uses.
- **`local-ci.test.mjs`** ships beside the runner and syncs with the skill. Run the suite with `node --test .claude/skills/local-ci/local-ci.test.mjs`, or `node --test local-ci.test.mjs` from the skill directory. On Windows, two spots are platform-sensitive today: the pure `the template` test reads `templates/local-ci.json` through `cat` (not on a stock Windows PATH), and the Herd candidate path assertion builds a macOS-shaped path with Node's `path.join`, which uses backslashes when the suite itself runs on Windows.
- **`local-ci.json`** at the repository root is the repository's own job list. The sync never touches it.
- **`.githooks/pre-commit`** is the commit-time guard against secret and personal-data files, where the repository has one. It reads the same `guard.deny` list the runner checks.

The templates beside this file (`templates/local-ci.json`, `templates/pre-commit`, `templates/gate-workflow.md`, and `templates/local-ci.app-jobs.json` for application jobs) are the starting points for a repository adopting the runner.

## Landing the runner before adopting

The sync from `UAMS-Web/uams-claude-skills` is what delivers `.claude/skills/local-ci/` (the runner, its tests, and the templates). Until that path is tracked in the adopting repository, copying the skill in by hand leaves untracked files: the tree is dirty, and `--comment` refuses before any job runs.

Prefer this order:

1. Land the synced skill path first: merge the sync pull request that adds `.claude/skills/local-ci/`, or commit that directory from this source repository on the adoption branch. The next sync will match what you committed.
2. Then add `local-ci.json` and the pre-commit hook (below) on a clean tree, and run with `--comment` only when the tree is clean.

If you must validate before the sync lands, copy the skill directory from this source repository and **commit** it on the adoption branch. Listing `.claude/skills/local-ci/` in `.git/info/exclude` is only a temporary local workaround so an untracked copy does not dirty the tree for `--comment`; do not put that path in `.gitignore` (the sync needs it tracked), and remove the exclude once the path is committed or the sync lands.

## Turning remote CI off (`RUN_REMOTE_CI`)

GitHub Actions minutes bill at the **organization** level, one pool shared by every `UAMS-Web` repository, and only private repositories consume them. When the pool is exhausted every Actions job fails instantly on a billing error, so the private repositories keep remote CI off and validate locally.

Every job in a gated workflow carries `if: vars.RUN_REMOTE_CI != 'false'`. With the Actions variable `RUN_REMOTE_CI` set to `false` the jobs report `skipped` and consume no runner minutes; unset, or any other value, runs them as before. The default is always-on.

- The variable is defined at the organization level, scoped to private repositories, so a repository inherits it by carrying the gate; nothing has to be set in the repository. A repository variable overrides it for one repository, which is only for testing the switch.
- **A `skipped` job is not a passing job.** When a pull request shows the Actions jobs as `skipped`, the switch is off: validate locally and say so on the pull request.
- **While the switch is off, the local runner is the whole gate.** That is the normal operating mode for months at a time, not a fallback.
- The runner has no concept of `RUN_REMOTE_CI` and always runs the jobs it selects.
- **A required status check from a gated job turns green while nothing runs.** GitHub reports a job skipped by an `if:` condition as `Success`, and it does not block a merge even when it is a required check ([Using conditions to control job execution](https://docs.github.com/en/actions/using-jobs/using-conditions-to-control-job-execution)). So branch protection that requires a gated workflow's checks gives no protection while the switch is off: the requirement is met by a job that did not run, and the local verdict comment is the only record of what was checked. A check stays `Pending` and blocks merging only when the whole workflow is skipped by a path, branch or commit-message filter, which is a different mechanism ([Troubleshooting required status checks](https://docs.github.com/en/pull-requests/collaborating-with-pull-requests/collaborating-on-repositories-with-code-quality-features/troubleshooting-required-status-checks)). Decide branch protection and the switch together.

Gating a workflow that still runs in Actions is in [Gating the Actions workflow](#gating-the-actions-workflow).

## Adding `local-ci.json` to a repository

1. Copy `.claude/skills/local-ci/templates/local-ci.json` to the repository root. The template is a WordPress plugin's configuration: a guard, `php -l` over every tracked PHP file, the `synced-files` check, then Pint, Rector, PHPStan and Pest.
2. Keep the `synced-files` job whatever else is deleted. It runs `.claude/skills/synced-files/scripts/check-synced-files.mjs` on every run (`always`), so a hand edit to a file the shared sync manages fails the run even when the branch touches nothing under `.claude/`, `.agents/` or `.cursor/`. It needs only `node`. A deleted checker fails the job rather than skipping it, and so does a manifest with no `hashes`, which a sync from before UAMS-Web/uams-claude-skills#30 leaves: merge a current sync pull request first. The `synced-files` skill covers the check and its exit statuses.
3. Set `php` to the version the repository deploys on (`"8.2"`), and `base` to the branch pull requests target (`main`, or `dev` where `dev` is promoted to `main`).
4. Delete the jobs for tools the repository does not have yet, and fix the commands of the ones it does: the `run` of each job is the command the repository's own `composer.json` scripts run.
5. Set `guard.deny` to the patterns no tracked file may match: key files, dumps, spreadsheets with personal data, `wp-config.php`, `.env`. The template's list is a WordPress plugin's and includes `\\.env(\\.|$)`, `\\.pem$` and `\\.key$` even when the repository never tracked those; keep a pattern only when the repository should refuse it. Patterns match case-insensitively everywhere the guard runs (the runner, the hook with node, and the hook's `grep -Ei` fallback), so `\\.sql` also refuses `DUMP.SQL` and `dump.Sql`. That is stricter than a migrated Actions step that used case-sensitive `grep -E`: a file that used to pass under a different casing now fails. The template's patterns avoid character classes because the hook's no-node fallback cannot read a pattern containing `]` and refuses every commit under one (see *The pre-commit guard*); keep any pattern added here free of `]` for the same reason. Its `\\.env(\\.|$)` also matches `.env.example`; where the repository tracks one, narrow that pattern (for example to `(^|/)\\.env$` plus the named variants it must refuse), or the guard fails on every run. Leave `guard` out entirely only when the repository has nothing of that kind, which is rare.
6. Run `node .claude/skills/local-ci/local-ci.mjs --dry-run` and read the plan: every job appears, selected or skipped, with its reason. Then run it for real and fix whatever fails.
7. Commit `local-ci.json`. It is repository-owned; the sync never overwrites it.

### The config, field by field

| Key | Meaning |
| --- | --- |
| `base` | The branch the diff is taken against. The runner diffs `HEAD` against its merge base with `origin/<base>`, so a base that moved under the branch does not pull its files into the diff. Default `main`. |
| `php` | The PHP version the jobs run under, `"8.2"` style. See [Toolchain](#toolchain). |
| `chain` | `true` stops at the first failure and reports the later jobs as skipped, naming the failing job. The default `false` runs every selected job and reports all of them, so one run surfaces everything rather than one failure per run. |
| `statusContext` | The context `--commit-status` posts under, `"local-ci"` by default. A non-empty string of at most 255 characters with no control characters. Name the same string in any branch rule that requires the status. |
| `guard.deny` | Regular expressions (JavaScript syntax; the strings are JSON, so a backslash is doubled). When set, a `guard` job runs first on every run and fails when any tracked file matches, naming the files. The same list is what the pre-commit hook checks over staged files. The name `guard` is then reserved. |
| `prepare` | Steps that bring a reused `--branch` slot in line with the commit before any job runs: `residue`, `submodules` and `patches`. Optional. See [Preparing a reused slot](#preparing-a-reused-slot-prepare). |
| `jobs[].name` | Short and unique: letters, digits, `.`, `_`, `-`. It is what `--jobs` and the summary use. |
| `jobs[].run` | A shell command, run from the repository root. When its first word is `php`, or starts with `vendor/bin/`, it runs under the resolved PHP (`vendor/bin/pint --test` becomes `<php> vendor/bin/pint --test`), so the version the config names is the one that runs, and so Windows' `.bat` shims in `vendor/bin` are never spawned directly. Any other command (`composer validate`, `node --test "scripts/*.test.mjs"`) runs as written. The shell is `/bin/sh` on macOS and Linux and `cmd.exe` on Windows, so a command that must run everywhere avoids pipes and shell-specific syntax; `each` exists for the common case. |
| `jobs[].paths` | Globs over repository-relative paths that select the job when a changed file matches one. `*` and `?` stay inside one path segment, `**` crosses segments, `{a,b}` is a choice of literals, and a leading `!` excludes. `*.php` matches only at the root; `**/*.php` matches everywhere; `!vendor/**` keeps a committed `vendor/` out. |
| `jobs[].each` | Globs; the command runs once per tracked file that matches, with the file appended. This is how `php -l` runs over every PHP file without a shell pipeline. When `paths` is absent, the `each` globs select the job too. Output is shown only for the files that fail, with a count at the end. |
| `jobs[].always` | `true` selects the job on every run, whatever the diff. For a cheap job whose inputs are hard to name by path. |
| `jobs[].requires` | Tooling the job needs, as repo-relative paths that must exist (`node_modules`, `scripts/check-no-emoji.mjs`) and `bin:<name>` entries for a command that must be on PATH (`bin:npm`). When any entry is absent the job is `SKIPPED (tooling absent: requires ..., which is absent)`: never run and never failed, so a consumer without a job's tooling is not forced to run it. A selected job is checked at run time, so `--all`, `--jobs` and `always` do not bypass it. When every selected job is skipped this way the verdict is NOTHING RAN; when some ran and passed, it is `PASS (incomplete)` naming the skipped jobs, in the summary and the comment headline alike. Neither is a bare PASS. A path may not be absolute or contain `..`. |
| `jobs[].database` | `{"name": "app_ci", "env": "WP_TESTS_DB_NAME"}` gives the job a database of the runner's own, in that variable, and with `lock` takes the repository's suite lock for it. See [A job's own database](#a-jobs-own-database-database). |
| `jobs[].mutation` | `{"minScore": 100}` makes the job's output part of its verdict, for a Pest mutation run. The output (ANSI stripped) must carry `N Mutations for M Files created` and `Score: N%`; the job fails when the first line is absent, when `N` or `M` is `0` (Pest exits `0` on `0 Mutations for 0 Files created`, so the exit status alone reads a run that mutated nothing as a pass), when the score line is absent, or when the score is below `minScore` (default `100`, range `0` to `100`). A run that exits non-zero fails on its own status. Output is still echoed. Not combinable with `each`. |
| `jobs[].parallel` | `{"serialGroup": "serial", "memoryLimit": "4G"}` runs a Pest job as two parts: `run` plus `--parallel --exclude-group=<serialGroup>`, then `run` plus `--group=<serialGroup>` as a serial tail. `run` must start with `vendor/bin/pest`; extra arguments in it go to both parts. Both parts always run, so a red parallel part does not hide the tail; the job fails when either fails, and the summary and the comment say which (`exit 3; serial part failed`). `serialGroup` defaults to `serial`. `memoryLimit` (a PHP size such as `4G`, or `-1`) is set as `-d memory_limit` on both parts and forwarded to the parallel workers with `--passthru-php`, because the workers are separate PHP processes that otherwise run at the system CLI limit. Not combinable with `each` or `mutation`; a job declaring no `parallel` runs as one command. |

**A key the config does not define is refused, not ignored.** The run fails before any job, naming the key, where it sits and the keys that level takes: `jobs[1] ("pest") has no "databse"; it takes "name", "run", "always", ...`. Without this a misspelled `database` parsed as no database and the job ran against the repository's usual one, and a misspelled `statusContext` fell back to `local-ci` silently. The top level takes `base`, `php`, `chain`, `guard`, `jobs`, `prepare`, `statusContext` and `watch` (read by `watch-github.mjs`, which validates its contents); a job takes `name`, `run`, `always`, `each`, `paths`, `requires`, `mutation`, `parallel` and `database`. The same refusal applies inside `prepare`, `database`, `database.lock`, `mutation` and `parallel`.

**Annotations: a key beginning with an underscore is allowed on the top level and on a job, and is ignored** (`"_note": "why this job exists"`). That is the only exception. `$schema` and `comment` are refused: no schema is published for the file, and a word that is not a prefix cannot be told apart from a mistyped real key. An underscore key never hides a typo beside it, and an annotation inside a nested object is refused like any other key there.

## Running it

Prerequisites: Node 20 or newer; for `vendor/bin` jobs, `composer install` has run (the runner refuses before any job with the remedy named when `vendor/` is missing); PHP, found as [Toolchain](#toolchain) describes.

```bash
# Jobs selected by the diff against origin/<base>, plus the guard and always-jobs
node .claude/skills/local-ci/local-ci.mjs

# What would run, and why, without running it
node .claude/skills/local-ci/local-ci.mjs --dry-run

# Every job; or a named subset (the guard still runs first)
node .claude/skills/local-ci/local-ci.mjs --all
node .claude/skills/local-ci/local-ci.mjs --jobs pint,phpstan

# Diff against another base; post the summary on the pull request
node .claude/skills/local-ci/local-ci.mjs --base dev
node .claude/skills/local-ci/local-ci.mjs --comment

# Validate a pushed branch in its own worktree (see Validating a branch in a slot)
node .claude/skills/local-ci/local-ci.mjs --branch my-branch
```

The run prints `Validating: <sha> <subject>` first, then the changed-file count and the base it was diffed against, then one `==> JOB <name>: <reason>` line per job as it starts, then the summary. It runs in the tree it lives in: the copy in a worktree validates that worktree, and the shell's current directory does not matter.

**Run it on a clean tree.** When the tree has uncommitted changes the run warns at the start and the summary says `Tree: DIRTY`, because the verdict then describes the working tree and not the commit named on the `Validating:` line. The run is still useful while working; it is not a record of anything.

A deleted file counts as changed, so deleting a file other code requires selects the jobs whose `paths` match it; an `each` job skips a tracked file that is no longer on disk. When the branch and `origin/<base>` share no history (a shallow clone, typically), the changed files cannot be listed and the run refuses rather than selecting from an empty diff; `git fetch --unshallow`, or run `--all`. When neither `origin/<base>` nor `<base>` resolves at all (a single-branch clone of another branch), `--all` and `--jobs` warn that the changes were not read and run; any other run refuses, naming `--base <ref>` and `--all`.

**Job selection is a speed optimization with one failure mode:** a job whose inputs the diff touches but whose `paths` do not name them is skipped, and because remote CI is off, nothing else catches it. When a diff touches something a job's `paths` do not describe, run `--all`, and fix the `paths`.

## Reading the verdict

```
================ local-ci summary ================
  Commit:   3f9c2e1d... Add the upload guard
  Tree:     clean: this verdict describes the commit
--------------------------------------------------
  guard     PASS        0.0s
  lint      PASS        1.2s
  pint      FAIL        3.4s  (exit 1)
  pest      SKIPPED           (not selected: no changed file matches **/*.php, phpunit.xml*)
--------------------------------------------------
  Verdict:  FAIL: 1 of 3 jobs run failed: pint
==================================================
```

- **PASS** and **FAIL** are jobs that ran. **SKIPPED** is a job that did not, with the reason: not selected by the diff, not named by `--jobs`, or stopped by `chain` after an earlier failure. Skipped is not passed, in the summary, in the comment and in the merge decision.
- **NOTHING RAN** is the verdict, and the comment's headline, when no job was selected (no guard and no `always` job, and nothing the diff touched). The exit status is `0`, because a change no job reads is not a failure, but nothing was checked and nothing says PASS.
- **PASS (incomplete)** is the verdict, and the comment's headline, when every job that ran passed but a selected job was skipped for absent tooling (`requires`). The exit status is `0`, and the verdict names the skipped jobs. Quote it as incomplete, never as a pass.
- **The exit status is the contract.** It is `1` when any job that ran failed and `0` otherwise, and also `1` on a refusal (no config, no PHP, `--comment` on a dirty tree), where no job ran at all and there is no summary to read. A run refused because another run holds the worktree exits `4`. A job killed by `SIGINT`, `SIGTERM` or `SIGHUP` ends the run at once with `130`, `143` or `129`, no summary and no comment; any other signal counts as that job failing. The verdict line is derived from the same results by the same test, so the two cannot disagree, and the unit tests prove it in both directions.
- **A pipe throws the exit status away.** `$?` after `node local-ci.mjs | tail` is `tail`'s. Measured in `wordpress-importer` on macOS (Darwin 25.6.0) on 2026-09-05, reproducing a refusal: redirected to a file the status was `1`; piped to `tail` and read after the pipeline, `0`; the same pipeline under `set -o pipefail`, `1`. Set `pipefail`, or read the status with no pipe in between, and never derive a verdict from a wrapper's exit code without one of those. Read the summary as a cross-check, not as the contract.
- **Compare the `Commit:` line with the pull request head** before quoting a green. A run on an older commit, or on a dirty tree, describes something other than what merges.

## Adding a job

1. Add an entry to `jobs` in `local-ci.json`: a `name`, the `run` command the repository's `composer.json` or `package.json` already defines, and the `paths` whose change should select it. Name every input: the source the tool reads, its configuration file, and `composer.lock` for a tool whose rule set depends on installed versions (Rector, PHPStan).
2. If the tool runs per file with no option to take a directory, use `each` instead of a pipeline.
3. Run `--dry-run` with a change that should select the job and one that should not, and read the reasons.
4. Run `--jobs <name>` to see the job pass and, by breaking something, fail. A job that cannot fail is not a check.
5. Where the repository also has an Actions workflow, keep its job list the same as `local-ci.json`'s, so a reader of either sees the same gate.

A job with no `paths` and no `always` is reported `SKIPPED (not selected: ... runs only with --all or --jobs)`; that is for a job too slow to run by default, and the summary says so every time rather than letting it disappear.

## Application jobs

The `requires` field and the existing `run`, `paths`, `each` and `always` fields express the app-level jobs the three private repositories (`uams-statamic`, `wordpress-importer`, `uamswp-migration-api`) still run in their own runners. `templates/local-ci.app-jobs.json` is a dry-run example keyed by repository: each key is a complete `local-ci.json`, copy the block for the repository and delete the jobs it does not need. After copying a block to `local-ci.json`, run `--dry-run` to read the plan.

| Job shape | Declared as | Used for |
| --- | --- | --- |
| npm script | `run: "npm run <script>"`, `requires: ["node_modules", "bin:npm"]` | `build`, `js`, `spell` |
| Composer or Node validation | `run: "composer validate ..."`, `requires: ["bin:composer"]` | `validate`, `composer-validate` |
| Repository guard script | `run: "node scripts/<guard>.mjs"`, `always: true`, `requires: ["scripts/<guard>.mjs"]` | no-emoji, encoding, glyph, load, exports-gitlink guards |
| Per-file test runner | `run: "node --test"`, `each: ["scripts/*.test.mjs"]` | `node-test` |
| Test-suite subset | `run: "vendor/bin/pest tests/Browser"`, `requires: [..., "public/build/manifest.json"]` | `browser`, `mutate` |

- **`each` carries the node-test job.** Node 20 has no glob support in `node --test`, so the glob is expanded by the runner and the command runs once per tracked file, which is the same on every platform.
- **A `requires` path can name a build output.** The `browser` job lists `public/build/manifest.json`, so without a prior `npm run build` it is skipped with the file named rather than failing on stale assets.
- **Not expressed.** The `spell` jobs run the repository's own `npm run spell`, which owns any zero-files-checked floor. The `mutate` job declares `"mutation": {"minScore": 100}`, which carries the mutation smoke's non-zero-count and score assertions (see `jobs[].mutation`).

## Recording the verdict on the pull request (`--comment`)

**Comment the verdict on the pull request before any merge decision, green or red.** A verdict that exists only in a terminal or on a channel is not a record: the person merging has nothing to read, and neither does anyone reading the pull request next month.

`--comment` posts the summary as a comment on the open pull request for the current branch (found with `gh pr view`), through `gh`, after the jobs have run. The comment carries:

- **The full SHA of the commit validated,** on the first line. Remote CI is off, so the pull request shows a set of `skipped` Actions jobs, and a reader who does not know that reads `skipped` as a failure to run. A bare count ("8/8 pass") has the same defect from the other side: it reads as remote jobs passing. Naming the commit is what lets a reviewer check that the checks passed on exactly what merges, and gives "has the head moved since?" a written baseline rather than a memory. When the pull request head is not the validated commit the runner warns before posting.
- **The jobs that actually ran,** each with PASS or FAIL and its duration, and separately the jobs that did not run, with their reasons.
- **That the run was local,** and that the skipped Actions jobs are expected where `RUN_REMOTE_CI` is off.

Two refusals and one non-event:

- **A dirty tree refuses `--comment` before any job runs.** The comment names a commit, and a run on a dirty tree describes the working tree instead. Commit or stash, then run again.
- **No open pull request for the branch** skips the comment with a warning, including when the branch's most recent pull request is merged or closed (a reused branch name). Open the pull request first, or comment by hand.
- **A comment that cannot be posted never changes the exit status.** No `gh`, no network, no permission, a reply it cannot parse: the runner warns and the verdict stands. A comment is an annotation; letting it fail a green run, or pass a red one, would be the inverse of what it exists for.

A red comment is posted on the same terms, naming the failing job. What happens next, who re-runs, and when, belongs to the repository's merge rules; the runner records, it does not decide.

## Posting a commit status (`--commit-status`)

`--commit-status` posts the verdict as a commit status on the commit the run validated, beside the comment. The comment is read by a person; the status is what the commit itself shows, and what a branch rule can read.

- **The call.** `gh api -X POST repos/{owner}/{repo}/statuses/<sha>` (the REST API, so it does not draw on the GraphQL quota) with `state`, `context` and `description`. `<sha>` is the commit named on the `Validating:` line. In a `--branch` run that is the remote head, so the status lands on the head the pull request shows.
- **The state follows the exit status:** `success` when the run exits `0`, `failure` when it exits `1`. The description is the verdict line (`PASS: 5 of 5 jobs run passed`), or, for a red run, the failing job names first (`pest, pint failed`), cut to the 140 characters GitHub keeps. A `PASS (incomplete)` run posts `success` with that wording in the description.
- **A run in which no job ran posts nothing,** with a warning. Its exit status is `0`, and a green status on a commit nothing checked is the false reassurance a status exists to remove.
- **The context** is the config's `statusContext`, `local-ci` by default.
- **Off unless asked.** Without `--commit-status` the runner posts no status. A developer running the checks on their own branch should not write to GitHub; the gating session passes the option.
- **A dirty tree refuses it before any job runs,** for the reason `--comment` does: a status belongs to a commit.
- **A status that cannot be posted never changes the exit status.** No `gh`, no network, no permission: the runner warns and the verdict stands.
- **It is only ever terminal.** The runner posts once, when the run is over, and never `pending`. A run that is killed, or that nobody started, leaves no status on the commit.

**A branch rule that requires the status waits for it.** While the runner is not running, no status exists on the head, and a required status that is absent holds the merge as `Expected: waiting for status`, which reads as a run in progress and is not one. Nothing in GitHub distinguishes "the runner has not been started" from "the runner is slow". The rule's context must match `statusContext` exactly, and requiring it means every head needs a gate run before it can merge, including a one-line documentation fix. A status also describes the commit and not the pull request: a new push to the branch leaves the earlier head's status on the earlier head. Whether to require it is the repository's decision with its rulesets, and not this runner's.

## Validating a branch in a slot (`--branch`)

`--branch <name>` validates a branch that is already on `origin` in a worktree the runner owns, so the invoking checkout never moves: its `HEAD`, index and working tree are the same afterwards, uncommitted work included.

```bash
node .claude/skills/local-ci/local-ci.mjs --branch my-branch
node .claude/skills/local-ci/local-ci.mjs --branch my-branch --worktree-path ../elsewhere-ci
```

- **The slot** is `<primary>-ci`, a sibling of the primary checkout (found through `git rev-parse --git-common-dir`, so a run started from any linked worktree resolves the same one). `--worktree-path` places it elsewhere. A slot that would be the checkout the run started from is refused, which includes a run started inside the slot itself.
- **What is validated** is the remote head. The runner asks `origin` for the branch, fetches it, and detaches the slot at that commit. A local branch of the same name, and any unpushed commit on it, is never validated, and no ref is written in the invoking repository.
- **A name `origin` does not have** is refused with `origin has no branch <name>` before a worktree is created or touched. A name that is not a valid branch name is refused before `origin` is asked.
- **Reuse.** The runner writes a marker (`local-ci-slot`) into the slot's own git directory when it creates the slot, and moves only a slot that carries it. A worktree at the slot path that the runner did not create, such as a standing checkout a repository already keeps at `<primary>-ci`, is refused before anything is touched, with `--dry-run` included; pass `--worktree-path` to place the slot elsewhere. An existing runner-made slot is moved to the new head. A slot with uncommitted changes to tracked files is refused, because a runner-owned tree is never hand-edited. Untracked files do not block it.
- **Runner handoff.** When the branch's copy of `local-ci.mjs` differs from the executing one (line endings aside), the run prints `Handoff:` and re-executes the branch's copy inside the slot, with `--branch` and `--worktree-path` removed, so a pull request that changes the runner or its job list is gated by its own. The branch's copy runs the whole chain and does not hand off again: it refuses `--branch` when it was started by a handoff. When the copies match, the chain runs in the same process, in the slot.
- **`--dry-run`** still prepares the slot, since selection reads the branch's own files and config.
- **One run at a time.** The slot is locked for the run (see *One run at a time*), and a second `--branch` run against it is refused with the holder named.
- **`--comment`** posts on the pull request whose head is the named branch. A branch named only with digits is refused with `--comment`, because `gh` reads a bare number as a pull request number.
- **Jobs** do not see the handoff markers, so a job that runs the runner itself is not mistaken for a handed-off run.

What this runner leaves to the repository's own: `--fresh`, `--skip-install`, and bootstrapping the slot (`composer install`, `npm ci`). Run the repository's bootstrap in the slot once before relying on a job that needs it. Bringing a reused slot back in line with the commit (submodules, leftover files, Composer patches) is `prepare`'s job, below.

### Preparing a reused slot (`prepare`)

A slot is reused from run to run, so it can hold what the commit being validated does not: a submodule at an older commit, files an earlier run wrote, a `vendor/` patched for an older `patches.lock.json`. `prepare` in `local-ci.json` names the steps that bring it in line. Each is opt-in, and with no `prepare` key the runner behaves as it did before.

```json
"prepare": {
    "residue":    ["tests", "includes"],
    "submodules": ["content", "users"],
    "patches":    true
}
```

| Step | What it does |
| --- | --- |
| `residue` | Paths inside the repository. The run fails before any job, naming each file, when one of them holds an untracked file that is not ignored. Nothing is deleted: delete the files, or remove the slot with `git worktree remove --force` so the next run recreates it. "Ignored" is git's reading, so a global `core.excludesFile` or `.git/info/exclude` counts as well as the commit's `.gitignore`. The check does not reach inside a submodule: a file left in an initialized submodule is that submodule's untracked file, and the runner's dirty-tree warning is the only sign of it. |
| `submodules` | Submodule paths. Each is checked out at the gitlink the validated commit records (`git submodule update --init`), and its `HEAD` is read back against that gitlink. A path the commit does not record as a submodule is refused, and so is an uninitialized submodule directory that still holds files, which are named rather than cleared. Submodules not listed are left alone. The fetch uses the invoking user's own git credentials, and the url the slot's submodule was initialized with: when a commit changes a submodule's url in `.gitmodules`, the update fails and its message names `git -C <slot> submodule sync -- <path>` to run. The runner does not sync itself, because the sync also writes the url to the repository's shared git config, which the primary checkout reads too. |
| `patches` | `true` keeps a reused `vendor/` in line with the commit's `patches.lock.json` (cweagans/composer-patches). The runner records the lock it last brought `vendor/` in line with at `vendor/.local-ci-patches.lock.json`. When the commit's lock differs from that record, or there is no record, it runs `composer reinstall` for every installed package either lock patches, and writes the new record only after the reinstall succeeds; a failed reinstall fails the run before any job and leaves the old record in place. A lock that differs only in line endings is the same lock. A `patches.lock.json` that is not valid JSON fails the run, naming the file; a record that is not valid JSON counts as no record. `composer` is the one first on PATH, run under whatever PHP it finds, not the PHP the config names. With no `vendor/composer/installed.json` there is nothing to bring in line and the step says so; `composer install` itself stays with the repository's bootstrap. |

- **Order:** residue, then submodules, then patches, and every refusal comes before any job runs. The residue check runs first so files left in a listed path are reported before a step changes the tree.
- **Only in a runner-owned slot.** A run in a developer's own checkout prints `Prepare:    not run (...)` and runs the jobs, because its untracked files are work in progress and its `vendor/` is the developer's. `prepare` takes effect in a slot `--branch` created, whether the run came through `--branch` or was started inside the slot, and after a handoff it is the branch's own copy of the runner that prepares the slot.
- **`--dry-run`** prints `Prepare:    would run ...` and runs none of the steps.
- A path that is absolute, contains `..`, or names the repository root is refused when the config is read, and so is a key other than these three.

## One run at a time (the single-flight lock)

Two runs in one worktree take the tree out from under each other: the second checks a branch out beneath the first's jobs, or a job rewrites a cache the other is reading, and both report failures no diff explains. The runner therefore holds a lock on the worktree it executes in, and refuses a second run before any job starts.

```
local-ci: Another local-ci run holds /work/repo-ci: pid 4120, validating origin/my-branch in the slot, since 2026-10-05T14:02:11.000Z. Wait for it, or run against another worktree. If pid 4120 is not a local-ci run, remove /work/repo-ci.lock and run again.
```

- **Keyed on the worktree the run executes in.** A plain run locks the checkout it lives in. `--branch` locks the slot (`<primary>-ci`, or `--worktree-path`), and takes the lock before the slot is created or moved, so a refused run changes nothing. Two runs in different worktrees do not contend.
- **Where it lives.** `<worktree>.lock`, a sibling directory of the worktree and never a file inside it, so a checkout or a clean of the tree cannot delete the lock a live run holds. `holder.json` inside it records `pid`, `branch` (`null` for an in-place run), `worktree` and `startedAt`. The directory is created with `mkdir`, which is the atomic step: whichever run creates it holds the lock.
- **The refusal** exits `4` (`1` stays for failed jobs and ordinary errors), prints the holder's pid, what it is validating, and when it started, and runs no job.
- **A dead holder is reclaimed.** When the recorded pid is no longer running, the run prints a `reclaiming the lock` warning naming it, removes the lock, and proceeds. A lock whose record is missing, empty or unreadable is never reclaimed, because a run between creating the directory and writing its record looks exactly like that; the refusal says so and names the directory to remove if no run is active. A pid that has been reused by an unrelated process reads as alive, which is why the refusal names the directory to remove.
- **Released** on success, on failure, on an error that stops the run, and when a job is killed by `SIGINT`, `SIGTERM` or `SIGHUP` on its own (the runner stops instead of starting the next job, and exits `130`, `143` or `129`; on Windows Node reports no signal for a child, so this path is POSIX only). A terminal's Ctrl-C reaches the runner as well as the job, and Node ends the runner without running any release, so that lock is reclaimed by the next run like one left by a kill. Only a lock that still names the releasing process is removed. A run killed outright, such as `SIGKILL` or a `SIGTERM` sent to the runner alone, cannot release anything; the next run reclaims its lock because the pid is gone.
- **`--branch` handoff.** The parent holds the slot lock across the handoff. The re-executed branch copy carries `LOCAL_CI_HANDOFF` and takes no lock of its own, so it is not refused by its parent. The marker's value is the parent's pid, and a run is treated as handed off only when that value equals its own parent process id. A marker exported in a shell, or inherited from some other process, names no parent of the run that reads it, so it neither skips the lock nor refuses `--branch` (#89). The runner removes the marker from the environment of every job.
- **Decisions on the edges of the lock (#89).**
    - **Pid reuse: accepted.** A recorded pid that the operating system has handed to an unrelated process reads as alive, and the lock is refused rather than reclaimed. Recording a process start time would close it, at the cost of a platform-specific read on every run, and the failure is the safe one: the refusal names the directory to remove, and nothing is deleted on a guess.
    - **A crash between `mkdir` and the record write: accepted.** The lock directory then has no readable record and is refused, never reclaimed, by design. A run that is still between the two steps looks identical, and reclaiming it would let two runs hold one worktree, which is the failure the lock exists to prevent. The refusal names the directory to remove, and the window is a few milliseconds wide.
    - **The handoff marker: fixed.** See the `--branch` handoff bullet above.
- **`--dry-run`** without `--branch` changes nothing and takes no lock, so it is not refused by a run in progress. With `--branch` it prepares the slot, so it takes the lock.
- **A test database** is locked separately, by the job that uses it: see *A job's own database*.

## A job's own database (`database`)

A suite whose test installer drops every table it finds cannot share a database with another run of itself: a runner started while someone runs the suite by hand destroys that run's fixtures, and both report failures no diff explains. A job's `database` gives it a database of the runner's own and, where the repository's suite already guards its database with a lock file, takes that same file.

```json
{
    "name": "pest",
    "run": "vendor/bin/pest",
    "database": {
        "name": "app_ci",
        "env": "WP_TESTS_DB_NAME",
        "lock": { "prefix": "app-ci", "heldEnv": "LOCAL_CI_SUITE_LOCK_HELD" }
    }
}
```

| Key | Meaning |
| --- | --- |
| `name` | The database the job uses: letters, digits, `_`, `$` or `-`, at most 64 characters. Choose a name no hand-run suite uses. |
| `env` | The environment variable that carries `name` to the job. The job runs with `name` there, whatever the invoking shell had set, and with every other variable any job's `database` names removed, so it sees no database name but its own. |
| `lock.prefix` | The lock file prefix the repository's suite uses. The run takes `<tmpdir>/<prefix>.database.<first 16 hex digits of sha256(name)>.lock` before any job runs: created exclusively with its record already in it (written to a temporary file and linked into place, so the file is never seen empty, which the suite would read as abandoned), holding JSON whose integer `pid` names the holder. While a live process holds it, the run exits `4` before any job, naming the holder's pid, what it is, and when it started. A suite started by hand while the run holds it is refused by the suite's own guard. Optional: without `lock`, the job still gets its own name, unguarded. |
| `lock.heldEnv` | A variable set to `1` for the job, telling the suite that its parent already holds the lock, so the job's own suite does not refuse against the run that started it. Optional, but a suite that takes the same lock needs it: without it the suite refuses its own runner on every run. It may not be the same variable as `env`, and neither may name a variable the runner relies on (`PATH`, `TEMP`, `TMPDIR`, `LOCAL_CI`, `LOCAL_CI_PHP` and the like). |

- **A job without `database`** runs with the runner's environment unchanged.
- **A dead holder is reclaimed** with a warning, as the worktree lock is. A record that cannot be read is never reclaimed; the refusal names the file to remove if no run is using the database.
- **One lock per file.** Jobs that declare the same `prefix` and `name` share one lock, taken once for the run. All are taken before the first job, and released on the same ways out as the worktree lock.
- **`--dry-run`** prints each job's database and lock file and takes no lock.
- **The temp directory has to agree.** The runner uses Node's `os.tmpdir()`, and the suite its own language's (PHP's `sys_get_temp_dir()`). On macOS and Linux both read `TMPDIR`. On Windows Node reads `TEMP` before `TMP` and PHP reads `TMP` before `TEMP`, so they agree while the two are the same, as they are by default. PHP's `sys_temp_dir` setting overrides all of these. `--dry-run` shows the path the runner will use; compare it with the path in the suite's own refusal.
- **The runner does not create the database.** Create it once, or have the job's command create it before the suite runs.

## The pre-commit guard

A local CI run, like an Actions run, sees a secret or personal-data file only after it has been committed, and the pull request sees it only after it has been pushed. Git history is permanent, so the check that matters most has to run before the commit exists. The runner's `guard` job stays as the backstop over every tracked file.

Setup, once per repository:

1. Copy `.claude/skills/local-ci/templates/pre-commit` to `.githooks/pre-commit` and make it executable (`chmod +x .githooks/pre-commit`; on Windows, `git update-index --chmod=+x .githooks/pre-commit` after staging it). Commit it.
2. Document the one-time step every clone needs, in `CONTRIBUTING.md` or the README: `git config core.hooksPath .githooks`. Git does not run hooks from inside the repository until told to. A repository with `composer.json` can run that command from a `post-install-cmd` script so a clone that installs is wired without reading the instructions.
3. Check it: stage a file named `sentinel-key.php` (or any pattern on the list) and commit; the commit is refused and the file named. Unstage it. An ordinary commit still works.

The hook reads `guard.deny` from `local-ci.json`, so the list is maintained in one place. When `local-ci.json` is missing, the hook prints a warning to stderr that the guard was skipped (not adopted yet) and exits 0, so a clone that has the hook but no config is not blocked; once the repository has adopted local-ci, that file belongs at the root, and the warning is the signal that the guard is not running. With `node` on PATH it matches exactly as the runner does, and a config it cannot evaluate (not JSON, `guard.deny` not an array of strings, a pattern that is not a regular expression) refuses the commit. Without `node`, a small shell parser reads the array and `grep -E` matches, which agrees for the patterns in the template (none contains `]`); a pattern containing `]` (a character class) or one `grep -E` cannot compile refuses every commit rather than passing with the guard disarmed, so a repository that needs such a pattern keeps `node` on PATH. `git commit --no-verify` bypasses the hook; the runner's `guard` job fails on the same file, so the bypass only moves the failure to the pull request.

## Gating the Actions workflow

A private repository whose checks moved to the runner gates its workflow rather than deleting it: every job gets `if: vars.RUN_REMOTE_CI != 'false'` and the header explains why, so the workflow stays, evaluates on every event, dispatches nothing, and comes back with a variable change. The order is: runner first, then the gate, so the repository is never without a gate. `templates/gate-workflow.md` has the snippet, the header comment, the branch-protection caveat and the steps.

## Toolchain

PHP resolves, in order: `LOCAL_CI_PHP` (an absolute path to the binary; a path that does not exist or cannot be executed is an error, not a fall-through); on macOS, Herd's version-pinned binary for the config's `php` (`php82` for `"8.2"`, under `~/Library/Application Support/Herd/bin`), so a run targets the deployment version even when the default Herd PHP is another; then `php` on PATH, which on Windows is Herd's `php.BAT` shim. When the pinned Herd binary is not installed, resolution continues to PATH rather than failing: the runner prints which binary it chose and its version, and warns when that version is not the one `local-ci.json` names (so an `"8.1"` repository can be linted under 8.4 with only a warning). It fails before any job when none is found. PHP is resolved only when a selected job needs it, so a repository with no PHP job runs on a machine with no PHP.

`--dry-run` lists those candidate sources in order (Herd pin, then PATH). When a Herd pin is listed but not present on disk, the plan notes that a real run falls through to PATH and warns on a version mismatch. Read the candidate line as the search order, not as a guarantee that the named Herd binary will be used.

The jobs run with `LOCAL_CI=1` and `LOCAL_CI_PHP=<resolved path>` in their environment, so a script a job runs can use the same interpreter. Composer, Node and other tools a `run` names are resolved by the shell from PATH, as written.

## Watching pull requests (`watch-github.mjs`)

`.claude/skills/local-ci/watch-github.mjs` prints one line per pull request lifecycle event, for a reviewing session to act on. It is one file with no dependencies (Node 20 or newer), synced with the skill like the runner, and it only reads: every call is a REST `GET` through `gh api`, so it never draws on the GraphQL quota and never comments, labels, flips a draft or merges. What to do with an event is the reading session's decision.

```bash
node .claude/skills/local-ci/watch-github.mjs                       # this repository, every 60 seconds
node .claude/skills/local-ci/watch-github.mjs --interval 30 --emit-existing
node .claude/skills/local-ci/watch-github.mjs --repos OWNER/REPO,OWNER/OTHER
node .claude/skills/local-ci/watch-github.mjs --issues              # also ISSUE / ISSUE-GONE
node .claude/skills/local-ci/watch-github.mjs --no-prs --issue-repos OWNER/REPO
node .claude/skills/local-ci/watch-github.mjs --glyphs              # also GLYPH lines for PR bodies and new comments
node .claude/skills/local-ci/watch-github.mjs --once                # seed and exit; --help lists every flag
```

Arm it under the harness `Monitor` tool with `persistent: true`, so each stdout line becomes a notification.

- **Which repositories.** `--repos`, else `watch.repos` in `local-ci.json`, else the `origin` remote of the checkout it runs in, so a bare run watches the repository it sits in. No repository is named in the script. `--issue-repos` (or `watch.issueRepos`) scopes the issue feed separately; it defaults to the pull request list.
- **The `watch` section** is optional and repository-owned: `{"watch": {"repos": ["OWNER/REPO"], "issueRepos": ["OWNER/REPO"], "glyphs": true}}`. The runner ignores it; a malformed one stops the watcher with the field named.
- **Events**, two spaces between fields: `READY` and `DRAFT` (opened, or flipped), `PUSH` (an open, non-draft pull request's head moved: `pushed <sha9>`), `GONE` (`merged` or `closed`), `WATCH-ERROR` (a repository that cannot be read), and, with `--issues`, `ISSUE` and `ISSUE-GONE`. A `WATCHING` line follows the first poll and names both feeds, the off one included, so a silent feed is distinguishable from a quiet repository.
- **The first poll is silent** so arming mid-stream does not replay history; `--emit-existing` announces the open pull requests instead. Issues are not replayed either way: the first poll only sets the `updated_at` watermark.
- **The glyph scan** (`--glyphs`, or `watch.glyphs: true`) is off by default. It enforces the `no-emoji-in-durable-records` rule on what no file sweep reaches: the body of each pull request reported as `READY` or `PUSH` (a draft is scanned once it turns ready), and each comment on the repository's issues and pull requests posted or edited since the last poll. A banned glyph prints `GLYPH  <repo>#<n>  body carries a banned glyph  line <l>: U+XXXX ...`, or `... comment carries a banned glyph  line <l>: U+XXXX ...  <url>`: one line per body or comment, code points only, never the character. The body comes from the listing the watcher already reads, so it costs no request; comments cost one `since=` read per repository per poll, and the first poll only sets that watermark.
- **The banned class is read from the synced rule**, `.claude/rules/no-emoji-in-durable-records.md`, never restated in the scanner. It is found relative to the scanner file, from the `.claude/skills/` copy and the `.agents/skills/` copy alike. Before the first poll the scanner proves both control arms: every bound of every range in the class is matched, and clean ASCII prose is not. If the rule cannot be read or parsed, or an arm fails, the watcher prints `WATCH-ERROR  glyphs  scan disabled, no GLYPH lines will be printed  <why>`, names the scan `glyphs FAILED` in its `WATCHING` line, and prints no `GLYPH` verdict; the other events carry on. Without the scan, the output is exactly what it was without it. `--glyphs` with `--no-prs` is refused, since the scan rides the pull request feed.
- **Pagination walks `?page=N`**, never `--paginate`, which a cloud session's proxy refuses after page 1 with an exit status a pipe discards. A page past the ceiling, or a payload that is not an array, is a `WATCH-ERROR`, not a short list.
- **Failures are counted per repository.** A repository that fails its first poll alerts at once (a setup error: auth, a typo, no access); one that was already seeded alerts on the third consecutive failure and every thirtieth after, and keeps its previous state in between so a transient error never produces phantom events.
- **A pull request that leaves the listing but is still open emits nothing** and is carried forward: a page served short, or a poll that raced a reopen, is not a state change.
- **Tests** are `watch-github.test.mjs` beside it: recorded API responses served by a fake invoker, so no network. Run `node --test .claude/skills/local-ci/watch-github.test.mjs`.

Validating, commenting on and merging the pull requests it reports is not part of the watcher.

## Reviewing a pull request (validate, comment, merge or hand back)

A session that gates a repository takes the pull requests the watcher reports one at a time and, for each, validates the exact head, records the verdict on the pull request, and then either merges that head or hands the pull request back. The runner, the slot, the lock and the watcher are described above and are not repeated here; this section is the order they are used in. What to check before merging, beyond the chain, belongs to the `pre-merge-check` rule, and what a red verdict does next is that rule's too.

**One pull request at a time, start to finish.** A queue entry is a pull request, not a push: a second `PUSH` for a pull request already queued replaces the head to validate, it does not add an entry. A `DRAFT` event is a note, not a dequeue: the author withdrew the pull request, so an in-flight validation of it is abandoned and nothing is merged. The first poll is silent, so seed the queue once with `--once --emit-existing` when the session starts.

1. **Pick the entry and read its head.** Read the full head SHA from the API, never from a short form printed elsewhere:

   ```bash
   gh api repos/{o}/{r}/pulls/<n> --jq '[.draft, .head.ref, .head.sha] | @tsv'
   ```

   A draft is not a candidate. Keep the 40-character SHA: the merge in step 4 is pinned to it.

2. **Validate that head in the runner's slot, with the verdict posted.**

   ```bash
   node .claude/skills/local-ci/local-ci.mjs --branch <head.ref> --comment > ci.log 2>&1
   ```

   `--branch` validates the remote head in a slot the runner owns, so the gating session's own checkout never moves and the author's branch is never checked out by the gate. Read the summary rather than the exit code, and confirm that the commit it names (the `Validating:` line and the `Commit:` line) equals the SHA from step 1. When they differ the pull request moved during the run: discard the result and start again from step 1. A second run against a slot a live run holds is refused with exit `4`, so a gate session never has two runs in one slot.

   **On a first run, two things stand between that command and a verdict worth reading.**

   - **The default slot may be taken.** The slot is the sibling `<primary>-ci`, and where that path is already a worktree the runner did not create (a standing checkout the gate keeps there, for instance) the command exits `1` with `<path> is a worktree of this repository that the runner did not create, so it will not be moved.` Pass a path of the runner's own and keep passing the same one, so later runs reuse that slot: `--branch <head.ref> --worktree-path ../<primary>-review --comment`.
   - **A new slot has no dependencies.** The runner creates the worktree and checks out the commit; it does not install anything. A job whose tool lives in `node_modules/` or `vendor/` then fails, and the verdict reads `FAIL` on a commit that is clean, exactly as a real failure would. Run the repository's bootstrap in the slot once (`npm ci`, `composer install`, or whatever it uses) before trusting the slot's first verdict, and again whenever a lockfile moves. Measured on Windows 11 against a clean commit (the dry walk recorded on #66): `FAIL: 2 of 5 jobs run failed` (tests, spell) in a fresh slot, then `PASS: 5 of 5` on the same commit after `npm ci` there.

3. **Record the verdict before any merge decision, green or red.** `--comment` posts it naming the commit and the jobs that ran (see *Recording the verdict on the pull request*). A run that skipped jobs says so; `skipped` is not `passed`. `--comment` goes through `gh pr view`, which draws on the GraphQL quota; when that is exhausted, post the same text with `gh api -X POST repos/{o}/{r}/issues/<n>/comments -F body=@verdict.md`. A verdict that exists only in a terminal is not a record.

4. **Green: merge the validated head, pinned.**

   ```bash
   gh api -X PUT repos/{o}/{r}/pulls/<n>/merge -f merge_method=<method> -f sha=<40-character head from step 1>
   ```

   `sha` makes GitHub refuse the merge when the head is not that commit. The refusal is `409` with `Head branch was modified`, and it reads the same whether the branch really moved or the SHA passed was never a commit; `git cat-file -t <sha>`, run in a checkout that has fetched the branch, tells them apart. A `409` is not retried with a re-derived value: go back to step 1. The merge call defaults to a merge commit, so pass the method the repository allows. Before merging, the checks `pre-merge-check` lists apply: base freshness, what the merge will close, and what the composed commit message will carry.

5. **Red, or a finding on a green: hand it back.** Comment the finding on the pull request (step 3's verdict names the failing job; a finding from review is a comment of its own) with the head SHA it was found at, then apply the label:

   ```bash
   gh api -X POST repos/{o}/{r}/issues/<n>/labels -f 'labels[]=handed-back'
   ```

   Do not flip the pull request to draft: `ready -> draft` is the author's signal, and the REST update ignores the field. Do not re-pick a head that has not moved; re-running it reproduces the same verdict. The author pushes, and the push is what returns the pull request to the queue: the `handed-back-label` skill's workflow removes the label on that push, so neither the gate nor the author removes it by hand. A push that leaves the pull request conflicted runs no workflow, so the label stays until the push that resolves the conflict. The label means a finding is outstanding; its absence does not mean the pull request is green.

6. **After a merge, refresh the gating checkout** (`git fetch origin`, then bring the primary to `origin/main` per the `worktrees` rule) before taking the next entry, so the next validation diffs against the base the merge produced.

**What stays with each repository.** This procedure fixes the order and the pins; it does not decide:

- **Whether the gate merges at all.** A repository whose maintainers merge by hand stops after step 3 or step 5. A standing grant to merge on green is the operator's to give, per repository, and a session without one asks.
- **The merge method**, and the settings behind it (`allow_squash_merge`, branch protection, required checks). Read them with `gh api repos/{o}/{r} --jq '[.allow_squash_merge, .allow_merge_commit, .allow_rebase_merge]'`. Changing any of them is outside this procedure.
- **Companion pull requests and merge order**, where a change spans repositories or a submodule: the repository's own rules say which lands first.
- **Which jobs run**, from `local-ci.json`, and any repository-specific health checks while a long run is in flight.
- **Whether the `handed-back` label and its workflow are adopted.** A repository without them records the finding in the comment and holds the pull request out of the queue another way.

## Repositories with their own runner

`uams-statamic`, `wordpress-importer` and `uamswp-migration-api` each predate the shared runner and keep a `scripts/local-ci.mjs` of their own, hand-maintained as a mirror of that repository's `ci.yml`. The shared runner now covers most of what they do, behind configuration (#58). What follows are the differences that remain, to be checked when a repository adopts the shared runner. Each is checked on that repository's adoption ticket: `UAMS-Web/uams-statamic#2835`, `UAMS-Web/wordpress-importer#1316` and `UAMS-Web/uamswp-migration-api#364`.

- **Slot bootstrap and reconciliation:** the shared runner's `--branch` (see *Validating a branch in a slot*) validates the remote head, and its `prepare` brings submodules, leftover files and Composer patches in line. The private runners also reconcile the slot with a local branch (fast-forwarding or refusing a diverged one), take `--fresh` and `--fetch`, and install dependencies into the slot before the jobs run; the shared runner leaves installing to the repository's bootstrap.
- **The test database.** The shared runner's per-job `database` (see *A job's own database*) covers what `uamswp-migration-api`'s runner does for its `pest` job, configured rather than built in. That runner sets `WP_TESTS_DB_NAME` to `uamswp_migration_api_ci` (`LOCAL_CI_DB_NAME` overrides it), or to `uamswp_migration_api_preflight` for an in-place `--preflight` run, so an in-place run and a slot run do not share a database; the shared runner has one name per job and lets the lock refuse the second of two runs instead. It takes `uamswp-migration-api-ci.database.<hash>.lock` at startup, the file `tests/Support/single-flight.php` takes, and tells the suite through `LOCAL_CI_SUITE_LOCK_HELD=1`; the equivalent declaration is `"lock": {"prefix": "uamswp-migration-api-ci", "heldEnv": "LOCAL_CI_SUITE_LOCK_HELD"}`. It also creates the database with the `mysql` client before the suite runs, which the shared runner leaves to the job's command. Its suite reclaims a lock record it cannot read, where the shared runner refuses one.
- **How the slot is prepared.** The shared runner's `prepare` (see *Preparing a reused slot*) covers the three steps these runners take before their jobs, configured rather than built in, with these differences. `uams-statamic` initializes `content/` and `users/` for its `pest` job only, shallow (`--depth 1`), authenticating with Composer's GitHub token where one is set, and clears files stranded in an uninitialized submodule directory; the shared runner initializes the listed submodules on every run with the user's own credentials and refuses stranded files rather than clearing them. `uamswp-migration-api` checks `includes/`, `config/`, `scripts/` and `tests/` for untracked files of the extensions its jobs read; the shared runner checks every untracked, unignored file under the listed paths. For Composer patches, `uams-statamic` stamps the lock's `_hash` and re-runs `composer install` after removing the patched packages, and `uamswp-migration-api` runs `composer reinstall` against a recorded copy of the lock, which is the approach the shared runner takes; both run `composer install` first, which the shared runner leaves to the repository's bootstrap.
- **The rest of the Pest job around the parallel run:** the shared runner now runs the parallel part and the serial tail (`jobs[].parallel`). `uams-statamic` also builds a route cache and a Stache seed before the suite, writes JUnit reports from each part for a skip-baseline check and a slowest-class check, keeps the run coverage-free with `-d pcov.enabled=0`, and deliberately excludes Pest's test-impact analysis from both parts.
- **The commit status around the merged state:** the shared runner posts the status (`--commit-status`, see *Posting a commit status*), under a configurable context that defaults to `local-ci`, on the commit it validated. `uams-statamic` validates the merged state and posts the status for the pull request head, and words the description for chain-gated jobs its own way.
- **The merge pipeline's repository-specific parts:** the shared skill ships the validate, comment, merge-or-hand-back order (see *Reviewing a pull request*); `uams-statamic` additionally keeps companion-pull-request ordering, a standing merge grant, a pull request compliance review before validation, and health checks while a run is in flight. The pull request watcher is no longer on this list: the shared skill ships it (see *Watching pull requests*), and each repository's own `scripts/watch-github.mjs` stays in place until that repository's adoption ticket retires it.

The mutation-tooling smoke `uams-statamic` runs is no longer a difference: `jobs[].mutation` fails a job whose output reports no mutations or a score below `minScore` (#53). Each of the three repositories decides, with its maintainers, whether to move to the shared runner, keep its own, or run the shared runner's `local-ci.json` beside its own for the jobs the two have in common. Until that decision is recorded on the adoption ticket, the repository's own runner is its gate and this skill's instructions about `local-ci.json` do not apply there.

