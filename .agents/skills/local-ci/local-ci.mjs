#!/usr/bin/env node
// cspell:ignore gitlink gitlinks
//
// local-ci.mjs: the shared local CI runner (UAMS-Web/uams-claude-skills#11).
//
// Runs a repository's checks on the developer's machine, with no Docker, no
// act, and no GitHub Actions minutes, and prints a verdict a reviewer can read.
// One file, no dependencies, Node 20 or newer, macOS, Linux and Windows.
//
// This file is delivered unchanged to every repository that carries the
// `local-ci` profile. NOTHING repository-specific lives in it: every job, path
// and version comes from `local-ci.json` at the repository root, which the
// repository owns and the sync never touches.
//
// WHAT A RUN DOES
//     1. Finds the repository root from this file's own location, so a copy in
//        a worktree validates that worktree's tree and nothing else.
//     2. Reads `local-ci.json`. In a runner-owned slot, runs the `prepare`
//        steps it names (see PREPARE) before anything else is read.
//     3. Lists the files changed on the branch: the diff of HEAD against its
//        merge base with `origin/<base>`, plus every uncommitted change.
//        Deleted files count as changed. With no merge base (a shallow clone)
//        the diff cannot be listed, and the run refuses unless --all or
//        --jobs makes the selection independent of it.
//     4. Selects jobs: the `guard` job (when `guard.deny` is set) always runs
//        first; a configured job runs when one of its `paths` globs matches a
//        changed file, when it has `always: true`, or when `--all` or `--jobs`
//        names it. Everything else is reported SKIPPED (not selected).
//     5. Runs the selected jobs through the shell, from the repository root.
//        `chain: true` stops at the first failure; the default runs every
//        selected job and reports all of them.
//     6. Prints the summary: the commit, each job with PASS / FAIL / SKIPPED
//        and its duration, and the verdict. Exits 1 when any job that ran
//        failed, 0 otherwise, 4 when another run holds the worktree (see ONE RUN AT A TIME), and 128 plus the signal number when a job is killed by SIGINT, SIGTERM or SIGHUP (POSIX only). The summary and the exit status are derived from
//        the same results, so they cannot disagree. A run in which no job ran
//        exits 0 with the verdict NOTHING RAN, never PASS.
//
// ONE RUN AT A TIME
//     Before step 2 (before the slot is prepared under --branch), the run takes
//     a lock on the worktree it executes in: a sibling directory
//     `<worktree>.lock` holding `holder.json` (pid, branch, worktree,
//     startedAt). A second run against a worktree a live run holds exits 4
//     before any job, naming the holder. A lock whose pid is gone is reclaimed
//     with a warning; a record that cannot be read is never reclaimed. The lock
//     is released on success, on failure, on an error, and when a job is
//     interrupted. --dry-run without --branch changes nothing and takes none.
//
// USAGE
//     node .claude/skills/local-ci/local-ci.mjs [options]
//
// OPTIONS
//     --all              Run every configured job, whatever the diff says.
//     --jobs <a,b>       Run only the named jobs (the guard still runs first).
//     --base <ref>       Diff against the merge base with origin/<ref> instead
//                        of the config's `base`.
//     --dry-run          Print the plan (which jobs would run and why) and exit.
//     --comment          After the run, post the summary as a comment on the
//                        open pull request for the current branch (through
//                        `gh`), naming the validated commit. Refused when the
//                        tree is dirty: the verdict would describe no commit.
//     --branch <name>    Validate origin/<name> in a runner-owned worktree slot
//                        instead of in place. The name must exist on origin
//                        (refused before anything is checked out); the slot is
//                        a sibling of the primary checkout named `<primary>-ci`,
//                        detached at the fetched remote head (a worktree there
//                        that the runner did not create is refused, never moved:
//                        pass --worktree-path), so the invoking
//                        checkout's HEAD, index and files never move. When the
//                        branch's copy of this runner differs from the executing
//                        one, that copy runs the whole chain in the slot (once:
//                        the child carries LOCAL_CI_HANDOFF, set to the parent's
//                        pid, and never hands off).
//                        --dry-run still prepares the slot, because the plan
//                        depends on the branch's config and diff.
//     --worktree-path <dir>  Place the slot elsewhere. Needs --branch.
//     --commit-status    Post the verdict as a commit status on the validated
//                        commit (POST repos/{owner}/{repo}/statuses/<sha> through
//                        gh): state `success` when the run exits 0 and `failure`
//                        when it exits 1, under the config's "statusContext"
//                        (default "local-ci"). It is opt-in, because a developer
//                        running the checks on their own branch should not write
//                        to GitHub. It is posted once, when the run is over: never
//                        `pending`, so a run that dies leaves no status behind
//                        (a required status then waits for one). A run in which
//                        nothing ran posts nothing. A status that cannot be posted
//                        warns and never changes the exit status. Refused on a
//                        dirty tree.
//     -h, --help         Show this help.
//
// CONFIG: `local-ci.json` at the repository root. A key the config does not
// define is refused, not ignored, except a top-level or job key beginning with
// an underscore (a note); SKILL.md lists the keys.
//     {
//       "base": "main",                     branch the diff is taken against
//       "php": "8.2",                       PHP version the jobs run under
//       "guard": {
//         "deny": ["sentinel-key\\.php$", "\\.sql(\\.gz)?$"]
//       },                                  regexes no tracked file may match
//       "chain": false,                     true: stop at the first failure
//       "statusContext": "local-ci",        the context --commit-status posts under
//       "jobs": [
//         { "name": "pint", "run": "vendor/bin/pint --test",
//           "paths": ["**/*.php", "pint.json"] },
//         { "name": "lint", "run": "php -l", "each": ["**/*.php"],
//           "always": true }
//       ]
//     }
//
//     name     unique; `guard` is reserved when `guard.deny` is set
//     run      a shell command, run from the repository root. When its first
//              word is `php` or starts with `vendor/bin/`, it runs under the
//              resolved PHP (see TOOLCHAIN) so the version the config names is
//              the version that runs, and so Windows' `.bat` shims in
//              vendor/bin are never spawned directly.
//     paths    globs over repository-relative paths. `*` and `?` stay inside
//              one path segment; `**` crosses segments; `{a,b}` is a choice of
//              literals; a leading `!` excludes. `*.php` matches only at the
//              root; `**/*.php` matches everywhere; `!vendor/**` keeps a
//              committed vendor/ out.
//     each     globs; the command runs once per tracked file that matches,
//              with the file appended (for `php -l`). When `paths` is absent
//              the `each` globs select the job too.
//     always   true: selected on every run.
//     requires the tooling the job needs, so a repository without it stays
//              green. Each entry is a repository-relative path that must exist
//              (`cspell.json`, `node_modules/.bin/cspell`) or `bin:<name>`, a
//              command that must be on PATH (`bin:python`). When any entry is
//              absent the job is SKIPPED, with the entry named, rather than
//              run and failed. A skip is never a pass: the summary says
//              `tooling absent`; a run whose selected jobs were all skipped this way
//              is NOTHING RAN, and a run where some passed and some were skipped
//              this way is `PASS (incomplete)`, never a bare PASS.
//     installs true: this job installs the repository's dependencies
//              (`composer install`). The run refuses before any job when a
//              selected job runs a `vendor/bin` tool and `vendor/autoload.php`
//              is absent; a selected `installs` job listed before the first
//              such job stands that refusal down, so a fresh slot reaches the
//              job that creates `vendor/`.
//     mutation `{"minScore": 100}`: the job's output is part of its verdict. The
//              command's output is read for Pest's `N Mutations for M Files
//              created` line and its `Score: N%` line, and the job fails when
//              the first line is absent, when either count is 0 (Pest exits 0
//              on `0 Mutations for 0 Files created`), when the score line is
//              absent, or when the score is below `minScore` (default 100).
//              Not combinable with `each`.
//     parallel `{"serialGroup": "serial", "memoryLimit": "4G"}`: runs the job as
//              two parts, for a Pest suite with tests that cannot run in
//              parallel. `run` must start with `vendor/bin/pest`. Part one is
//              `run --parallel --exclude-group=<serialGroup>`; part two, the
//              serial tail, is `run --group=<serialGroup>`. Both always run,
//              and the job fails when either fails, with the summary naming
//              which. `serialGroup` defaults to `serial`. `memoryLimit` (a
//              PHP size such as `4G`, or `-1`) is set as `-d memory_limit` on
//              both parts and forwarded to the parallel workers with
//              `--passthru-php`, because workers are their own PHP processes
//              and otherwise run at the system CLI limit. Not combinable with
//              `each` or `mutation`.
//     php      "8.2": this job runs on that PHP instead of the run's, for a
//              `php -l` over the shipped files under the supported floor while
//              the rest of the run uses the development PHP. `run` must start
//              with `php` or `vendor/bin/`. It resolves from
//              LOCAL_CI_PHP_<major><minor> (LOCAL_CI_PHP_82), Herd's pinned
//              binary, or php8.2 / php82 on PATH, never from plain `php`, and
//              the run fails before any job when that PHP is missing or reports
//              another version: a newer parser accepts what the floor rejects.
//
// PREPARE: bringing a reused slot in line with the commit (opt-in)
//     "prepare": {
//       "residue":    ["tests", "includes"],   refuse untracked files here
//       "submodules": ["content", "users"],    check these out at their gitlinks
//       "patches":    true                     re-sync vendor/ to patches.lock.json
//     }
//
//     Runs only in a slot the runner created (--branch, or a run started inside
//     that slot), before the tree is described and before any job. In any other
//     checkout the run says it did not prepare and carries on. --dry-run names
//     the steps without running them. In order:
//     residue     fails, naming each path, when a listed path holds a file that
//                 is neither tracked nor ignored. Nothing is deleted.
//     submodules  `git submodule update --init` for the listed paths only, then
//                 checks each is at the gitlink the commit records. A listed path
//                 that is not a submodule, or an uninitialized one that holds
//                 files, fails. Other submodules are left alone.
//     patches     when patches.lock.json differs from the copy recorded at
//                 vendor/.local-ci-patches.lock.json (or none is recorded),
//                 `composer reinstall` the installed packages either lock
//                 patches, then records the new lock, only after that succeeds.
//                 Nothing happens when vendor/ is not installed.
//
// DATABASE: a job's own test database (opt-in, per job)
//     "database": {
//       "name": "app_ci",                       the runner's own database
//       "env":  "WP_TESTS_DB_NAME",             the variable that carries it
//       "lock": { "prefix": "app-ci",           the suite's lock file prefix
//                 "heldEnv": "LOCAL_CI_SUITE_LOCK_HELD" }
//     }
//
//     The job runs with `name` in `env`, and with every other variable any
//     job's `database` names removed from its environment. A job without
//     `database` gets the runner's environment unchanged. With `lock`, the run
//     takes <tmpdir>/<prefix>.database.<16 hex of sha256(name)>.lock (JSON with
//     an integer `pid`, created exclusively) before any job runs, the file a
//     suite started by hand takes too, and exits 4 when a live process holds it
//     (naming the holder) or its record cannot be read (naming the file). The
//     record is linked into place whole, so the file is never seen empty.
//     `heldEnv` (optional) is set to "1" for the job, telling the suite its
//     parent holds the lock. The runner does not create the database.
//
// TOOLCHAIN
//     PHP resolves to, in order: `LOCAL_CI_PHP` (an absolute path); on macOS,
//     Herd's version-pinned binary for the config's `php` (`php82` for "8.2",
//     under ~/Library/Application Support/Herd/bin); then `php` on PATH. A
//     missing Herd pin falls through to PATH (with a version warning when it
//     differs from the config). It is resolved only when a selected job needs
//     it, so a repository with no PHP job runs on a machine with no PHP. A
//     version other than the config's warns; a missing PHP fails before any
//     job runs. --dry-run lists the search order and notes when a Herd pin is
//     absent so the plan is not read as a guarantee that pin will run. A job
//     that names its own `php` resolves it separately (see `php` above).
//
// EXPORTS
//     The pure functions (config parsing, glob matching, job selection, the
//     chain, summary formatting, exit-status derivation, the comment body) are
//     exported for `local-ci.test.mjs`. Importing this module runs nothing.

import { spawnSync } from 'node:child_process'
import { createHash } from 'node:crypto'
import { accessSync, constants, existsSync, linkSync, mkdirSync, readFileSync, readdirSync, realpathSync, renameSync, rmSync, statSync, writeFileSync, unlinkSync } from 'node:fs'
import { constants as osConstants, homedir, tmpdir } from 'node:os'
import { basename, delimiter, dirname, extname, join, posix, relative, resolve } from 'node:path'
import { fileURLToPath, pathToFileURL } from 'node:url'

const isWindows = process.platform === 'win32'
const CONFIG_FILE = 'local-ci.json'
const GUARD_JOB = 'guard'

// --- console ---------------------------------------------------------------

const useColor = process.stdout.isTTY && !process.env.NO_COLOR
const paint = (code, text) => (useColor ? `\x1b[${code}m${text}\x1b[0m` : text)
const cyan = (t) => paint('36', t)
const green = (t) => paint('32', t)
const red = (t) => paint('31', t)
const yellow = (t) => paint('33', t)

function warn(message) {
    console.log(yellow(`warning: ${message}`))
}

// --- arguments -------------------------------------------------------------

export function usage() {
    return `local-ci.mjs: run this repository's checks locally (no Actions minutes)

Usage:
  node .claude/skills/local-ci/local-ci.mjs [--all | --jobs <a,b>] [--base <ref>] [--dry-run] [--comment]
                                            [--commit-status] [--branch <name> [--worktree-path <dir>]]
  node .claude/skills/local-ci/local-ci.mjs --drop-patch-record

Options:
  --all           Run every job in local-ci.json, whatever the diff says.
  --jobs <a,b>    Run only the named jobs. The guard job still runs first.
  --base <ref>    Diff against the merge base with origin/<ref> (default: the
                  config's "base", then "main").
  --dry-run       Print which jobs would run, and why, without running them.
  --comment       Post the summary as a pull request comment through gh, naming
                  the validated commit. Refused when the tree is dirty.
  --commit-status Post the verdict as a commit status on the validated commit
                  through the REST API, under the config's "statusContext"
                  (default "local-ci"). Off unless given. Refused when the tree
                  is dirty.
  --branch <name> Validate origin/<name> in a runner-owned worktree slot, detached
                  at the remote head, and run the branch's own copy of this
                  runner there. This checkout is never moved. A name that is not
                  on origin is refused before anything is checked out.
  --worktree-path <dir>
                  Where the slot lives (default: a sibling of the primary
                  checkout, named <primary>-ci). Needs --branch.
  --drop-patch-record
                  Delete the patch record (vendor/.local-ci-patches.lock.json) in
                  the tree this runner lives in, then exit. A repository's install
                  script runs it before composer install, so an install that
                  dies after re-extracting a patched package cannot leave a record
                  that vouches for an unpatched vendor/. Takes no other option.
  -h, --help      Show this help.

Config: local-ci.json at the repository root. The header of this file documents
its format; .claude/skills/local-ci/templates/local-ci.json is a worked example.`
}

