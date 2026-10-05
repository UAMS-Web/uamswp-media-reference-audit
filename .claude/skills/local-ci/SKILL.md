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
- **`local-ci.test.mjs`** ships beside the runner and syncs with the skill. Run the suite with `node --test .claude/skills/local-ci/local-ci.test.mjs`, or `node --test local-ci.test.mjs` from the skill directory. On Windows, two spots are platform-sensitive today: the pure `the template` test reads `templates/local-ci.json` through `cat` (not on a stock Windows PATH), and the Herd candidate path assertion builds a macOS-shaped path with Node's `path.join`, which uses backslashes when the suite itself runs on Windows.
- **`local-ci.json`** at the repository root is the repository's own job list. The sync never touches it.
- **`.githooks/pre-commit`** is the commit-time guard against secret and personal-data files, where the repository has one. It reads the same `guard.deny` list the runner checks.

The templates beside this file (`templates/local-ci.json`, `templates/pre-commit`, `templates/gate-workflow.md`) are the starting points for a repository adopting the runner.

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

1. Copy `.claude/skills/local-ci/templates/local-ci.json` to the repository root. The template is a WordPress plugin's configuration: a guard, `php -l` over every tracked PHP file, then Pint, Rector, PHPStan and Pest.
2. Set `php` to the version the repository deploys on (`"8.2"`), and `base` to the branch pull requests target (`main`, or `dev` where `dev` is promoted to `main`).
3. Delete the jobs for tools the repository does not have yet, and fix the commands of the ones it does: the `run` of each job is the command the repository's own `composer.json` scripts run.
4. Set `guard.deny` to the patterns no tracked file may match: key files, dumps, spreadsheets with personal data, `wp-config.php`, `.env`. The template's list is a WordPress plugin's and includes `\\.env(\\.|$)`, `\\.pem$` and `\\.key$` even when the repository never tracked those; keep a pattern only when the repository should refuse it. Patterns match case-insensitively everywhere the guard runs (the runner, the hook with node, and the hook's `grep -Ei` fallback), so `\\.sql` also refuses `DUMP.SQL` and `dump.Sql`. That is stricter than a migrated Actions step that used case-sensitive `grep -E`: a file that used to pass under a different casing now fails. The template's patterns avoid character classes because the hook's no-node fallback cannot read a pattern containing `]` and refuses every commit under one (see *The pre-commit guard*); keep any pattern added here free of `]` for the same reason. Its `\\.env(\\.|$)` also matches `.env.example`; where the repository tracks one, narrow that pattern (for example to `(^|/)\\.env$` plus the named variants it must refuse), or the guard fails on every run. Leave `guard` out entirely only when the repository has nothing of that kind, which is rare.
5. Run `node .claude/skills/local-ci/local-ci.mjs --dry-run` and read the plan: every job appears, selected or skipped, with its reason. Then run it for real and fix whatever fails.
6. Commit `local-ci.json`. It is repository-owned; the sync never overwrites it.

### The config, field by field

| Key | Meaning |
| --- | --- |
| `base` | The branch the diff is taken against. The runner diffs `HEAD` against its merge base with `origin/<base>`, so a base that moved under the branch does not pull its files into the diff. Default `main`. |
| `php` | The PHP version the jobs run under, `"8.2"` style. See [Toolchain](#toolchain). |
| `chain` | `true` stops at the first failure and reports the later jobs as skipped, naming the failing job. The default `false` runs every selected job and reports all of them, so one run surfaces everything rather than one failure per run. |
| `guard.deny` | Regular expressions (JavaScript syntax; the strings are JSON, so a backslash is doubled). When set, a `guard` job runs first on every run and fails when any tracked file matches, naming the files. The same list is what the pre-commit hook checks over staged files. The name `guard` is then reserved. |
| `jobs[].name` | Short and unique: letters, digits, `.`, `_`, `-`. It is what `--jobs` and the summary use. |
| `jobs[].run` | A shell command, run from the repository root. When its first word is `php`, or starts with `vendor/bin/`, it runs under the resolved PHP (`vendor/bin/pint --test` becomes `<php> vendor/bin/pint --test`), so the version the config names is the one that runs, and so Windows' `.bat` shims in `vendor/bin` are never spawned directly. Any other command (`composer validate`, `node --test "scripts/*.test.mjs"`) runs as written. The shell is `/bin/sh` on macOS and Linux and `cmd.exe` on Windows, so a command that must run everywhere avoids pipes and shell-specific syntax; `each` exists for the common case. |
| `jobs[].paths` | Globs over repository-relative paths that select the job when a changed file matches one. `*` and `?` stay inside one path segment, `**` crosses segments, `{a,b}` is a choice of literals, and a leading `!` excludes. `*.php` matches only at the root; `**/*.php` matches everywhere; `!vendor/**` keeps a committed `vendor/` out. |
| `jobs[].each` | Globs; the command runs once per tracked file that matches, with the file appended. This is how `php -l` runs over every PHP file without a shell pipeline. When `paths` is absent, the `each` globs select the job too. Output is shown only for the files that fail, with a count at the end. |
| `jobs[].always` | `true` selects the job on every run, whatever the diff. For a cheap job whose inputs are hard to name by path. |

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
```

The run prints `Validating: <sha> <subject>` first, then the changed-file count and the base it was diffed against, then one `==> JOB <name>: <reason>` line per job as it starts, then the summary. It runs in the tree it lives in: the copy in a worktree validates that worktree, and the shell's current directory does not matter.

**Run it on a clean tree.** When the tree has uncommitted changes the run warns at the start and the summary says `Tree: DIRTY`, because the verdict then describes the working tree and not the commit named on the `Validating:` line. The run is still useful while working; it is not a record of anything.

A deleted file counts as changed, so deleting a file other code requires selects the jobs whose `paths` match it; an `each` job skips a tracked file that is no longer on disk. When the branch and `origin/<base>` share no history (a shallow clone, typically), the changed files cannot be listed and the run refuses rather than selecting from an empty diff; `git fetch --unshallow`, or run `--all`.

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
- **The exit status is the contract.** It is `1` when any job that ran failed and `0` otherwise, and also `1` on a refusal (no config, no PHP, `--comment` on a dirty tree), where no job ran at all and there is no summary to read. The verdict line is derived from the same results by the same test, so the two cannot disagree, and the unit tests prove it in both directions.
- **A pipe throws the exit status away.** `$?` after `node local-ci.mjs | tail` is `tail`'s. Measured in `wordpress-importer` on macOS (Darwin 25.6.0) on 2026-09-05, reproducing a refusal: redirected to a file the status was `1`; piped to `tail` and read after the pipeline, `0`; the same pipeline under `set -o pipefail`, `1`. Set `pipefail`, or read the status with no pipe in between, and never derive a verdict from a wrapper's exit code without one of those. Read the summary as a cross-check, not as the contract.
- **Compare the `Commit:` line with the pull request head** before quoting a green. A run on an older commit, or on a dirty tree, describes something other than what merges.

## Adding a job

1. Add an entry to `jobs` in `local-ci.json`: a `name`, the `run` command the repository's `composer.json` or `package.json` already defines, and the `paths` whose change should select it. Name every input: the source the tool reads, its configuration file, and `composer.lock` for a tool whose rule set depends on installed versions (Rector, PHPStan).
2. If the tool runs per file with no option to take a directory, use `each` instead of a pipeline.
3. Run `--dry-run` with a change that should select the job and one that should not, and read the reasons.
4. Run `--jobs <name>` to see the job pass and, by breaking something, fail. A job that cannot fail is not a check.
5. Where the repository also has an Actions workflow, keep its job list the same as `local-ci.json`'s, so a reader of either sees the same gate.

A job with no `paths` and no `always` is reported `SKIPPED (not selected: ... runs only with --all or --jobs)`; that is for a job too slow to run by default, and the summary says so every time rather than letting it disappear.

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

## Repositories with their own runner

`uams-statamic`, `wordpress-importer` and `uamswp-migration-api` each predate the shared runner and keep a `scripts/local-ci.mjs` of their own, hand-maintained as a mirror of that repository's `ci.yml`. Those runners have capabilities the shared one deliberately does not:

- **Worktree slot checkout:** `--branch` checks the branch out into a runner-owned sibling worktree (detached at a resolved commit, reconciled with `origin/<branch>` first) and validates it there, so the primary checkout never moves and a validating session never has to switch branches.
- **Runner handoff:** after checkout the runner compares the branch's copy of itself with the executing one and re-executes the branch's copy, so a pull request that changes the job list is gated by its own list.
- **Single-flight lock:** a second run against the same worktree (and, in `uamswp-migration-api`, the same test database) is refused with the holder named, and a stale lock from a dead run is reclaimed.
- **Submodule init** of the private `content/` and `users/` submodules at the branch's recorded gitlink (`uams-statamic`), a reused-worktree residue check (`uamswp-migration-api`), and a Composer patch re-sync for a reused `vendor/` (`uams-statamic`, `uamswp-migration-api`).
- **Parallel Pest with a serial tail:** `pest --parallel --exclude-group=serial` followed by `pest --group=serial`, with a raised PHP memory limit for the workers (`uams-statamic`).
- **The mutation-tooling smoke:** one scoped `pest --mutate` run asserting a non-zero mutation count and a 100% score, so a broken coverage driver cannot pass as a silent no-op (`uams-statamic`).
- **A per-runner test database:** the `pest` job uses its own database so a run cannot drop the tables a hand-run suite is using (`uamswp-migration-api`).
- **A commit status** posted on the branch head (`--commit-status`, `uams-statamic`), beside the comment.
- **The PR watcher and merge pipeline:** `scripts/watch-github.mjs` reports pull request lifecycle events, and the skill's autonomous mode validates, comments and merges one pull request at a time (`uams-statamic`; the watcher also in `wordpress-importer` and `uamswp-migration-api`).

The shared runner selects jobs from a diff, runs them in place, and reports; that is the whole of it. Each of the three repositories decides, with its maintainers, whether to move to the shared runner, keep its own, or run the shared runner's `local-ci.json` beside its own for the jobs the two have in common. Until that decision is recorded on the adoption ticket, the repository's own runner is its gate and this skill's instructions about `local-ci.json` do not apply there.

