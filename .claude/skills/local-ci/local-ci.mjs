#!/usr/bin/env node
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
//     2. Reads `local-ci.json`.
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
//        failed, 0 otherwise. The summary and the exit status are derived from
//        the same results, so they cannot disagree. A run in which no job ran
//        exits 0 with the verdict NOTHING RAN, never PASS.
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
//     -h, --help         Show this help.
//
// CONFIG: `local-ci.json` at the repository root
//     {
//       "base": "main",                     branch the diff is taken against
//       "php": "8.2",                       PHP version the jobs run under
//       "guard": {
//         "deny": ["sentinel-key\\.php$", "\\.sql(\\.gz)?$"]
//       },                                  regexes no tracked file may match
//       "chain": false,                     true: stop at the first failure
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
//     absent so the plan is not read as a guarantee that pin will run.
//
// EXPORTS
//     The pure functions (config parsing, glob matching, job selection, the
//     chain, summary formatting, exit-status derivation, the comment body) are
//     exported for `local-ci.test.mjs`. Importing this module runs nothing.

import { spawnSync } from 'node:child_process'
import { accessSync, constants, existsSync, readFileSync, statSync, writeFileSync, unlinkSync } from 'node:fs'
import { homedir, tmpdir } from 'node:os'
import { delimiter, dirname, extname, join, posix } from 'node:path'
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