export function parseArgs(argv) {
    const opts = { all: false, jobs: null, base: null, dryRun: false, comment: false, commitStatus: false, branch: null, worktreePath: null, dropPatchRecord: false, help: false }
    const needsValue = (flag, value) => {
        if (value === undefined || value.startsWith('-')) {
            throw new Error(`Option ${flag} requires a value.`)
        }
        return value
    }
    for (let i = 0; i < argv.length; i++) {
        const arg = argv[i]
        switch (arg) {
            case '-h':
            case '--help':
                opts.help = true
                break
            case '--all':
                opts.all = true
                break
            case '--jobs':
            case '-j':
                opts.jobs = needsValue(arg, argv[++i]).split(',').map((j) => j.trim()).filter(Boolean)
                break
            case '--base':
                opts.base = needsValue(arg, argv[++i])
                break
            case '--dry-run':
                opts.dryRun = true
                break
            case '--comment':
                opts.comment = true
                break
            case '--commit-status':
                opts.commitStatus = true
                break
            case '--branch':
                opts.branch = needsValue(arg, argv[++i])
                break
            case '--worktree-path':
                opts.worktreePath = needsValue(arg, argv[++i])
                break
            case '--drop-patch-record':
                opts.dropPatchRecord = true
                break
            default:
                throw new Error(`Unknown option: ${arg} (see --help)`)
        }
    }
    if (opts.all && opts.jobs) {
        throw new Error('--all and --jobs exclude each other.')
    }
    if (opts.dropPatchRecord && (opts.all || opts.jobs || opts.base || opts.dryRun || opts.comment || opts.commitStatus || opts.branch || opts.worktreePath)) {
        throw new Error('--drop-patch-record only deletes the patch record; it takes no other option.')
    }
    if (opts.worktreePath && !opts.branch) {
        throw new Error('--worktree-path only places the slot --branch uses; pass --branch too.')
    }
    // `gh pr view <digits>` reads the argument as a pull request number, so a
    // branch named only with digits would comment on an unrelated pull request.
    if (opts.comment && opts.branch && /^\d+$/.test(opts.branch)) {
        throw new Error(`--comment cannot look up a branch named ${opts.branch}: gh reads a bare number as a pull request number.`)
    }
    return opts
}

// --- config ----------------------------------------------------------------

const asStringList = (value, what) => {
    if (value === undefined) {
        return []
    }
    if (!Array.isArray(value) || value.some((v) => typeof v !== 'string' || v === '')) {
        throw new Error(`${what} must be an array of non-empty strings.`)
    }
    return value
}

// Normalize and validate the parsed JSON. Throws on anything the runner could
// only guess about, so a typo in the config fails here with the key named
// rather than as a job that never runs.
const TOP_LEVEL_KEYS = ['base', 'php', 'chain', 'guard', 'jobs', 'prepare', 'statusContext', 'watch']
const JOB_KEYS = ['name', 'run', 'always', 'each', 'paths', 'requires', 'mutation', 'parallel', 'database', 'php', 'installs']
const GUARD_KEYS = ['deny']
const MUTATION_KEYS = ['minScore']
const PARALLEL_KEYS = ['serialGroup', 'memoryLimit']

// A key the config does not define is refused rather than ignored: a misspelled
// `database` would otherwise parse as "no database" and run the job against the
// repository's usual one. With `annotations`, a key beginning with an underscore
// is a note to the reader and is skipped; nothing else is (`$schema` and
// `comment` included, so `comment` cannot stand in for a mistyped real key).
function refuseUnknownKeys(value, allowed, where, annotations = false) {
    const unknown = Object.keys(value).filter((key) => !allowed.includes(key) && !(annotations && key.startsWith('_')))
    if (unknown.length > 0) {
        throw new Error(`${where} has no ${unknown.map((key) => JSON.stringify(key)).join(', ')}; it takes ${allowed.map((key) => `"${key}"`).join(', ')}.`)
    }
}

export function parseConfig(raw) {
    const config = typeof raw === 'string' ? JSON.parse(raw) : raw
    if (!config || typeof config !== 'object' || Array.isArray(config)) {
        throw new Error(`${CONFIG_FILE} must hold a JSON object.`)
    }
    refuseUnknownKeys(config, TOP_LEVEL_KEYS, 'the top level', true)

    const base = config.base === undefined ? 'main' : config.base
    if (typeof base !== 'string' || base === '') {
        throw new Error('"base" must be a non-empty string.')
    }
    const php = config.php === undefined ? null : config.php
    if (php !== null && !/^\d+\.\d+$/.test(String(php))) {
        throw new Error('"php" must be a version like "8.2".')
    }
    const chain = config.chain === undefined ? false : config.chain
    if (typeof chain !== 'boolean') {
        throw new Error('"chain" must be true or false.')
    }

    let deny = []
    if (config.guard !== undefined) {
        if (!config.guard || typeof config.guard !== 'object') {
            throw new Error('"guard" must be an object with a "deny" array.')
        }
        refuseUnknownKeys(config.guard, GUARD_KEYS, '"guard"')
        deny = asStringList(config.guard.deny, '"guard.deny"').map((pattern) => {
            try {
                // Case-insensitive: a guard protects file names, and DUMP.Sql is the same dump as dump.sql.
                return new RegExp(pattern, 'i')
            } catch (error) {
                throw new Error(`"guard.deny" pattern ${JSON.stringify(pattern)} is not a valid regular expression: ${error.message}`)
            }
        })
    }

    if (!Array.isArray(config.jobs)) {
        throw new Error('"jobs" must be an array.')
    }
    const names = new Set()
    const jobs = config.jobs.map((job, index) => {
        const where = `jobs[${index}]`
        if (!job || typeof job !== 'object') {
            throw new Error(`${where} must be an object.`)
        }
        refuseUnknownKeys(job, JOB_KEYS, typeof job.name === 'string' ? `${where} (${JSON.stringify(job.name)})` : where, true)
        if (typeof job.name !== 'string' || !/^[A-Za-z0-9._-]+$/.test(job.name)) {
            throw new Error(`${where}.name must be a short name (letters, digits, ".", "_" or "-").`)
        }
        if (names.has(job.name)) {
            throw new Error(`Job name ${JSON.stringify(job.name)} is used twice.`)
        }
        if (job.name === GUARD_JOB && deny.length > 0) {
            throw new Error(`Job name "${GUARD_JOB}" is reserved while "guard.deny" is set.`)
        }
        names.add(job.name)
        if (typeof job.run !== 'string' || job.run.trim() === '') {
            throw new Error(`${where}.run must be a non-empty shell command.`)
        }
        if (job.always !== undefined && typeof job.always !== 'boolean') {
            throw new Error(`${where}.always must be true or false.`)
        }
        const each = asStringList(job.each, `${where}.each`).map(normalizeGlob)
        const paths = (job.paths === undefined && each.length > 0 ? each : asStringList(job.paths, `${where}.paths`).map(normalizeGlob))
        const requires = asStringList(job.requires, `${where}.requires`).map((entry) => normalizeRequirement(entry, `${where}.requires`))
        const mutation = parseMutation(job.mutation, `${where}.mutation`)
        if (mutation && each.length > 0) {
            throw new Error(`${where}.mutation cannot be combined with "each"; a mutation run reports one summary.`)
        }
        const parallel = parseParallel(job.parallel, job.run, `${where}.parallel`)
        if (parallel && (each.length > 0 || mutation)) {
            throw new Error(`${where}.parallel cannot be combined with "each" or "mutation".`)
        }
        const database = parseDatabase(job.database, `${where}.database`)
        if (job.installs !== undefined && typeof job.installs !== 'boolean') {
            throw new Error(`${where}.installs must be true or false.`)
        }
        const jobPhp = job.php === undefined ? null : job.php
        if (jobPhp !== null && (typeof jobPhp !== 'string' || !/^\d+\.\d+$/.test(jobPhp))) {
            throw new Error(`${where}.php must be a version like "8.2".`)
        }
        if (jobPhp !== null && !needsPhp(job.run)) {
            throw new Error(`${where}.php needs "run" to start with php or vendor/bin/; nothing else runs under PHP.`)
        }
        return { name: job.name, run: job.run.trim(), paths, each, always: job.always === true, requires, mutation, parallel, database, php: jobPhp, installs: job.installs === true }
    })

    const statusContext = config.statusContext === undefined ? 'local-ci' : config.statusContext
    if (typeof statusContext !== 'string' || statusContext.trim() === '' || statusContext.length > 255 || /[\u0000-\u001f]/.test(statusContext)) {
        throw new Error('"statusContext" must be a non-empty string of at most 255 characters, such as "local-ci".')
    }

    return { base, php: php === null ? null : String(php), chain, deny, jobs, prepare: parsePrepare(config.prepare), statusContext: statusContext.trim() }
}

const PREPARE_KEYS = ['submodules', 'residue', 'patches']

// `prepare`: the steps that bring a reused slot in line with the commit before
// any job runs. Absent, or every step left out, is `null`: nothing is prepared.
function parsePrepare(value) {
    if (value === undefined) {
        return null
    }
    if (!value || typeof value !== 'object' || Array.isArray(value)) {
        throw new Error('"prepare" must be an object with "submodules", "residue" or "patches".')
    }
    const unknown = Object.keys(value).filter((key) => !PREPARE_KEYS.includes(key))
    if (unknown.length > 0) {
        throw new Error(`"prepare" has no ${unknown.map((key) => JSON.stringify(key)).join(', ')}; it takes ${PREPARE_KEYS.map((key) => `"${key}"`).join(', ')}.`)
    }
    const paths = (key) => asStringList(value[key], `"prepare.${key}"`).map((entry) => {
        const path = entry.replace(/\\/g, '/').replace(/^\.\//, '').replace(/\/+$/, '')
        if (path === '' || path === '.' || /^(\/|[A-Za-z]:)/.test(path) || path.split('/').includes('..')) {
            throw new Error(`"prepare.${key}" entry ${JSON.stringify(entry)} must be a path inside the repository.`)
        }
        return path
    })
    const submodules = paths('submodules')
    const residue = paths('residue')
    if (value.patches !== undefined && typeof value.patches !== 'boolean') {
        throw new Error('"prepare.patches" must be true or false.')
    }
    const patches = value.patches === true
    return submodules.length === 0 && residue.length === 0 && !patches ? null : { submodules, residue, patches }
}

// `parallel` splits a Pest job into a parallel run and a serial tail; see the
// header. Returns null when the job declares none.
const PEST_RUN = /^vendor[\\/]bin[\\/]pest(\s|$)/
const PHP_SIZE = /^(-1|[0-9]+[KMG]?)$/i
const GROUP_NAME = /^[A-Za-z0-9_.-]+$/
function parseParallel(value, run, where) {
    if (value === undefined) {
        return null
    }
    if (!value || typeof value !== 'object' || Array.isArray(value)) {
        throw new Error(`${where} must be an object such as {"serialGroup": "serial", "memoryLimit": "4G"}.`)
    }
    refuseUnknownKeys(value, PARALLEL_KEYS, where)
    if (!PEST_RUN.test(run.trim())) {
        throw new Error(`${where} needs "run" to start with vendor/bin/pest: it appends Pest's --parallel and --group options.`)
    }
    const serialGroup = value.serialGroup === undefined ? 'serial' : value.serialGroup
    if (typeof serialGroup !== 'string' || !GROUP_NAME.test(serialGroup)) {
        throw new Error(`${where}.serialGroup must be a Pest group name (letters, digits, ".", "_" or "-").`)
    }
    const memoryLimit = value.memoryLimit === undefined ? null : String(value.memoryLimit)
    if (memoryLimit !== null && !PHP_SIZE.test(memoryLimit)) {
        throw new Error(`${where}.memoryLimit must be a PHP size such as "4G", "512M" or "-1".`)
    }
    return { serialGroup, memoryLimit }
}

// The two commands a `parallel` job runs. The memory limit goes on the parent as
// `php -d memory_limit=` and to the workers through --passthru-php, which is the
// only way a paratest worker receives it.
export function parallelParts(run, parallel, php, platform = process.platform) {
    const base = parallel.memoryLimit === null ? run.trim() : `php -d memory_limit=${parallel.memoryLimit} ${run.trim()}`
    const passthru = parallel.memoryLimit === null ? '' : ` ${shellQuote(`--passthru-php=-d memory_limit=${parallel.memoryLimit}`, platform)}`
    return [
        { part: 'parallel', command: commandFor(`${base} --parallel --exclude-group=${parallel.serialGroup}${passthru}`, php, platform) },
        { part: 'serial', command: commandFor(`${base} --group=${parallel.serialGroup}`, php, platform) },
    ]
}

// `mutation` makes a job's output part of its verdict. Pest exits 0 on
// "0 Mutations for 0 Files created", so the exit status alone cannot tell a
// real mutation run from one that mutated nothing.
function parseMutation(value, where) {
    if (value === undefined) {
        return null
    }
    if (!value || typeof value !== 'object' || Array.isArray(value)) {
        throw new Error(`${where} must be an object such as {"minScore": 100}.`)
    }
    refuseUnknownKeys(value, MUTATION_KEYS, where)
    const minScore = value.minScore === undefined ? 100 : value.minScore
    if (typeof minScore !== 'number' || !Number.isFinite(minScore) || minScore < 0 || minScore > 100) {
        throw new Error(`${where}.minScore must be a number from 0 to 100.`)
    }
    return { minScore }
}

const DATABASE_KEYS = ['name', 'env', 'lock']
const ENV_NAME = /^[A-Za-z_][A-Za-z0-9_]*$/

// `database` gives a job a database of the runner's own, so a run cannot drop
// the tables of a suite somebody is running by hand against the repository's
// usual test database. `name` is set in the variable `env` names; `lock`, where
// the repository's suite takes a lock file of its own, makes the runner take the
// same file so the two interlock.
function parseDatabase(value, where) {
    if (value === undefined) {
        return null
    }
    if (!value || typeof value !== 'object' || Array.isArray(value)) {
        throw new Error(`${where} must be an object such as {"name": "app_ci", "env": "DB_DATABASE"}.`)
    }
    const unknown = Object.keys(value).filter((key) => !DATABASE_KEYS.includes(key))
    if (unknown.length > 0) {
        throw new Error(`${where} has no ${unknown.map((key) => JSON.stringify(key)).join(', ')}; it takes ${DATABASE_KEYS.map((key) => `"${key}"`).join(', ')}.`)
    }
    if (typeof value.name !== 'string' || !/^[A-Za-z0-9_][A-Za-z0-9_$-]{0,63}$/.test(value.name)) {
        throw new Error(`${where}.name must be a database name: letters, digits, "_", "$" or "-", at most 64 characters.`)
    }
    if (typeof value.env !== 'string' || !ENV_NAME.test(value.env)) {
        throw new Error(`${where}.env must name the environment variable that carries the database name to the job, such as "DB_DATABASE".`)
    }
    const lock = parseDatabaseLock(value.lock, `${where}.lock`)
    // Compared without case, since Windows environment names have none.
    for (const [key, name] of [['env', value.env], ['lock.heldEnv', lock?.heldEnv]]) {
        if (name && RESERVED_ENV.includes(name.toUpperCase())) {
            throw new Error(`${where}.${key} may not be ${name}: the runner sets or needs that variable for every job.`)
        }
    }
    if (lock?.heldEnv && lock.heldEnv.toUpperCase() === value.env.toUpperCase()) {
        throw new Error(`${where}.env and ${where}.lock.heldEnv name the same variable, ${value.env}; the job would get "1" as its database name.`)
    }
    return { name: value.name, env: value.env, lock }
}

// Variables a job's `database` may not name: the runner sets them for every job,
// or a job cannot run without them. (cspell:ignore SYSTEMROOT: the Windows variable.)
const RESERVED_ENV = ['PATH', 'PATHEXT', 'COMSPEC', 'SYSTEMROOT', 'HOME', 'TMPDIR', 'TEMP', 'TMP', 'LOCAL_CI', 'LOCAL_CI_PHP', 'LOCAL_CI_HANDOFF', 'LOCAL_CI_BRANCH']

function parseDatabaseLock(value, where) {
    if (value === undefined) {
        return null
    }
    if (!value || typeof value !== 'object' || Array.isArray(value)) {
        throw new Error(`${where} must be an object such as {"prefix": "app-ci", "heldEnv": "LOCAL_CI_SUITE_LOCK_HELD"}.`)
    }
    const unknown = Object.keys(value).filter((key) => key !== 'prefix' && key !== 'heldEnv')
    if (unknown.length > 0) {
        throw new Error(`${where} has no ${unknown.map((key) => JSON.stringify(key)).join(', ')}; it takes "prefix" and "heldEnv".`)
    }
    if (typeof value.prefix !== 'string' || !/^[A-Za-z0-9][A-Za-z0-9._-]*$/.test(value.prefix)) {
        throw new Error(`${where}.prefix must be the lock file prefix the repository's suite uses (letters, digits, ".", "_" or "-").`)
    }
    if (value.heldEnv !== undefined && (typeof value.heldEnv !== 'string' || !ENV_NAME.test(value.heldEnv))) {
        throw new Error(`${where}.heldEnv must name an environment variable.`)
    }
    return { prefix: value.prefix, heldEnv: value.heldEnv ?? null }
}

const ANSI_PATTERN = /\x1b\[[0-9;]*m/g

// Returns the reasons a mutation run's output does not count as a pass
// (empty when it does). Reads the Pest mutation summary line and score.
export function mutationProblems(output, minScore) {
    const text = String(output).replace(ANSI_PATTERN, '')
    const created = /(\d+)\s+Mutations?\s+for\s+(\d+)\s+Files?\s+created/i.exec(text)
    if (!created) {
        return ['the run did not report "N Mutations for M Files created", so nothing can be asserted about it']
    }
    const problems = []
    const mutations = Number(created[1])
    const files = Number(created[2])
    if (mutations === 0 || files === 0) {
        problems.push(`the run reported ${mutations} mutation${mutations === 1 ? '' : 's'} for ${files} file${files === 1 ? '' : 's'}: nothing was mutated`)
    }
    const score = /Score:\s*([\d.]+)%/i.exec(text)
    if (!score) {
        problems.push('the run did not report a "Score: N%" line')
    } else if (Number(score[1]) < minScore) {
        problems.push(`mutation score ${score[1]}% is below the configured minimum of ${minScore}%`)
    }
    return problems
}

const BIN_PREFIX = 'bin:'

// A `requires` entry is either `bin:<name>` (a command on PATH) or a path
// relative to the repository root. An absolute path or one that climbs out of
// the repository is refused: the config describes this repository's tooling, and
// a path outside it would pass on one machine and skip on the next.
function normalizeRequirement(entry, where) {
    if (entry.startsWith(BIN_PREFIX)) {
        const name = entry.slice(BIN_PREFIX.length)
        if (!/^[A-Za-z0-9._-]+$/.test(name)) {
            throw new Error(`${where} entry ${JSON.stringify(entry)}: "bin:" must be followed by a command name (letters, digits, ".", "_" or "-").`)
        }
        return entry
    }
    const path = entry.replace(/\\/g, '/').replace(/^\.\//, '')
    if (/^(\/|[A-Za-z]:)/.test(path) || path.split('/').includes('..')) {
        throw new Error(`${where} entry ${JSON.stringify(entry)} must be a path inside the repository (or "bin:<command>").`)
    }
    return path
}

// True when the run must stop before any job because a selected vendor/bin job
// would start with no vendor/autoload.php. A selected job marked `installs`
// that runs before the first vendor/bin job is the repository's own bootstrap,
// so the check stands down and that job's result carries the message. `selected`
// is in the order the jobs run.
export function vendorMissingFor(selected, vendorPresent) {
    if (vendorPresent) {
        return false
    }
    for (const entry of selected) {
        if (!entry.job) {
            continue
        }
        if (entry.job.installs) {
            return false
        }
        if (/^vendor[\\/]bin[\\/]/.test(entry.job.run)) {
            return true
        }
    }
    return false
}

// The `requires` entries that are not met, in the order written. `exists` and
// `hasBin` are the two questions the environment answers, injected so the
// function is pure and the tests can fake a machine without the tooling.
export function unmetRequirements(requires, { exists, hasBin }) {
    return requires.filter((entry) => (entry.startsWith(BIN_PREFIX) ? !hasBin(entry.slice(BIN_PREFIX.length)) : !exists(entry)))
}

export function loadConfig(repoRoot) {
    const file = join(repoRoot, CONFIG_FILE)
    if (!existsSync(file)) {
        throw new Error(
            `${CONFIG_FILE} not found at ${repoRoot}. Copy .claude/skills/local-ci/templates/${CONFIG_FILE} there and edit its jobs.`,
        )
    }
    try {
        return parseConfig(readFileSync(file, 'utf8'))
    } catch (error) {
        throw new Error(`${CONFIG_FILE}: ${error.message}`)
    }
}

// --- globs -----------------------------------------------------------------

function normalizeGlob(glob) {
    return glob.replace(/^(!?)\.\//, '$1').replace(/\\/g, '/')
}

const escapeRegExp = (text) => text.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')

export function globToRegExp(glob) {
    let out = ''
    for (let i = 0; i < glob.length; i++) {
        const c = glob[i]
        if (c === '*') {
            if (glob[i + 1] === '*') {
                if (glob[i + 2] === '/') {
                    out += '(?:.*/)?'
                    i += 2
                } else {
                    out += '.*'
                    i += 1
                }
            } else {
                out += '[^/]*'
            }
        } else if (c === '?') {
            out += '[^/]'
        } else if (c === '{') {
            const end = glob.indexOf('}', i)
            if (end === -1) {
                out += '\\{'
            } else {
                out += `(?:${glob.slice(i + 1, end).split(',').map(escapeRegExp).join('|')})`
                i = end
            }
        } else {
            out += escapeRegExp(c)
        }
    }
    return new RegExp(`^${out}$`)
}

export function matchesGlob(file, glob) {
    return globToRegExp(normalizeGlob(glob)).test(file.replace(/\\/g, '/'))
}

// The first positive glob the file matches, or null; a glob starting with `!`
// excludes, and one exclusion outranks every match (`**/*.php`, `!vendor/**`).
export function matchesAny(file, globs) {
    if (globs.some((glob) => glob.startsWith('!') && matchesGlob(file, glob.slice(1)))) {
        return null
    }
    return globs.find((glob) => !glob.startsWith('!') && matchesGlob(file, glob)) ?? null
}

// --- selection -------------------------------------------------------------

// Which configured jobs run, and why. Pure: the changed-file list is an input.
// Every job appears in the result exactly once, selected or skipped, so the
// summary can account for the whole config rather than only for what ran.
export function selectJobs(config, changedFiles, opts = {}) {
    const selected = []
    const skipped = []

    if (config.deny.length > 0) {
        selected.push({ name: GUARD_JOB, reason: 'guard.deny is set; the guard always runs first', guard: true })
    }

    if (opts.jobs) {
        const known = new Set(config.jobs.map((job) => job.name))
        const unknown = opts.jobs.filter((name) => !known.has(name) && name !== GUARD_JOB)
        if (unknown.length > 0) {
            throw new Error(`--jobs names ${unknown.join(', ')}, which ${CONFIG_FILE} does not define. Known: ${[...known].join(', ')}.`)
        }
    }

    for (const job of config.jobs) {
        let reason = null
        if (opts.jobs) {
            reason = opts.jobs.includes(job.name) ? 'named by --jobs' : null
            if (!reason) {
                skipped.push({ name: job.name, reason: 'not named by --jobs' })
                continue
            }
        } else if (opts.all) {
            reason = '--all'
        } else if (job.always) {
            reason = 'always: true'
        } else {
            for (const file of changedFiles) {
                const glob = matchesAny(file, job.paths)
                if (glob) {
                    reason = `${file} matches ${glob}`
                    break
                }
            }
        }

        if (reason) {
            // Selected, but the tooling it needs is not on this machine: report
            // it SKIPPED with the entry named. Never run it to fail, and never
            // let it read as a pass.
            const unmet = opts.unmet ? opts.unmet(job) : []
            if (unmet.length > 0) {
                skipped.push({ name: job.name, reason: `requires ${unmet.join(', ')}, which ${unmet.length === 1 ? 'is' : 'are'} absent`, absent: true })
            } else {
                selected.push({ name: job.name, reason, job })
            }
        } else {
            skipped.push({
                name: job.name,
                reason: job.paths.every((glob) => glob.startsWith('!'))
                    ? 'no paths and not always: true; runs only with --all or --jobs'
                    : `no changed file matches ${job.paths.join(', ')}`,
            })
        }
    }

    return { selected, skipped }
}

export function guardViolations(trackedFiles, deny) {
    return trackedFiles.filter((file) => deny.some((pattern) => pattern.test(file)))
}

// The files an `each` job runs on: tracked, matching its globs, and still on
// disk. A file deleted in the working tree but not yet staged is still tracked;
// running the command on it would fail for the deletion, not for its content.
export function eachTargets(trackedFiles, globs, exists) {
    return trackedFiles.filter((file) => matchesAny(file, globs) && exists(file))
}

// A file name as an argument: one that starts with `-` would be read as an
// option (`php -l -v.php` prints the version and exits 0 without linting), so
// it is passed as `./-v.php`.
export function fileArgument(file) {
    return file.startsWith('-') ? `./${file}` : file
}

// --- the chain -------------------------------------------------------------

// Run the selected jobs through `execute(entry) -> { code }` and return one
// result per configured job. Pure apart from `execute`, which the tests fake.
// Under `chain`, the first failure marks every later selected job SKIPPED with
// the failing job named; without it, every selected job runs.
export function runJobs(selection, { chain, execute, now = () => Date.now() }) {
    const results = []
    let brokenBy = null
    for (const entry of selection.selected) {
        if (brokenBy) {
            results.push({ name: entry.name, status: 'SKIPPED', reason: `chain: stopped after ${brokenBy} failed`, ms: 0 })
            continue
        }
        const started = now()
        const { code, failedParts } = execute(entry)
        const ms = now() - started
        if (code === 0) {
            results.push({ name: entry.name, status: 'PASS', ms, code })
        } else {
            results.push({ name: entry.name, status: 'FAIL', ms, code, ...(failedParts?.length ? { failedParts } : {}) })
            if (chain) {
                brokenBy = entry.name
            }
        }
    }
    for (const entry of selection.skipped) {
        results.push({ name: entry.name, status: 'SKIPPED', reason: `${entry.absent ? 'tooling absent' : 'not selected'}: ${entry.reason}`, ms: 0, ...(entry.absent ? { absent: true } : {}) })
    }
    return results
}

// --- verdict ---------------------------------------------------------------

// The exit status is the contract. A pipe throws it away (`$?` after a pipeline
// is the last command's), so the summary repeats the verdict in words, derived
// from the same results by the same test.
export function exitStatus(results) {
    return results.some((r) => r.status === 'FAIL') ? 1 : 0
}

export function verdictOf(results) {
    const ran = results.filter((r) => r.status !== 'SKIPPED')
    const absent = results.filter((r) => r.absent)
    const failed = ran.filter((r) => r.status === 'FAIL')
    if (exitStatus(results) !== 0) {
        return `FAIL: ${failed.length} of ${ran.length} job${ran.length === 1 ? '' : 's'} run failed: ${failed.map((r) => r.name).join(', ')}`
    }
    // Nothing checked is not a pass. The exit status stays 0 (a change no job
    // reads is not a failure), but no word here or in the comment says PASS.
    if (ran.length === 0) {
        return absent.length > 0
            ? `NOTHING RAN: ${absent.length} selected job${absent.length === 1 ? '' : 's'} skipped, tooling absent (${absent.map((r) => r.name).join(', ')}), so nothing was checked`
            : 'NOTHING RAN: no job was selected, so nothing was checked'
    }
    // A selected job that did not run for want of tooling is a check that never
    // happened: the verdict stays out of a bare PASS so it cannot be quoted as one.
    if (absent.length > 0) {
        return `PASS (incomplete): ${ran.length} of ${ran.length + absent.length} selected jobs ran and passed; ${absent.map((r) => r.name).join(', ')} skipped, tooling absent`
    }
    return `PASS: ${ran.length} of ${ran.length} job${ran.length === 1 ? '' : 's'} run passed`
}

// "; serial part failed" for a two-part job, so a red pest line says which half.
function partsNote(result) {
    return result.failedParts ? `; ${result.failedParts.join(' and ')} part failed` : ''
}

export function formatDuration(ms) {
    if (ms < 60_000) {
        return `${(ms / 1000).toFixed(1)}s`
    }
    const minutes = Math.floor(ms / 60_000)
    const seconds = Math.round((ms % 60_000) / 1000)
    return `${minutes}m ${String(seconds).padStart(2, '0')}s`
}

// `tree` is { sha, subject, dirty: { tracked, untracked } | null }.
export function formatSummary(tree, results, { color = false } = {}) {
    const tint = (fn, text) => (color ? fn(text) : text)
    const width = Math.max(8, ...results.map((r) => r.name.length))
    const lines = []
    lines.push('================ local-ci summary ================')
    lines.push(`  Commit:   ${tree.sha} ${tree.subject}`)
    if (tree.dirty) {
        lines.push(`  Tree:     DIRTY (${tree.dirty.tracked} tracked change${tree.dirty.tracked === 1 ? '' : 's'}, ${tree.dirty.untracked} untracked file${tree.dirty.untracked === 1 ? '' : 's'}): this verdict describes the working tree, not the commit`)
    } else {
        lines.push('  Tree:     clean: this verdict describes the commit')
    }
    lines.push('--------------------------------------------------')
    for (const r of results) {
        const name = r.name.padEnd(width)
        if (r.status === 'PASS') {
            lines.push(`  ${name}  ${tint(green, 'PASS   ')}  ${formatDuration(r.ms).padStart(7)}`)
        } else if (r.status === 'FAIL') {
            lines.push(`  ${name}  ${tint(red, 'FAIL   ')}  ${formatDuration(r.ms).padStart(7)}  (exit ${r.code}${partsNote(r)})`)
        } else {
            lines.push(`  ${name}  ${tint(yellow, 'SKIPPED')}           (${r.reason})`)
        }
    }
    lines.push('--------------------------------------------------')
    const verdict = verdictOf(results)
    lines.push(`  Verdict:  ${tint(exitStatus(results) !== 0 ? red : verdict.startsWith('PASS:') ? green : yellow, verdict)}`)
    lines.push('==================================================')
    return lines.join('\n')
}

// The pull request comment. It names the full SHA because the comment is the
// record a reviewer reads to know what was validated; a count alone ("8/8")
// reads as remote CI passing, and a verdict with no commit cannot be checked
// against the pull request head.
export function commentBody(tree, results, { platform = process.platform, when = new Date() } = {}) {
    const verdict = verdictOf(results)
    const ran = results.filter((r) => r.status !== 'SKIPPED')
    const skipped = results.filter((r) => r.status === 'SKIPPED')
    const lines = []
    const headline = exitStatus(results) !== 0 ? 'FAIL' : ran.length === 0 ? 'NOTHING RAN' : results.some((r) => r.absent) ? 'PASS (incomplete)' : 'PASS'
    lines.push(`**local-ci: ${headline}** on \`${tree.sha}\`: ${tree.subject}`)
    lines.push('')
    lines.push(verdict + '.')
    lines.push('')
    lines.push('| Job | Result | Duration |')
    lines.push('| --- | --- | --- |')
    for (const r of ran) {
        lines.push(`| \`${r.name}\` | ${r.status}${r.status === 'FAIL' ? ` (exit ${r.code}${partsNote(r)})` : ''} | ${formatDuration(r.ms)} |`)
    }
    if (skipped.length > 0) {
        lines.push('')
        lines.push(`Not run: ${skipped.map((r) => `\`${r.name}\` (${r.reason})`).join('; ')}.`)
    }
    lines.push('')
    lines.push(`Run locally by the shared local CI runner on ${platform}, ${when.toISOString()}. Where remote CI is gated off (\`RUN_REMOTE_CI\`), the skipped Actions jobs on this pull request are expected rather than a failure.`)
    return lines.join('\n')
}

// --- processes -------------------------------------------------------------

export function which(command, { path = process.env.PATH || '', pathext = process.env.PATHEXT } = {}) {
    const exts = isWindows ? (pathext || '.COM;.EXE;.BAT;.CMD').split(';').filter(Boolean) : ['']
    for (const dir of path.split(delimiter).filter(Boolean)) {
        for (const ext of exts) {
            const candidate = join(dir, command + ext)
            try {
                if (statSync(candidate).isFile()) {
                    return candidate
                }
            } catch {
                // not here
            }
        }
    }
    return null
}

// Existence is not the question: spawn needs something the OS will run. On
// POSIX that is the mode bit; on Windows the extension, read against PATHEXT.
export function isExecutable(candidate) {
    try {
        if (!statSync(candidate).isFile()) {
            return false
        }
    } catch {
        return false
    }
    if (isWindows) {
        const exts = (process.env.PATHEXT || '.COM;.EXE;.BAT;.CMD').split(';').map((e) => e.toLowerCase())
        return exts.includes(extname(candidate).toLowerCase())
    }
    try {
        accessSync(candidate, constants.X_OK)
        return true
    } catch {
        return false
    }
}

function git(repoRoot, args, { allowFailure = false } = {}) {
    // core.quotePath=false: a path with a non-ASCII character comes back as
    // itself, not as an octal-escaped string in quotes that no glob matches.
    const res = spawnSync('git', ['-C', repoRoot, '-c', 'core.quotePath=false', ...args], { encoding: 'utf8', stdio: ['ignore', 'pipe', 'pipe'] })
    if (res.error) {
        throw new Error(`git could not be started: ${res.error.message}`)
    }
    if (res.status !== 0) {
        if (allowFailure) {
            return null
        }
        throw new Error(`git ${args.join(' ')} failed (exit ${res.status}): ${(res.stderr || '').trim()}`)
    }
    return (res.stdout || '').replace(/\r\n/g, '\n').trimEnd()
}

// Paths from a `-z` git command. core.quotePath=false does not stop git from
// C-quoting a path that holds `"`, `\` or a control character (`"a\"b.sql"`);
// NUL-separated output is the only form that gives every path back as itself.
function gitPaths(repoRoot, args) {
    const res = spawnSync('git', ['-C', repoRoot, ...args], { encoding: 'utf8', stdio: ['ignore', 'pipe', 'pipe'], maxBuffer: Infinity })
    if (res.error) {
        throw new Error(`git could not be started: ${res.error.message}`)
    }
    if (res.status !== 0) {
        throw new Error(`git ${args.join(' ')} failed (exit ${res.status}): ${(res.stderr || '').trim()}`)
    }
    return (res.stdout || '').split('\0').filter(Boolean)
}

// `git status --porcelain -z --no-renames`: each entry is "XY <path>", and with
// renames off no entry carries a second path.
export function parseStatusZ(entries) {
    return entries.map((entry) => ({ code: entry.slice(0, 2), path: entry.slice(3) }))
}

// Quote one argument for the platform shell. The commands in `run` are the
// repository's own text and are passed through as written; this is for the
// pieces the runner adds (the PHP path, a file name).
export function shellQuote(arg, platform = process.platform) {
    if (platform === 'win32') {
        return /[\s"]/.test(arg) ? `"${arg.replace(/(\\*)"/g, '$1$1\\"').replace(/(\\*)$/, '$1$1')}"` : arg
    }
    return /^[A-Za-z0-9_\/.=:+,@%-]+$/.test(arg) ? arg : `'${arg.replace(/'/g, `'\\''`)}'`
}

// Does this command run under PHP? Its first word is `php`, or a vendor/bin tool.
export function needsPhp(run) {
    const first = run.trim().split(/\s+/)[0].replace(/\\/g, '/')
    return first === 'php' || first.startsWith('vendor/bin/')
}

// Rewrite `run` so the resolved PHP executes it: `php -l` -> `<php> -l`, and
// `vendor/bin/pint --test` -> `<php> vendor/bin/pint --test`. The vendor/bin
// entry is a PHP script; running it through the interpreter is what makes the
// pinned version the one that runs, and on Windows it avoids spawning the
// `.bat` shim, which Node refuses to do directly (CVE-2024-27980).
export function commandFor(run, php, platform = process.platform) {
    if (!needsPhp(run)) {
        return run
    }
    const trimmed = run.trim()
    const first = trimmed.split(/\s+/)[0]
    const rest = trimmed.slice(first.length)
    if (first === 'php') {
        return `${shellQuote(php, platform)}${rest}`
    }
    return `${shellQuote(php, platform)} ${first}${rest}`
}

// --- toolchain -------------------------------------------------------------

export function phpCandidates({ version, env = process.env, platform = process.platform, home = homedir() }) {
    const candidates = []
    if (env.LOCAL_CI_PHP) {
        candidates.push({ path: env.LOCAL_CI_PHP, source: '$LOCAL_CI_PHP' })
    }
    if (platform === 'darwin' && version) {
        const pinned = `php${version.replace('.', '')}`
        // POSIX join so a host on Windows still builds a darwin path when the
        // caller passes platform: 'darwin' (unit tests; cross-platform sims).
        candidates.push({
            path: posix.join(home, 'Library', 'Application Support', 'Herd', 'bin', pinned),
            source: `Herd ${pinned}`,
        })
    }
    return candidates
}

// A job's own PHP (`"php": "8.2"` on the job): the floor parser, where the
// run's PHP is the development one. Unlike the run's PHP there is no fall
// through to `php` on PATH: a newer parser accepts syntax the floor rejects,
// so a job that names a version runs on that version or fails. In order:
// LOCAL_CI_PHP_<major><minor> (an absolute path); Herd's version-pinned binary
// (macOS ~/Library/Application Support/Herd/bin/php82, Windows
// ~/.config/herd/bin/php82/php.exe); then php8.2 and php82 on PATH.
export function jobPhpCandidates({ version, env = process.env, platform = process.platform, home = homedir() }) {
    const digits = version.replace('.', '')
    const variable = `LOCAL_CI_PHP_${digits}`
    const candidates = []
    if (env[variable]) {
        candidates.push({ path: env[variable], source: `$${variable}` })
    }
    const pinned = `php${digits}`
    if (platform === 'darwin') {
        candidates.push({ path: posix.join(home, 'Library', 'Application Support', 'Herd', 'bin', pinned), source: `Herd ${pinned}` })
    } else if (platform === 'win32') {
        candidates.push({ path: join(home, '.config', 'herd', 'bin', pinned, 'php.exe'), source: `Herd ${pinned}` })
    }
    for (const name of [`php${version}`, pinned]) {
        candidates.push({ path: null, bin: name, source: `PATH ${name}` })
    }
    return candidates
}

function resolveJobPhp(version, jobs) {
    const variable = `LOCAL_CI_PHP_${version.replace('.', '')}`
    for (const candidate of jobPhpCandidates({ version })) {
        if (candidate.bin) {
            const found = which(candidate.bin)
            if (found) {
                return { path: found, source: candidate.source }
            }
            continue
        }
        if (existsSync(candidate.path) && isExecutable(candidate.path)) {
            return candidate
        }
        if (candidate.source === `$${variable}`) {
            throw new Error(`${variable} points at ${candidate.path}, which does not exist or cannot be executed.`)
        }
    }
    throw new Error(
        `PHP ${version} was not found, and ${jobs.map((name) => JSON.stringify(name)).join(', ')} run${jobs.length === 1 ? 's' : ''} only on it. Install PHP ${version} (Laravel Herd on macOS and Windows) or set ${variable} to its absolute path.`,
    )
}

function resolvePhp(config) {
    for (const [index, candidate] of phpCandidates({ version: config.php }).entries()) {
        if (!existsSync(candidate.path)) {
            // An explicit override that names nothing is a typo, not a hint.
            if (index === 0 && candidate.source === '$LOCAL_CI_PHP') {
                throw new Error(`LOCAL_CI_PHP points at ${candidate.path}, which does not exist.`)
            }
            continue
        }
        if (!isExecutable(candidate.path)) {
            if (index === 0 && candidate.source === '$LOCAL_CI_PHP') {
                throw new Error(`LOCAL_CI_PHP points at ${candidate.path}, which this OS cannot execute.`)
            }
            continue
        }
        return candidate
    }
    const onPath = which('php')
    if (onPath) {
        return { path: onPath, source: 'PATH' }
    }
    throw new Error(
        `PHP was not found. Install PHP${config.php ? ` ${config.php}` : ''} (Laravel Herd on macOS and Windows) or set LOCAL_CI_PHP to its absolute path.`,
    )
}

function phpVersion(php) {
    const res = spawnSync(commandFor('php -r "echo PHP_VERSION;"', php), { shell: true, encoding: 'utf8', stdio: ['ignore', 'pipe', 'pipe'] })
    const version = (res.stdout || '').trim()
    if (res.status !== 0 || version === '') {
        throw new Error(`PHP at ${php} did not report a version (exit ${res.status}). Run it directly to see why.`)
    }
    return version
}

// --- the tree --------------------------------------------------------------

export function repoRootOf(scriptPath) {
    const root = spawnSync('git', ['-C', dirname(scriptPath), 'rev-parse', '--show-toplevel'], { encoding: 'utf8' })
    if (root.status !== 0 || !root.stdout.trim()) {
        throw new Error(`${scriptPath} is not inside a git repository.`)
    }
    return root.stdout.trim()
}

export function describeTree(repoRoot) {
    const sha = git(repoRoot, ['rev-parse', 'HEAD'])
    const subject = git(repoRoot, ['log', '-1', '--pretty=%s'])
    const status = parseStatusZ(gitPaths(repoRoot, ['status', '--porcelain', '-z', '--no-renames', '--untracked-files=all']))
    const untracked = status.filter((entry) => entry.code === '??').length
    const tracked = status.length - untracked
    return { sha, subject, dirty: status.length > 0 ? { tracked, untracked } : null }
}

// Files changed on the branch: the diff against the merge base with
// origin/<base> (so a long-lived base moving under the branch does not pull its
// files into the diff), plus every uncommitted change, staged or not, and
// every untracked file. Paths are repository-relative with forward slashes.
// A deleted path is a changed path: removing a file other code requires breaks
// that code, so a deletion selects every job whose `paths` match it. Renames
// are read as a deletion plus an addition, so both names select.
//
// `mergeBase` is null when HEAD and the base share no history (a shallow clone,
// unrelated histories). The committed diff is then unknown, not empty, and the
// caller must refuse to select from it; see requireMergeBase.
//
// When neither origin/<base> nor <base> resolves (a single-branch clone of
// another branch), the diff cannot be listed at all. That throws, unless the
// caller's selection does not depend on the diff (`tolerateUnresolved`: --all,
// --jobs); the result is then an empty list with `unresolved` set, for
// requireMergeBase to turn into a warning.
export function changedFiles(repoRoot, base, { tolerateUnresolved = false } = {}) {
    const remoteBase = `origin/${base}`
    let ref = git(repoRoot, ['rev-parse', '--verify', '--quiet', remoteBase], { allowFailure: true })
    let baseName = remoteBase
    if (ref === null) {
        ref = git(repoRoot, ['rev-parse', '--verify', '--quiet', base], { allowFailure: true })
        baseName = base
        if (ref === null) {
            if (tolerateUnresolved) {
                return { files: [], against: remoteBase, mergeBase: null, unresolved: base }
            }
            throw new Error(`Neither ${remoteBase} nor ${base} resolves. Fetch origin, or pass --base <ref>, or run --all.`)
        }
        warn(`${remoteBase} does not exist; diffing against the local ${base} instead.`)
    }
    const mergeBase = git(repoRoot, ['merge-base', 'HEAD', ref], { allowFailure: true })
    const files = new Set()
    if (mergeBase !== null) {
        for (const file of gitPaths(repoRoot, ['diff', '--name-only', '-z', '--no-renames', mergeBase, 'HEAD'])) {
            files.add(file)
        }
    }
    for (const { path } of parseStatusZ(gitPaths(repoRoot, ['status', '--porcelain', '-z', '--no-renames', '--untracked-files=all']))) {
        files.add(path)
    }
    return { files: [...files].sort(), against: baseName, mergeBase }
}

// With no merge base the branch's committed changes cannot be listed, so a
// diff-selected run would skip every job they should have selected and report
// green. Refuse, unless the selection does not depend on the diff (--all,
// --jobs), in which case say that the diff was not read.
export function requireMergeBase(changed, opts) {
    if (changed.mergeBase !== null) {
        return null
    }
    if (changed.unresolved !== undefined) {
        return `Neither ${changed.against} nor ${changed.unresolved} resolves, so the branch's changes were not read. The selection does not depend on the diff (${opts.all ? '--all' : '--jobs'}), so the run proceeds.`
    }
    const why = `HEAD and ${changed.against} share no history (a shallow clone, or unrelated histories), so the branch's changes cannot be listed.`
    if (opts.all || opts.jobs) {
        return `${why} The selection does not depend on the diff (${opts.all ? '--all' : '--jobs'}), so the run proceeds.`
    }
    throw new Error(`${why} Selecting jobs from that would skip what the branch changed. Run \`git fetch --unshallow\` (or fetch ${changed.against}'s history), pass --base <ref>, or run --all.`)
}

// --- the branch slot (--branch) --------------------------------------------
//
// `--branch <name>` validates origin/<name> in a worktree the runner owns, so a
// session never switches the checkout it works in. The slot is detached at the
// remote head: no local branch ref is written, so the branch can stay checked
// out anywhere without a conflict, and nothing here touches the invoking
// checkout's HEAD, index or files (a fetch updates remote-tracking refs only).

// The handoff marker. A child the runner re-executes in the slot carries it,
// and a runner that sees it refuses --branch, so a handoff cannot recurse.
export const HANDOFF_ENV = 'LOCAL_CI_HANDOFF'

// The marker carries the parent runner's pid, and a runner is handed off only
// when that pid is its own parent. A value exported in a user's shell, or left
// in an environment a job inherited, names no parent of this process, so it
// neither skips the lock nor refuses --branch.
export function isHandedOff(env = process.env, ppid = process.ppid) {
    const marker = env[HANDOFF_ENV]
    return typeof marker === 'string' && /^[1-9][0-9]*$/.test(marker) && Number(marker) === ppid
}
// The branch under validation, passed to the child so `--comment` can find the
// pull request from a detached HEAD.
export const BRANCH_ENV = 'LOCAL_CI_BRANCH'

// The primary checkout: the directory that owns the repository's .git. The
// slot is derived from it, never from the invoking worktree, so a run started
// in any linked worktree resolves to the same one. A run started inside the
// slot itself is refused by prepareBranchSlot, since the slot cannot prepare
// the tree it is running from.
export function primaryRootOf(repoRoot) {
    const common = git(repoRoot, ['rev-parse', '--git-common-dir'])
    const dir = resolve(repoRoot, common)
    return basename(dir) === '.git' ? dirname(dir) : repoRoot
}

export function slotPathFor(primaryRoot) {
    return join(dirname(primaryRoot), `${basename(primaryRoot)}-ci`)
}

// A path with every symbolic link, junction and 8.3 spelling resolved, so two
// spellings of one place compare equal. `git worktree list` reports the resolved
// form (/private/tmp on macOS, where /tmp is a link), while a path the operator or a
// caller typed may not be. A path that does not exist yet is resolved through its
// nearest existing parent, and one with no existing parent is left as written.
export function canonicalPath(path) {
    const full = resolve(path)
    try {
        return realpathSync.native(full)
    } catch {
        try {
            return join(realpathSync.native(dirname(full)), basename(full))
        } catch {
            return full
        }
    }
}

export const samePath = (a, b) => {
    const [x, y] = [canonicalPath(a), canonicalPath(b)]
    return isWindows ? x.toLowerCase() === y.toLowerCase() : x === y
}

// The pre-commit guard only runs in a clone whose `core.hooksPath` points at
// `.githooks`. Git does not run a hook from inside the repository until told to,
// so a tracked hook in a clone without the setting guards nothing.
export const HOOK_FILE = '.githooks/pre-commit'
export const HOOKS_COMMAND = 'git config core.hooksPath .githooks'

// What the clone says about the hook: whether `.githooks/pre-commit` is tracked,
// and the `core.hooksPath` git resolves for it (null when unset). `git config`
// run from a linked worktree reads the shared config, so a worktree answers the
// same as the clone it belongs to.
export function readHooksState(repoRoot) {
    const tracked = git(repoRoot, ['ls-files', '--', HOOK_FILE], { allowFailure: true })
    const hooksPath = git(repoRoot, ['config', '--get', 'core.hooksPath'], { allowFailure: true })

    return { hookTracked: Boolean(tracked), hooksPath: hooksPath === null || hooksPath === '' ? null : hooksPath }
}

// The one warning line for a clone whose guard is not wired, or null. A relative
// `core.hooksPath` is resolved from the working tree root, where git runs hooks
// from, so `.githooks`, `./.githooks` and `.githooks/` all count. Never a failure:
// the caller prints it and carries on, so the verdict and the exit status are
// those of the same run with the setting in place. Silent under GitHub Actions,
// where nobody commits from the checkout.
export function hooksPathWarning(repoRoot, { hookTracked, hooksPath }, env = process.env) {
    if (!hookTracked || env.GITHUB_ACTIONS) {
        return null
    }
    if (hooksPath !== null && samePath(resolve(repoRoot, hooksPath), join(repoRoot, '.githooks'))) {
        return null
    }
    const current = hooksPath === null ? 'core.hooksPath is not set' : `core.hooksPath is ${hooksPath}`

    return `${HOOK_FILE} is tracked but ${current}, so the pre-commit guard does not run in this clone. Run once: ${HOOKS_COMMAND}`
}

// The registered worktree paths, from `git worktree list --porcelain`.
function worktreePaths(repoRoot) {
    return git(repoRoot, ['worktree', 'list', '--porcelain'])
        .split('\n')
        .filter((line) => line.startsWith('worktree '))
        .map((line) => line.slice('worktree '.length))
}

// Remove the options a re-executed child must not see: it runs in place in the
// slot, so --branch and --worktree-path would only make it prepare a slot again.
export function stripSlotOptions(argv) {
    const out = []
    for (let i = 0; i < argv.length; i++) {
        if (argv[i] === '--branch' || argv[i] === '--worktree-path') {
            i += 1
        } else {
            out.push(argv[i])
        }
    }
    return out
}

// Is the branch's copy of the runner the executing one? Compared as text with
// line endings normalized, so a checkout that only differs by CRLF does not
// hand off to an identical copy.
export function sameRunner(executingText, branchText) {
    return executingText.replace(/\r\n/g, '\n') === branchText.replace(/\r\n/g, '\n')
}

// The file the runner writes into a slot's own git directory when it creates the
// slot. A worktree without it was not made by the runner, so it is never moved:
// a standing checkout at the default path (the gate's own, for one) would
// otherwise be detached under whatever run is in progress there.
export const SLOT_MARKER = 'local-ci-slot'

const slotMarkerPath = (slot) => join(git(slot, ['rev-parse', '--absolute-git-dir']), SLOT_MARKER)

// Resolve origin/<name> and bring the slot to it. Returns { path, sha }.
// Every refusal happens before the slot is created or moved.
export function prepareBranchSlot(repoRoot, branch, { worktreePath = null, log = () => {} } = {}) {
    const refCheck = spawnSync('git', ['check-ref-format', `refs/heads/${branch}`], { encoding: 'utf8' })
    if (refCheck.status !== 0) {
        throw new Error(`--branch ${JSON.stringify(branch)} is not a valid branch name.`)
    }
    const primary = primaryRootOf(repoRoot)
    const slot = resolve(worktreePath ?? slotPathFor(primary))
    if (samePath(slot, primary) || samePath(slot, repoRoot)) {
        throw new Error(`The slot would be ${slot}, which is the checkout this run was started from. Pass --worktree-path to place it elsewhere.`)
    }

    // On the remote at all? Nothing is written until this answers yes.
    const remote = spawnSync('git', ['-C', repoRoot, 'ls-remote', '--exit-code', '--heads', 'origin', `refs/heads/${branch}`], { encoding: 'utf8' })
    if (remote.status === 2) {
        throw new Error(`origin has no branch ${branch}. Push it first: the slot validates the remote head, never a local ref.`)
    }
    if (remote.status !== 0) {
        throw new Error(`Could not ask origin about ${branch} (git exit ${remote.status}): ${(remote.stderr || '').trim().split('\n')[0]}`)
    }
    git(repoRoot, ['fetch', '--quiet', 'origin', `+refs/heads/${branch}:refs/remotes/origin/${branch}`])
    const sha = git(repoRoot, ['rev-parse', '--verify', `refs/remotes/origin/${branch}^{commit}`])

    const registered = worktreePaths(repoRoot).some((p) => samePath(p, slot))
    if (registered && existsSync(slot)) {
        if (!existsSync(slotMarkerPath(slot))) {
            throw new Error(`${slot} is a worktree of this repository that the runner did not create, so it will not be moved. Pass --worktree-path to place the slot elsewhere.`)
        }
        // Submodules are left out: the checkout below does not move them, so a
        // submodule behind a gitlink an earlier commit moved is not a hand edit,
        // and "prepare.submodules" is what brings one in line.
        const dirty = git(slot, ['status', '--porcelain', '--untracked-files=no', '--ignore-submodules=all'])
        if (dirty !== '') {
            throw new Error(`The slot ${slot} has uncommitted changes to tracked files; a runner-owned tree is never hand-edited. Inspect it, then clean it or remove it with \`git worktree remove --force\`.\n${dirty}`)
        }
        git(slot, ['checkout', '--quiet', '--detach', '--no-recurse-submodules', sha])
        log(`Slot:       ${slot} (reused)`)
    } else {
        if (registered) {
            git(repoRoot, ['worktree', 'prune'])
        } else if (existsSync(slot)) {
            throw new Error(`${slot} exists and is not a worktree of this repository, so the runner will not use it. Move it, or pass --worktree-path.`)
        }
        git(repoRoot, ['worktree', 'add', '--quiet', '--detach', slot, sha])
        writeFileSync(slotMarkerPath(slot), `created by local-ci.mjs --branch
`)
        log(`Slot:       ${slot} (created)`)
    }
    return { path: slot, sha }
}

// --- preparing a reused slot (`prepare`) -------------------------------------
//
// A slot is reused from run to run, so it can carry what an earlier commit left
// there: untracked files a job wrote into the tree, submodules checked out at an
// older gitlink (the slot is moved with --no-recurse-submodules), and a vendor/
// patched from an older patches.lock.json. `composer install` reports nothing to
// do when composer.lock pins the same versions, and cweagans/composer-patches
// patches a package only when it is installed, so a patch a branch adds, changes
// or removes never reaches a reused vendor/ on its own.
//
// Each step is opt-in through `prepare` in local-ci.json, and the steps run only
// in a slot the runner created (its git directory carries SLOT_MARKER). A
// developer's own checkout is never prepared: its untracked files are work in
// progress, its submodules may hold that work, and a reinstall there is an
// install in somebody's checkout. Order: residue (refuse before changing
// anything), submodules, then patches.

// Is `repoRoot` a slot the runner created?
export function isRunnerSlot(repoRoot) {
    return existsSync(slotMarkerPath(repoRoot))
}

// The untracked paths under the configured residue paths, from
// `git status --porcelain -z --untracked-files=all -- <paths>`. Ignored files
// are not listed, so a cache the repository ignores is never residue.
export function residuePaths(statusEntries) {
    return parseStatusZ(statusEntries).filter((entry) => entry.code === '??').map((entry) => entry.path).sort()
}

// The package names a patches.lock.json patches, or none when it is absent.
// Throws, naming `label`, when the text is not a lock: reading it as "no
// patches" would record it and report vendor/ as matching.
function patchedPackages(lockText, label) {
    if (lockText === null) {
        return []
    }
    let parsed
    try {
        parsed = JSON.parse(lockText)
    } catch (error) {
        throw new Error(`${label} is not valid JSON (${error.message}).`)
    }
    const patches = parsed?.patches ?? {}
    if (typeof patches !== 'object' || Array.isArray(patches)) {
        throw new Error(`${label} has a "patches" that is not an object.`)
    }
    return Object.keys(patches)
}

// The installed package names, from vendor/composer/installed.json (Composer 2
// writes {"packages": [...]}, Composer 1 a bare array).
export function installedPackages(installedJsonText) {
    let parsed
    try {
        parsed = JSON.parse(installedJsonText)
    } catch (error) {
        throw new Error(`vendor/composer/installed.json is not valid JSON (${error.message}); run the repository's composer install in the slot.`)
    }
    const list = Array.isArray(parsed) ? parsed : parsed?.packages
    if (!Array.isArray(list)) {
        throw new Error('vendor/composer/installed.json lists no packages.')
    }
    return new Set(list.map((pkg) => pkg?.name).filter((name) => typeof name === 'string'))
}

// Which installed packages to reinstall so vendor/ is patched as the commit's
// patches.lock.json says. Nothing when the lock matches the one recorded at the
// last install. No record at all counts as a difference, since nothing says
// what vendor/ carries. The union of both locks takes in a package whose patch
// was removed, which a fresh extract then drops. A package that is not
// installed is skipped: there is nothing to reinstall. A commit lock that is
// not a lock throws; a record that is not one counts as no record, so every
// package the commit's lock patches is reinstalled.
export function patchReinstallPackages(currentLock, recordedLock, installed) {
    const current = patchedPackages(currentLock, 'patches.lock.json')
    let recorded
    try {
        recorded = patchedPackages(recordedLock, PATCH_LOCK_RECORD)
    } catch {
        recordedLock = null
        recorded = []
    }
    // Line endings are not content: a checkout with core.autocrlf rewrites them.
    const normalize = (text) => (text === null ? null : text.replace(/\r\n/g, '\n'))
    if (normalize(currentLock) === normalize(recordedLock)) {
        return []
    }
    const names = new Set([...current, ...recorded])
    return [...names].filter((name) => installed.has(name)).sort()
}

// Where the slot records the patches.lock.json its vendor/ was last brought in
// line with. Inside vendor/, so a removed vendor/ forgets it with the packages.
export const PATCH_LOCK_RECORD = 'vendor/.local-ci-patches.lock.json'

// Bring vendor/ to the commit's patches.lock.json, then record that lock: in
// that order, and only once the reinstall has succeeded, since a record written
// first would describe patches vendor/ does not carry and the next run would
// trust it. The effects are passed in so the order is testable without Composer.
export function syncPatchedVendor({ currentLock, recordedLock, installed, reinstall, writeRecord, removeRecord }) {
    const packages = patchReinstallPackages(currentLock, recordedLock, installed)
    if (packages.length > 0) {
        const status = reinstall(packages)
        if (status !== 0) {
            throw new Error(`composer reinstall ${packages.join(' ')} failed (exit ${status}); vendor/ may not carry the patches patches.lock.json declares, and nothing was recorded. Read the output above.`)
        }
    }
    if (currentLock === null) {
        removeRecord()
    } else {
        writeRecord(currentLock)
    }
    return packages
}

// Delete the patch record, and report whether there was one. A repository's own
// install script calls this before `composer install` (as `--drop-patch-record`
// from a shell, or by import). syncPatchedVendor writes the record only after
// its reinstall, which covers that reinstall and nothing before it: an install
// that re-extracts a patched package and dies before cweagans/composer-patches
// patches it leaves the old record standing, the next install finds the package
// at the locked version and does nothing, the record equals patches.lock.json,
// and the run proceeds over an unpatched package. With the record gone first, a
// failed install leaves none, so the next run reinstalls every patched package.
export function dropPatchRecord(repoRoot) {
    const path = join(repoRoot, PATCH_LOCK_RECORD)
    const existed = existsSync(path)
    rmSync(path, { force: true })
    return existed
}

const readOrNull = (path) => (existsSync(path) ? readFileSync(path, 'utf8') : null)

// Run the configured steps in the slot at `repoRoot`. Throws, naming what to
// do, when a step refuses or fails; every refusal comes before any job runs.
export function prepareSlot(repoRoot, prepare, { log = () => {}, composer = () => which('composer') } = {}) {
    if (prepare.residue.length > 0) {
        const found = residuePaths(gitPaths(repoRoot, ['status', '--porcelain', '-z', '--no-renames', '--untracked-files=all', '--ignore-submodules=all', '--', ...prepare.residue]))
        if (found.length > 0) {
            throw new Error(
                `the slot holds ${found.length} untracked file${found.length === 1 ? '' : 's'} under ${prepare.residue.join(', ')} that the commit does not track, and the jobs would read ${found.length === 1 ? 'it' : 'them'}:\n${found.map((path) => `  ${path}`).join('\n')}\nThey are left from an earlier run or written by hand. Delete them, or remove the slot with \`git worktree remove --force\` so the next run recreates it.`,
            )
        }
        log(`Residue:    none under ${prepare.residue.join(', ')}`)
    }

    if (prepare.submodules.length > 0) {
        const head = git(repoRoot, ['rev-parse', 'HEAD'])
        const gitlinks = new Map()
        for (const path of prepare.submodules) {
            const entry = git(repoRoot, ['ls-tree', 'HEAD', '--', path])
            const match = /^160000 commit ([0-9a-f]+)\t/.exec(entry)
            if (!match) {
                throw new Error(`"prepare.submodules" names ${path}, which is not a submodule in ${head.slice(0, 9)}.`)
            }
            gitlinks.set(path, match[1])
            // An uninitialized submodule path is a plain directory, and a job can
            // write into it; `git submodule update --init` then refuses the clone.
            // Refuse first and name what is there, rather than delete it.
            const abs = join(repoRoot, path)
            if (existsSync(abs) && !existsSync(join(abs, '.git'))) {
                const stranded = readdirSync(abs)
                if (stranded.length > 0) {
                    throw new Error(`${path} is not initialized but holds ${stranded.length} ${stranded.length === 1 ? 'entry' : 'entries'}, so the submodule cannot be checked out there:\n${stranded.sort().map((name) => `  ${path}/${name}`).join('\n')}\nDelete ${stranded.length === 1 ? 'it' : 'them'}, or remove the slot so the next run recreates it.`)
                }
            }
        }
        const update = spawnSync('git', ['-C', repoRoot, 'submodule', 'update', '--init', '--', ...prepare.submodules], { encoding: 'utf8', stdio: ['ignore', 'pipe', 'pipe'] })
        if (update.error || update.status !== 0) {
            throw new Error(`git submodule update --init -- ${prepare.submodules.join(' ')} failed (exit ${update.status}): ${((update.stderr || update.error?.message || '').trim())}\nWhen the commit changed a submodule's url in .gitmodules, the slot's submodule still fetches from the url it was initialized with: run \`git -C "${repoRoot}" submodule sync -- ${prepare.submodules.join(' ')}\`, which also writes the new url to the repository's shared git config, then run again. When git names local changes inside a submodule, they were left by an earlier run or by hand: discard them in the slot's submodule, or remove the slot so the next run recreates it.`)
        }
        for (const [path, want] of gitlinks) {
            const have = git(join(repoRoot, path), ['rev-parse', 'HEAD'])
            if (have !== want) {
                throw new Error(`${path} is at ${have.slice(0, 9)} after the update, but ${head.slice(0, 9)} records ${want.slice(0, 9)}.`)
            }
            log(`Submodule:  ${path} at ${want.slice(0, 9)}`)
        }
    }

    if (prepare.patches) {
        const installedJson = readOrNull(join(repoRoot, 'vendor', 'composer', 'installed.json'))
        if (installedJson === null) {
            log('Patches:    vendor/ is not installed, so there is nothing to bring in line')
        } else {
            let tool = null
            const currentLock = readOrNull(join(repoRoot, 'patches.lock.json'))
            syncPatchedVendor({
                currentLock,
                recordedLock: readOrNull(join(repoRoot, PATCH_LOCK_RECORD)),
                installed: installedPackages(installedJson),
                reinstall: (packages) => {
                    tool = composer()
                    if (!tool) {
                        throw new Error(`patches.lock.json differs from the one vendor/ was last brought in line with, and composer was not found on PATH to reinstall ${packages.join(' ')}.`)
                    }
                    log(`Patches:    patches.lock.json differs from the last install; reinstalling ${packages.join(' ')}`)
                    const command = ['composer', 'reinstall', ...packages, '--no-interaction', '--no-progress'].map((arg) => shellQuote(arg)).join(' ')
                    console.log(`  $ ${command}`)
                    return runShell(command, repoRoot, process.env).code
                },
                writeRecord: (text) => writeFileSync(join(repoRoot, PATCH_LOCK_RECORD), text),
                removeRecord: () => rmSync(join(repoRoot, PATCH_LOCK_RECORD), { force: true }),
            })
            if (tool === null) {
                log(currentLock === null ? 'Patches:    the commit has no patches.lock.json' : 'Patches:    vendor/ matches patches.lock.json')
            }
        }
    }
}

// --- single-flight lock ----------------------------------------------------
//
// Two runs in one worktree take the tree out from under each other: the second
// checks a branch out beneath the first's jobs, or a job rewrites a cache the
// other is reading, and both report failures that no diff explains. The lock is
// keyed on the worktree a run executes in (the slot under --branch), so a second
// run is refused before it touches anything.
//
// It is a sibling directory, `<worktree>.lock`, never a file inside the tree: a
// checkout or a clean of the tree must not be able to delete the lock a live run
// is holding. mkdir is the atomic step (EEXIST means held). `holder.json` inside
// it names the run.

export const LOCK_REFUSED_EXIT = 4
const HOLDER_FILE = 'holder.json'

// The signals that mean the operator stopped the run, and the exit status a
// shell reports for each (128 plus the signal number).
const INTERRUPT_SIGNALS = ['SIGINT', 'SIGTERM', 'SIGHUP']
export const interruptExitCode = (signal) => (INTERRUPT_SIGNALS.includes(signal) ? 128 + osConstants.signals[signal] : null)

// The lock directory for a worktree. The parent is resolved through realpath and,
// on Windows, the name is lowercased, so two spellings of one path share a lock.
export function lockPathFor(worktreePath) {
    // A worktree that exists is resolved whole, so a symlinked or 8.3 leaf shares
    // the lock of its real path.
    const full = canonicalPath(worktreePath)
    const parent = dirname(full)
    const name = isWindows || process.platform === 'darwin' ? basename(full).toLowerCase() : basename(full)
    return join(parent, `${name}.lock`)
}

export function pidIsAlive(pid) {
    try {
        process.kill(pid, 0)
        return true
    } catch (error) {
        // EPERM: the process exists and belongs to someone else.
        return error.code === 'EPERM'
    }
}

// Who holds a lock whose directory exists, from the text of its holder file.
// Fails closed: a missing, empty, malformed or pid-less record is `held`, so a
// run that is between mkdir and writing its record, or a record this runner
// cannot read, is never reclaimed. Only a readable record whose pid is dead is
// `stale`.
export function holderDisposition(raw, isAlive) {
    let holder = null
    try {
        holder = JSON.parse(raw)
    } catch {
        // Falls through to the unreadable verdict.
    }
    if (holder === null || typeof holder !== 'object' || !Number.isInteger(holder.pid) || holder.pid <= 0) {
        return { state: 'held', holder: null }
    }
    return { state: isAlive(holder.pid) ? 'held' : 'stale', holder }
}

export function lockRefusal({ worktreePath, lockPath, holder }) {
    if (holder === null) {
        return `${worktreePath} is locked by a run whose record cannot be read (${join(lockPath, HOLDER_FILE)}). If no local-ci run is active there, remove ${lockPath} and run again.`
    }
    const what = holder.branch ? `validating origin/${holder.branch} in the slot` : 'validating the working tree'
    return `Another local-ci run holds ${worktreePath}: pid ${holder.pid}, ${what}, since ${holder.startedAt}. Wait for it, or run against another worktree. If pid ${holder.pid} is not a local-ci run, remove ${lockPath} and run again.`
}

// Take the lock, or say who has it. Returns { ok: true, lockPath, release } or
// { ok: false, message }. A lock whose holder pid is gone is reclaimed once,
// announced, and the run proceeds; the holder is read again immediately before
// the delete so a lock another run just took over is not removed.
export function acquireWorktreeLock(worktreePath, { branch = null, pid = process.pid, isAlive = pidIsAlive, now = () => new Date(), onReclaim = () => {}, beforeRename = () => {} } = {}) {
    const lockPath = lockPathFor(worktreePath)
    const holderFile = join(lockPath, HOLDER_FILE)
    const readHolder = () => {
        try {
            return readFileSync(holderFile, 'utf8')
        } catch {
            return null
        }
    }

    // A holder between mkdir and writing its record shows an empty directory for
    // a moment; look again briefly before treating the record as unreadable.
    const readRecord = () => {
        let raw = readHolder()
        for (let tries = 0; raw === null && tries < 5; tries++) {
            Atomics.wait(new Int32Array(new SharedArrayBuffer(4)), 0, 0, 20)
            raw = readHolder()
        }
        return raw
    }

    for (let attempt = 0; attempt < 2; attempt++) {
        try {
            mkdirSync(lockPath)
        } catch (error) {
            if (error.code === 'ENOENT') {
                // A --worktree-path whose parent directories do not exist yet:
                // git worktree add would create them, so the lock does too.
                mkdirSync(dirname(lockPath), { recursive: true })
                attempt--
                continue
            }
            if (error.code !== 'EEXIST') {
                throw error
            }
            const raw = readRecord()
            const verdict = holderDisposition(raw, isAlive)
            if (verdict.state === 'held' || attempt === 1 || readHolder() !== raw) {
                return { ok: false, message: lockRefusal({ worktreePath, lockPath, holder: verdict.holder }) }
            }
            // Reclaim by renaming, which only one of several racing runs can win:
            // a loser finds the path gone and goes back to mkdir, which then
            // reports the winner's lock as held. Deleting in place instead would
            // let a slow run remove a lock another run had just taken.
            const stalePath = `${lockPath}.stale-${pid}-${Date.now()}`
            beforeRename()
            try {
                renameSync(lockPath, stalePath)
            } catch {
                continue
            }
            // The directory moved is the one that was read only if its record is
            // unchanged; if another run reclaimed first, this took its live lock,
            // so put it back and refuse.
            let moved = null
            try {
                moved = readFileSync(join(stalePath, HOLDER_FILE), 'utf8')
            } catch {
                // Treated as changed below.
            }
            if (moved !== raw) {
                try {
                    renameSync(stalePath, lockPath)
                } catch {
                    // Another run has already taken the path; nothing more to restore.
                }
                return { ok: false, message: lockRefusal({ worktreePath, lockPath, holder: holderDisposition(moved, isAlive).holder }) }
            }
            onReclaim(`reclaiming the lock at ${lockPath}: its holder, pid ${verdict.holder.pid}, is no longer running.`)
            rmSync(stalePath, { recursive: true, force: true })
            continue
        }
        try {
            writeFileSync(holderFile, `${JSON.stringify({ pid, branch, worktree: resolve(worktreePath), startedAt: now().toISOString() })}\n`)
        } catch (error) {
            rmSync(lockPath, { recursive: true, force: true })
            throw error
        }
        // Release only a lock that still names this process.
        const release = () => {
            let holder = null
            try {
                holder = JSON.parse(readHolder())
            } catch {
                return
            }
            if (holder && holder.pid === pid) {
                rmSync(lockPath, { recursive: true, force: true })
            }
        }
        return { ok: true, lockPath, release }
    }
    return { ok: false, message: lockRefusal({ worktreePath, lockPath, holder: null }) }
}

// The lock this process holds, released on any way out of the process: a normal
// end, a failing exit status, a thrown error, process.exit, and an interrupt
// that reaches a job (runShell). A signal delivered to this process alone while
// it waits on a job cannot be caught, because the run is synchronous; its lock
// is reclaimed by the next run, since the holder pid is gone.
let heldLock = null

function holdLock(lock) {
    heldLock = lock
    process.once('exit', () => lock.release())
}

// --- database locks --------------------------------------------------------
//
// A job's `database.lock` names the lock file prefix a repository's own test
// suite uses, and the runner takes that same file, so a run and a suite started
// by hand against one database refuse each other instead of one dropping the
// other's tables. The file format is the one uamswp-migration-api's runner and
// tests/Support/single-flight.php share:
//
//     <tmpdir>/<prefix>.database.<first 16 hex of sha256(name)>.lock
//
// created exclusively, holding JSON whose integer `pid` names the holder. The
// suite is told the runner already holds the lock through `heldEnv` (set to
// "1"), since nothing else in its environment says the holder is its parent.

export function databaseLockPath(prefix, database, dir = tmpdir()) {
    return join(dir, `${prefix}.database.${createHash('sha256').update(database).digest('hex').slice(0, 16)}.lock`)
}

export function databaseLockRefusal({ database, lockPath, holder }) {
    if (holder === null) {
        return `The database ${database} is locked by a run whose record cannot be read (${lockPath}). If no run is using that database, remove ${lockPath} and run again.`
    }
    const what = holder.suite ? ` (${holder.suite})` : ''
    const since = holder.startedAt ? `, since ${holder.startedAt}` : ''
    return `Another run is using the database ${database}: pid ${holder.pid}${what}${since}. A test installer that drops the tables it finds would destroy that run's fixtures, so this run stops before any job. Wait for it. If pid ${holder.pid} is not running a suite, remove ${lockPath} and run again.`
}

// Take the lock file for `database`, or say who has it. Returns { ok: true,
// lockPath, release } or { ok: false, message }. Fails closed on a record it
// cannot read, after a short wait for a holder that has created the file but
// not written it yet; reclaims a record whose pid is gone, by the same
// rename-and-verify the worktree lock uses.
export function acquireDatabaseLock({ prefix, database, suite, branch = null, dir = tmpdir(), pid = process.pid, isAlive = pidIsAlive, now = () => new Date(), onReclaim = () => {} }) {
    const lockPath = databaseLockPath(prefix, database, dir)
    const read = () => {
        try {
            return readFileSync(lockPath, 'utf8')
        } catch {
            return null
        }
    }
    const readRecord = () => {
        let raw = read()
        for (let tries = 0; (raw === null || raw === '') && tries < 5; tries++) {
            Atomics.wait(new Int32Array(new SharedArrayBuffer(4)), 0, 0, 20)
            raw = read()
        }
        return raw
    }
    const record = `${JSON.stringify({ pid, suite, database, branch, startedAt: now().toISOString() })}\n`

    // The record is written to a temporary file first and linked into place, so
    // the lock file never exists empty: a suite that finds an empty lock file
    // reclaims it, and would then run with no lock at all. link() fails with
    // EEXIST when the lock is held, which makes it the exclusive create.
    const tempPath = `${lockPath}.${pid}-${Date.now()}.tmp`
    const writeTemp = () => {
        try {
            writeFileSync(tempPath, record)
        } catch (error) {
            throw new Error(`Could not write the lock for the database ${database} in ${dir} (${error.code ?? error.message}). The directory must exist and be writable; it is the temporary directory, from TMPDIR on macOS and Linux, or TEMP on Windows.`)
        }
    }
    const create = () => {
        writeTemp()
        try {
            linkSync(tempPath, lockPath)
        } finally {
            rmSync(tempPath, { force: true })
        }
    }

    for (let attempt = 0; attempt < 2; attempt++) {
        try {
            create()
        } catch (error) {
            if (error.code !== 'EEXIST') {
                throw error
            }
            const raw = readRecord()
            const verdict = holderDisposition(raw, isAlive)
            // A record naming this pid was left by a dead run on a number since
            // reused for this one: taking it over is the same as reclaiming it.
            // Replaced by a rename, so the file is never seen empty.
            if (verdict.holder?.pid === pid) {
                writeTemp()
                renameSync(tempPath, lockPath)
                break
            }
            if (verdict.state === 'held' || attempt === 1 || read() !== raw) {
                return { ok: false, message: databaseLockRefusal({ database, lockPath, holder: verdict.holder }) }
            }
            const stalePath = `${lockPath}.stale-${pid}-${Date.now()}`
            try {
                renameSync(lockPath, stalePath)
            } catch {
                continue
            }
            let moved = null
            try {
                moved = readFileSync(stalePath, 'utf8')
            } catch {
                // Treated as changed below.
            }
            if (moved !== raw) {
                // Put it back with link(), which fails rather than replacing a
                // lock some third run has created in the meantime.
                try {
                    linkSync(stalePath, lockPath)
                } catch {
                    // Another run has already taken the path; nothing more to restore.
                }
                rmSync(stalePath, { force: true })
                return { ok: false, message: databaseLockRefusal({ database, lockPath, holder: holderDisposition(moved, isAlive).holder }) }
            }
            onReclaim(`reclaiming the lock on the database ${database} at ${lockPath}: its holder, pid ${verdict.holder.pid}, is no longer running.`)
            rmSync(stalePath, { force: true })
            continue
        }
        break
    }
    let mine = null
    try {
        mine = JSON.parse(read())
    } catch {
        // Treated as not taken below.
    }
    if (!mine || mine.pid !== pid) {
        return { ok: false, message: databaseLockRefusal({ database, lockPath, holder: holderDisposition(read(), isAlive).holder }) }
    }
    // Release only a lock that still names this process.
    const release = () => {
        let holder = null
        try {
            holder = JSON.parse(read())
        } catch {
            return
        }
        if (holder && holder.pid === pid) {
            rmSync(lockPath, { force: true })
        }
    }
    return { ok: true, lockPath, release }
}

// The distinct database locks the selected jobs declare, one per lock file.
export function databaseLocksFor(entries) {
    const locks = new Map()
    for (const entry of entries) {
        const database = entry.job?.database
        if (!database?.lock) {
            continue
        }
        const key = `${database.lock.prefix}\0${database.name}`
        const lock = locks.get(key) ?? { prefix: database.lock.prefix, database: database.name, jobs: [] }
        lock.jobs.push(entry.name)
        locks.set(key, lock)
    }
    return [...locks.values()]
}

// The environment a job runs in, from the runner's. A job that declares no
// database gets the runner's unchanged. One that does has every variable any
// job's `database` names removed, so it sees no database name but its own, then
// its own name, with the lock's `heldEnv` set to "1".
export function jobEnvironment(baseEnv, job, jobs) {
    const env = { ...baseEnv }
    if (!job.database) {
        return env
    }
    const declared = new Set(jobs.flatMap((other) => (other.database ? [other.database.env, other.database.lock?.heldEnv].filter(Boolean) : [])))
    for (const key of Object.keys(env)) {
        // Windows environment names are case-insensitive.
        if ([...declared].some((name) => (isWindows ? name.toUpperCase() === key.toUpperCase() : name === key))) {
            delete env[key]
        }
    }
    env[job.database.env] = job.database.name
    if (job.database.lock?.heldEnv) {
        env[job.database.lock.heldEnv] = '1'
    }
    return env
}

// --- execution -------------------------------------------------------------

function runShell(command, cwd, env, { capture = false } = {}) {
    const res = spawnSync(command, {
        cwd,
        shell: true,
        env,
        encoding: 'utf8',
        stdio: capture ? ['ignore', 'pipe', 'pipe'] : 'inherit',
        // The default 1 MB cap kills a passing command that prints more and
        // reports it as "could not start"; the job's own exit status decides.
        maxBuffer: Infinity,
    })
    if (res.error) {
        console.error(red(`  could not start: ${res.error.message}`))
    }
    // A job killed by SIGINT, SIGTERM or SIGHUP: stop the run instead of starting
    // the next job, and let go of the lock on the way out. A terminal's Ctrl-C
    // also reaches this process, which Node ends without an exit event, so that
    // case falls to the next run's reclaim.
    const stopped = interruptExitCode(res.signal)
    if (stopped !== null) {
        console.error(red(`\nlocal-ci: interrupted (${res.signal}).`))
        heldLock?.release()
        process.exit(stopped)
    }
    // Any other signal leaves a null status; read that kill as a failure.
    return { code: res.status ?? 1, output: `${res.stdout ?? ''}${res.stderr ?? ''}` }
}

function makeExecutor({ repoRoot, config, php, jobPhps = new Map(), trackedFiles }) {
    const env = { ...process.env, LOCAL_CI: '1', ...(php ? { LOCAL_CI_PHP: php.path } : {}) }
    // The handoff markers belong to the runner. A job that itself runs the
    // runner (this skill's own tests do) must not be read as a handed-off run.
    delete env[HANDOFF_ENV]
    delete env[BRANCH_ENV]

    return (entry) => {
        if (entry.guard) {
            const violations = guardViolations(trackedFiles, config.deny)
            console.log(`${violations.length === 0 ? 'No' : violations.length} tracked file${violations.length === 1 ? '' : 's'} match${violations.length === 1 ? 'es' : ''} a guard.deny pattern (${config.deny.length} pattern${config.deny.length === 1 ? '' : 's'}, ${trackedFiles.length} tracked files).`)
            for (const file of violations) {
                console.log(red(`  denied: ${file}`))
            }
            return { code: violations.length === 0 ? 0 : 1 }
        }

        const { job } = entry
        const own = job.php ? jobPhps.get(job.php) : null
        const phpPath = own ? own.path : php ? php.path : 'php'
        const jobEnv = { ...jobEnvironment(env, job, config.jobs), ...(own ? { LOCAL_CI_PHP: own.path } : {}) }
        const command = commandFor(job.run, phpPath)
        if (job.mutation) {
            console.log(`  $ ${command}`)
            const { code, output } = runShell(command, repoRoot, jobEnv, { capture: true })
            process.stdout.write(output === '' || output.endsWith('\n') ? output : `${output}\n`)
            if (code !== 0) {
                return { code }
            }
            const problems = mutationProblems(output, job.mutation.minScore)
            for (const problem of problems) {
                console.log(red(`  ${problem}`))
            }
            return { code: problems.length === 0 ? 0 : 1 }
        }
        if (job.parallel) {
            // Both parts always run: a red parallel part must not hide whether
            // the serial tail passes. The exit code is the first failing part's.
            const outcomes = parallelParts(job.run, job.parallel, phpPath).map(({ part, command: partCommand }) => {
                console.log(`  [${part}] $ ${partCommand}`)
                return { part, code: runShell(partCommand, repoRoot, jobEnv).code }
            })
            const failed = outcomes.filter((outcome) => outcome.code !== 0)
            for (const { part, code } of failed) {
                console.log(red(`  ${part} part failed (exit ${code})`))
            }
            return { code: failed.length === 0 ? 0 : failed[0].code, failedParts: failed.map((outcome) => outcome.part) }
        }
        if (job.each.length === 0) {
            console.log(`  $ ${command}`)
            return runShell(command, repoRoot, jobEnv)
        }

        const targets = eachTargets(trackedFiles, job.each, (file) => existsSync(join(repoRoot, file)))
        console.log(`  $ ${command} <file>: ${targets.length} tracked file${targets.length === 1 ? '' : 's'} matching ${job.each.join(', ')}`)
        if (targets.length === 0) {
            warn(`${job.name}: no tracked file matches ${job.each.join(', ')}; nothing was checked.`)
        }
        let failures = 0
        for (const file of targets) {
            const { code, output } = runShell(`${command} ${shellQuote(fileArgument(file))}`, repoRoot, jobEnv, { capture: true })
            if (code !== 0) {
                failures += 1
                console.log(red(`  FAIL ${file} (exit ${code})`))
                process.stdout.write(output.endsWith('\n') ? output : `${output}\n`)
            }
        }
        console.log(`  ${targets.length - failures} of ${targets.length} passed.`)
        return { code: failures === 0 ? 0 : 1 }
    }
}

// --- the commit status -----------------------------------------------------

// GitHub truncates a status description past 140 characters.
const STATUS_DESCRIPTION_LIMIT = 140

// The status for a finished run, or null when there is nothing to assert. The
// state follows the exit status (`success` at 0, `failure` at 1), and a run in
// which no job ran asserts nothing: its exit status is 0, and a green status on
// a commit nothing checked is the false reassurance a status exists to remove.
// The failing job names come first in the description, so a truncation loses
// the tail and not the names.
export function commitStatusFor(tree, results, context) {
    if (results.every((result) => result.status === 'SKIPPED')) {
        return null
    }
    const failed = exitStatus(results) !== 0
    const description = failed
        ? `${results.filter((r) => r.status === 'FAIL').map((r) => r.name).join(', ')} failed`
        : verdictOf(results)
    return {
        sha: tree.sha,
        state: failed ? 'failure' : 'success',
        context,
        description: description.slice(0, STATUS_DESCRIPTION_LIMIT),
    }
}

// The `gh api` argument list for a status: the REST endpoint, on the exact
// commit the run validated. `{owner}/{repo}` is filled in by gh from the
// repository in the working directory.
export function commitStatusArgs(status) {
    return [
        'api', '-X', 'POST', `repos/{owner}/{repo}/statuses/${status.sha}`,
        '-f', `state=${status.state}`,
        '-f', `context=${status.context}`,
        '-f', `description=${status.description}`,
    ]
}

// Post it. A status that cannot be posted warns and returns false; it never
// changes the run's exit status. `gh` and `spawn` are parameters so the tests
// stub the call rather than reach GitHub.
export function postCommitStatus(repoRoot, tree, results, context, { gh = which('gh'), spawn = spawnSync } = {}) {
    const status = commitStatusFor(tree, results, context)
    if (status === null) {
        warn('commit status not posted: no job ran, so there is nothing to assert.')
        return false
    }
    if (!gh) {
        warn('commit status not posted: gh was not found on PATH.')
        return false
    }
    const res = spawn(gh, commitStatusArgs(status), { cwd: repoRoot, encoding: 'utf8' })
    if (res.status !== 0) {
        warn(`commit status not posted (gh exit ${res.status ?? 'unknown'}): ${String(res.stderr || res.error?.message || '').trim().split('\n')[0]}`)
        return false
    }
    console.log(`Commit status: ${status.state} on ${status.sha.slice(0, 9)} (context ${status.context})`)
    return true
}

// --- the pull request comment ----------------------------------------------

function postComment(repoRoot, tree, results, branch = null) {
    const gh = which('gh')
    if (!gh) {
        warn('comment not posted: gh was not found on PATH.')
        return false
    }
    // A slot is detached, so the pull request is found by the branch name.
    const view = spawnSync(gh, ['pr', 'view', ...(branch ? [branch] : []), '--json', 'number,state,headRefOid,url'], { cwd: repoRoot, encoding: 'utf8' })
    if (view.status !== 0) {
        warn(`comment not posted: no open pull request for this branch (${(view.stderr || '').trim().split('\n')[0]}).`)
        return false
    }
    const pr = JSON.parse(view.stdout)
    // `gh pr view` with no argument returns the branch's most recent pull
    // request in any state, so a reused branch name finds a merged one.
    if (pr.state !== 'OPEN') {
        warn(`comment not posted: the branch's pull request #${pr.number} is ${String(pr.state).toLowerCase()}, not open (${pr.url}). Open a pull request first, or comment by hand.`)
        return false
    }
    if (pr.headRefOid !== tree.sha) {
        warn(`the pull request head is ${pr.headRefOid.slice(0, 9)}; this run validated ${tree.sha.slice(0, 9)}. Push, or read the comment with that in mind.`)
    }
    const bodyFile = join(tmpdir(), `local-ci-comment-${process.pid}.md`)
    writeFileSync(bodyFile, commentBody(tree, results))
    try {
        const post = spawnSync(gh, ['pr', 'comment', String(pr.number), '--body-file', bodyFile], { cwd: repoRoot, encoding: 'utf8' })
        if (post.status !== 0) {
            warn(`comment not posted (gh exit ${post.status}): ${(post.stderr || '').trim().split('\n')[0]}`)
            return false
        }
        console.log(`Comment posted on ${pr.url} for ${tree.sha}.`)
        return true
    } finally {
        unlinkSync(bodyFile)
    }
}

// --- main ------------------------------------------------------------------

// Take the single-flight lock for a worktree, or print who holds it. Returns
// whether this run now holds it.
function takeLock(worktreePath, branch) {
    const lock = acquireWorktreeLock(worktreePath, { branch, onReclaim: warn })
    if (!lock.ok) {
        console.error(red(`\nlocal-ci: ${lock.message}`))
        return false
    }
    holdLock(lock)
    return true
}

function main(argv) {
    const major = Number(process.versions.node.split('.')[0])
    if (major < 20) {
        throw new Error(`Node ${process.versions.node} is too old; this runner needs Node 20 or newer.`)
    }

    const opts = parseArgs(argv)
    if (opts.help) {
        console.log(usage())
        return 0
    }

    const scriptPath = fileURLToPath(import.meta.url)
    let repoRoot = repoRootOf(scriptPath)

    if (opts.dropPatchRecord) {
        console.log(dropPatchRecord(repoRoot) ? `Patches:    removed ${PATCH_LOCK_RECORD}` : `Patches:    no ${PATCH_LOCK_RECORD} to remove`)
        return 0
    }

    if (opts.branch) {
        if (isHandedOff()) {
            throw new Error('--branch inside a handed-off run: the slot is already prepared, and a second handoff would not terminate.')
        }
        // Taken before the slot is prepared: preparing it moves the tree.
        if (!takeLock(resolve(opts.worktreePath ?? slotPathFor(primaryRootOf(repoRoot))), opts.branch)) {
            return LOCK_REFUSED_EXIT
        }
        const slot = prepareBranchSlot(repoRoot, opts.branch, { worktreePath: opts.worktreePath, log: (line) => console.log(line) })
        console.log(`Branch:     origin/${opts.branch} at ${slot.sha}`)
        // The branch's own copy of this runner gates the branch, so a pull
        // request that changes the job list is held to its own list.
        const branchCopy = join(slot.path, relative(repoRoot, scriptPath))
        if (existsSync(branchCopy) && !sameRunner(readFileSync(scriptPath, 'utf8'), readFileSync(branchCopy, 'utf8'))) {
            console.log(`Handoff:    the branch's local-ci.mjs differs from the executing one; running ${branchCopy}.`)
            const child = spawnSync(process.execPath, [branchCopy, ...stripSlotOptions(argv)], {
                cwd: slot.path,
                stdio: 'inherit',
                env: { ...process.env, [HANDOFF_ENV]: String(process.pid), [BRANCH_ENV]: opts.branch },
            })
            if (child.error) {
                throw new Error(`The branch's runner could not be started: ${child.error.message}`)
            }
            return child.status ?? 1
        }
        repoRoot = slot.path
    } else if (!isHandedOff() && !opts.dryRun && !takeLock(repoRoot, null)) {
        // A handed-off child runs in the slot its parent already holds, and a
        // plain --dry-run changes nothing, so neither takes the lock.
        return LOCK_REFUSED_EXIT
    }
    const config = loadConfig(repoRoot)
    const base = opts.base ?? config.base

    // Before the tree is described, so the verdict's commit and dirty state are
    // the slot's after it has been brought in line.
    if (config.prepare) {
        const steps = [
            config.prepare.residue.length > 0 ? `residue under ${config.prepare.residue.join(', ')}` : null,
            config.prepare.submodules.length > 0 ? `submodules ${config.prepare.submodules.join(', ')}` : null,
            config.prepare.patches ? 'patches' : null,
        ].filter(Boolean).join('; ')
        if (!isRunnerSlot(repoRoot)) {
            console.log(`Prepare:    not run (${steps}): this checkout is not a runner-owned slot; "prepare" runs only in a slot --branch created.`)
        } else if (opts.dryRun) {
            console.log(`Prepare:    would run ${steps}`)
        } else {
            prepareSlot(repoRoot, config.prepare, { log: (line) => console.log(line) })
        }
    }

    const tree = describeTree(repoRoot)
    console.log(`Validating: ${tree.sha} ${tree.subject}`)
    if (tree.dirty) {
        warn(`the tree is DIRTY (${tree.dirty.tracked} tracked change${tree.dirty.tracked === 1 ? '' : 's'}, ${tree.dirty.untracked} untracked file${tree.dirty.untracked === 1 ? '' : 's'}). The verdict will describe the working tree, not commit ${tree.sha.slice(0, 9)}.`)
        if (opts.comment) {
            throw new Error('--comment is refused on a dirty tree: a comment names a commit, and this run would not describe one. Commit or stash, then run again.')
        }
        if (opts.commitStatus) {
            throw new Error('--commit-status is refused on a dirty tree: a status belongs to a commit, and this run would not describe one. Commit or stash, then run again.')
        }
    }

    const hooksWarning = hooksPathWarning(repoRoot, readHooksState(repoRoot))
    if (hooksWarning) {
        warn(hooksWarning)
    }

    const changed = changedFiles(repoRoot, base, { tolerateUnresolved: Boolean(opts.all || opts.jobs) })
    const noMergeBase = requireMergeBase(changed, opts)
    if (noMergeBase) {
        warn(noMergeBase)
    }
    console.log(
        changed.unresolved === undefined
            ? `Changed:    ${changed.files.length} file${changed.files.length === 1 ? '' : 's'} against ${changed.against}${changed.mergeBase ? ` (merge base ${changed.mergeBase.slice(0, 9)})` : ''}`
            : `Changed:    not read (${changed.against} does not resolve)`,
    )

    const selection = selectJobs(config, changed.files, {
        ...opts,
        unmet: (job) => unmetRequirements(job.requires, { exists: (path) => existsSync(join(repoRoot, path)), hasBin: (name) => which(name) !== null }),
    })
    const needs = selection.selected.some((entry) => entry.job && needsPhp(entry.job.run) && !entry.job.php)
    // Each version a selected job names for itself, with the jobs that name it.
    const ownVersions = new Map()
    for (const entry of selection.selected) {
        if (entry.job && entry.job.php) {
            ownVersions.set(entry.job.php, [...(ownVersions.get(entry.job.php) ?? []), entry.name])
        }
    }

    if (opts.dryRun) {
        console.log(`\nPlan (${config.chain ? 'chain: stop at the first failure' : 'every selected job runs'}):`)
        for (const entry of selection.selected) {
            console.log(`  ${green('RUN ')} ${entry.name.padEnd(12)} ${entry.reason}`)
            if (entry.job) {
                if (entry.job.parallel) {
                for (const { part, command: partCommand } of parallelParts(entry.job.run, entry.job.parallel, 'php')) {
                    console.log(`       ${''.padEnd(12)} [${part}] $ ${partCommand}`)
                }
            } else {
                console.log(`       ${''.padEnd(12)} $ ${entry.job.run}${entry.job.each.length > 0 ? ` <each of ${entry.job.each.join(', ')}>` : ''}`)
                const database = entry.job.database
                if (database) {
                    console.log(`       ${''.padEnd(12)} database ${database.name} in ${database.env}${database.lock ? `, locked at ${databaseLockPath(database.lock.prefix, database.name)}` : ''}`)
                }
            }
            }
        }
        for (const entry of selection.skipped) {
            console.log(`  ${yellow('SKIP')} ${entry.name.padEnd(12)} ${entry.reason}`)
        }
        if (needs) {
            const candidates = phpCandidates({ version: config.php })
            console.log(`\nPHP would resolve from: ${candidates.map((c) => `${c.source} (${c.path})`).concat('PATH').join(', then ')}.`)
            const missingHerd = candidates.filter((c) => c.source.startsWith('Herd') && !existsSync(c.path))
            if (missingHerd.length > 0) {
                console.log(
                    `  Note: ${missingHerd.map((c) => c.source).join(', ')} is not installed; a real run falls through to PATH php and warns if its version is not the one ${CONFIG_FILE} names.`,
                )
            }
        }
        for (const [version, jobs] of ownVersions) {
            const candidates = jobPhpCandidates({ version })
            console.log(`PHP ${version} for ${jobs.join(', ')} would resolve from: ${candidates.map((c) => (c.bin ? c.source : `${c.source} (${c.path})`)).join(', then ')}; nothing else.`)
        }
        return 0
    }

    // Fail before any job runs, naming the remedy, rather than letting every
    // vendor/bin job fail with the shell's "not found".
    if (vendorMissingFor(selection.selected, existsSync(join(repoRoot, 'vendor', 'autoload.php')))) {
        throw new Error(
            `vendor/ is missing in ${repoRoot}; the selected jobs run vendor/bin tools and no job marked "installs" runs before them. Run \`composer install\` first, or mark the job that installs dependencies "installs": true and list it before the first vendor/bin job.`,
        )
    }

    let php = null
    if (needs) {
        php = resolvePhp(config)
        const version = phpVersion(php.path)
        console.log(`PHP:        ${php.path} (${version}, from ${php.source})`)
        if (config.php && !version.startsWith(`${config.php}.`)) {
            warn(`PHP is ${version}; ${CONFIG_FILE} names ${config.php}. Results may differ from a ${config.php} run.`)
        }
    }

    const jobPhps = new Map()
    for (const [version, jobs] of ownVersions) {
        const resolved = resolveJobPhp(version, jobs)
        const reported = phpVersion(resolved.path)
        // The version is the point of the job, so a mismatch is a failure, not a warning.
        if (!reported.startsWith(`${version}.`)) {
            throw new Error(`${resolved.path} (from ${resolved.source}) is PHP ${reported}, not ${version}, which ${jobs.map((name) => JSON.stringify(name)).join(', ')} need${jobs.length === 1 ? 's' : ''}.`)
        }
        console.log(`PHP ${version}:    ${resolved.path} (${reported}, from ${resolved.source}) for ${jobs.join(', ')}`)
        jobPhps.set(version, resolved)
    }

    // Every database lock the selected jobs declare, before any job runs, so a
    // refusal never leaves a run half done. Released on any way out, as the
    // worktree lock is.
    for (const want of databaseLocksFor(selection.selected)) {
        const lock = acquireDatabaseLock({ prefix: want.prefix, database: want.database, suite: `local-ci ${want.jobs.join(', ')}`, branch: opts.branch ?? process.env[BRANCH_ENV] ?? null, onReclaim: warn })
        if (!lock.ok) {
            console.error(red(`\nlocal-ci: ${lock.message}`))
            return LOCK_REFUSED_EXIT
        }
        process.once('exit', () => lock.release())
        console.log(`Database:   ${want.database} locked for ${want.jobs.join(', ')} (${lock.lockPath})`)
    }

    const trackedFiles = gitPaths(repoRoot, ['ls-files', '-z'])
    const execute = makeExecutor({ repoRoot, config, php, jobPhps, trackedFiles })

    const results = runJobs(selection, {
        chain: config.chain,
        execute: (entry) => {
            console.log(`\n${cyan(`==> JOB ${entry.name}`)}: ${entry.reason}`)
            return execute(entry)
        },
    })

    console.log(`\n${formatSummary(tree, results, { color: useColor })}`)

    // A comment that cannot be posted never changes the exit status.
    if (opts.comment) {
        try {
            postComment(repoRoot, tree, results, opts.branch ?? process.env[BRANCH_ENV] ?? null)
        } catch (error) {
            warn(`comment not posted: ${error.message}`)
        }
    }

    // Likewise a status that cannot be posted never changes the exit status.
    if (opts.commitStatus) {
        try {
            postCommitStatus(repoRoot, tree, results, config.statusContext)
        } catch (error) {
            warn(`commit status not posted: ${error.message}`)
        }
    }

    return exitStatus(results)
}

// Run only when invoked directly, never on import. process.argv[1] is absent
// under `node -e` and a REPL, where pathToFileURL(undefined) would throw.
const entryPoint = process.argv[1] ? pathToFileURL(process.argv[1]).href : null
if (import.meta.url === entryPoint) {
    try {
        process.exitCode = main(process.argv.slice(2))
    } catch (error) {
        console.error(red(`\nlocal-ci: ${error.message}`))
        process.exitCode = 1
    }
}