Options:
  --all           Run every job in local-ci.json, whatever the diff says.
  --jobs <a,b>    Run only the named jobs. The guard job still runs first.
  --base <ref>    Diff against the merge base with origin/<ref> (default: the
                  config's "base", then "main").
  --dry-run       Print which jobs would run, and why, without running them.
  --comment       Post the summary as a pull request comment through gh, naming
                  the validated commit. Refused when the tree is dirty.
  -h, --help      Show this help.

Config: local-ci.json at the repository root. The header of this file documents
its format; .claude/skills/local-ci/templates/local-ci.json is a worked example.`
}

export function parseArgs(argv) {
    const opts = { all: false, jobs: null, base: null, dryRun: false, comment: false, help: false }
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
            default:
                throw new Error(`Unknown option: ${arg} (see --help)`)
        }
    }
    if (opts.all && opts.jobs) {
        throw new Error('--all and --jobs exclude each other.')
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
export function parseConfig(raw) {
    const config = typeof raw === 'string' ? JSON.parse(raw) : raw
    if (!config || typeof config !== 'object' || Array.isArray(config)) {
        throw new Error(`${CONFIG_FILE} must hold a JSON object.`)
    }

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
        return { name: job.name, run: job.run.trim(), paths, each, always: job.always === true }
    })

    return { base, php: php === null ? null : String(php), chain, deny, jobs }
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
            selected.push({ name: job.name, reason, job })
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
        const { code } = execute(entry)
        const ms = now() - started
        if (code === 0) {
            results.push({ name: entry.name, status: 'PASS', ms, code })
        } else {
            results.push({ name: entry.name, status: 'FAIL', ms, code })
            if (chain) {
                brokenBy = entry.name
            }
        }
    }
    for (const entry of selection.skipped) {
        results.push({ name: entry.name, status: 'SKIPPED', reason: `not selected: ${entry.reason}`, ms: 0 })
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
    const failed = ran.filter((r) => r.status === 'FAIL')
    if (exitStatus(results) !== 0) {
        return `FAIL: ${failed.length} of ${ran.length} job${ran.length === 1 ? '' : 's'} run failed: ${failed.map((r) => r.name).join(', ')}`
    }
    // Nothing checked is not a pass. The exit status stays 0 (a change no job
    // reads is not a failure), but no word here or in the comment says PASS.
    if (ran.length === 0) {
        return 'NOTHING RAN: no job was selected, so nothing was checked'
    }
    return `PASS: ${ran.length} of ${ran.length} job${ran.length === 1 ? '' : 's'} run passed`
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
            lines.push(`  ${name}  ${tint(red, 'FAIL   ')}  ${formatDuration(r.ms).padStart(7)}  (exit ${r.code})`)
        } else {
            lines.push(`  ${name}  ${tint(yellow, 'SKIPPED')}           (${r.reason})`)
        }
    }
    lines.push('--------------------------------------------------')
    const verdict = verdictOf(results)
    lines.push(`  Verdict:  ${tint(exitStatus(results) !== 0 ? red : verdict.startsWith('PASS') ? green : yellow, verdict)}`)
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
    const headline = exitStatus(results) !== 0 ? 'FAIL' : ran.length === 0 ? 'NOTHING RAN' : 'PASS'
    lines.push(`**local-ci: ${headline}** on \`${tree.sha}\`: ${tree.subject}`)
    lines.push('')
    lines.push(verdict + '.')
    lines.push('')
    lines.push('| Job | Result | Duration |')
    lines.push('| --- | --- | --- |')
    for (const r of ran) {
        lines.push(`| \`${r.name}\` | ${r.status}${r.status === 'FAIL' ? ` (exit ${r.code})` : ''} | ${formatDuration(r.ms)} |`)
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
export function changedFiles(repoRoot, base) {
    const remoteBase = `origin/${base}`
    let ref = git(repoRoot, ['rev-parse', '--verify', '--quiet', remoteBase], { allowFailure: true })
    let baseName = remoteBase
    if (ref === null) {
        ref = git(repoRoot, ['rev-parse', '--verify', '--quiet', base], { allowFailure: true })
        baseName = base
        if (ref === null) {
            throw new Error(`Neither ${remoteBase} nor ${base} resolves. Fetch origin, or pass --base <ref>.`)
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
    const why = `HEAD and ${changed.against} share no history (a shallow clone, or unrelated histories), so the branch's changes cannot be listed.`
    if (opts.all || opts.jobs) {
        return `${why} The selection does not depend on the diff (${opts.all ? '--all' : '--jobs'}), so the run proceeds.`
    }
    throw new Error(`${why} Selecting jobs from that would skip what the branch changed. Run \`git fetch --unshallow\` (or fetch ${changed.against}'s history), pass --base <ref>, or run --all.`)
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
    // A signalled child reports a null status; read a kill as a failure.
    return { code: res.status ?? 1, output: `${res.stdout ?? ''}${res.stderr ?? ''}` }
}

function makeExecutor({ repoRoot, config, php, trackedFiles }) {
    const env = { ...process.env, LOCAL_CI: '1', ...(php ? { LOCAL_CI_PHP: php.path } : {}) }

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
        const command = commandFor(job.run, php ? php.path : 'php')
        if (job.each.length === 0) {
            console.log(`  $ ${command}`)
            return runShell(command, repoRoot, env)
        }

        const targets = eachTargets(trackedFiles, job.each, (file) => existsSync(join(repoRoot, file)))
        console.log(`  $ ${command} <file>: ${targets.length} tracked file${targets.length === 1 ? '' : 's'} matching ${job.each.join(', ')}`)
        if (targets.length === 0) {
            warn(`${job.name}: no tracked file matches ${job.each.join(', ')}; nothing was checked.`)
        }
        let failures = 0
        for (const file of targets) {
            const { code, output } = runShell(`${command} ${shellQuote(fileArgument(file))}`, repoRoot, env, { capture: true })
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

// --- the pull request comment ----------------------------------------------

function postComment(repoRoot, tree, results) {
    const gh = which('gh')
    if (!gh) {
        warn('comment not posted: gh was not found on PATH.')
        return false
    }
    const view = spawnSync(gh, ['pr', 'view', '--json', 'number,state,headRefOid,url'], { cwd: repoRoot, encoding: 'utf8' })
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

    const repoRoot = repoRootOf(fileURLToPath(import.meta.url))
    const config = loadConfig(repoRoot)
    const base = opts.base ?? config.base

    const tree = describeTree(repoRoot)
    console.log(`Validating: ${tree.sha} ${tree.subject}`)
    if (tree.dirty) {
        warn(`the tree is DIRTY (${tree.dirty.tracked} tracked change${tree.dirty.tracked === 1 ? '' : 's'}, ${tree.dirty.untracked} untracked file${tree.dirty.untracked === 1 ? '' : 's'}). The verdict will describe the working tree, not commit ${tree.sha.slice(0, 9)}.`)
        if (opts.comment) {
            throw new Error('--comment is refused on a dirty tree: a comment names a commit, and this run would not describe one. Commit or stash, then run again.')
        }
    }

    const changed = changedFiles(repoRoot, base)
    const noMergeBase = requireMergeBase(changed, opts)
    if (noMergeBase) {
        warn(noMergeBase)
    }
    console.log(`Changed:    ${changed.files.length} file${changed.files.length === 1 ? '' : 's'} against ${changed.against}${changed.mergeBase ? ` (merge base ${changed.mergeBase.slice(0, 9)})` : ''}`)

    const selection = selectJobs(config, changed.files, opts)
    const needs = selection.selected.some((entry) => entry.job && needsPhp(entry.job.run))

    if (opts.dryRun) {
        console.log(`\nPlan (${config.chain ? 'chain: stop at the first failure' : 'every selected job runs'}):`)
        for (const entry of selection.selected) {
            console.log(`  ${green('RUN ')} ${entry.name.padEnd(12)} ${entry.reason}`)
            if (entry.job) {
                console.log(`       ${''.padEnd(12)} $ ${entry.job.run}${entry.job.each.length > 0 ? ` <each of ${entry.job.each.join(', ')}>` : ''}`)
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
        return 0
    }

    // Fail before any job runs, naming the remedy, rather than letting every
    // vendor/bin job fail with the shell's "not found".
    const wantsVendor = selection.selected.some((entry) => entry.job && /^vendor[\\/]bin[\\/]/.test(entry.job.run))
    if (wantsVendor && !existsSync(join(repoRoot, 'vendor', 'autoload.php'))) {
        throw new Error(`vendor/ is missing in ${repoRoot}; the selected jobs run vendor/bin tools. Run \`composer install\` first.`)
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

    const trackedFiles = gitPaths(repoRoot, ['ls-files', '-z'])
    const execute = makeExecutor({ repoRoot, config, php, trackedFiles })

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
            postComment(repoRoot, tree, results)
        } catch (error) {
            warn(`comment not posted: ${error.message}`)
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
