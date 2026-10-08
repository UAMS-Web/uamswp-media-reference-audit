// Unit tests for local-ci.mjs's pure functions.
// Run: node --test .claude/skills/local-ci/local-ci.test.mjs
//
// Importing local-ci.mjs runs nothing: its main block is guarded by
// `import.meta.url === pathToFileURL(process.argv[1]).href`, which is false
// for every importer other than a direct `node local-ci.mjs` invocation.
import { after, describe, it } from 'node:test'
import assert from 'node:assert/strict'
import { spawnSync } from 'node:child_process'
import { chmodSync, copyFileSync, existsSync, mkdirSync, mkdtempSync, readdirSync, readFileSync, rmSync, symlinkSync, unlinkSync, writeFileSync } from 'node:fs'
import { tmpdir } from 'node:os'
import { basename, delimiter, dirname, join, resolve, sep } from 'node:path'
import { fileURLToPath, pathToFileURL } from 'node:url'

import {
    commandFor,
    commentBody,
    commitStatusArgs,
    commitStatusFor,
    eachTargets,
    exitStatus,
    fileArgument,
    formatDuration,
    formatSummary,
    globToRegExp,
    guardViolations,
    matchesGlob,
    needsPhp,
    parallelParts,
    parseArgs,
    parseConfig,
    parseStatusZ,
    postCommitStatus,
    phpCandidates,
    requireMergeBase,
    runJobs,
    HANDOFF_ENV,
    isHandedOff,
    sameRunner,
    acquireWorktreeLock,
    holderDisposition,
    interruptExitCode,
    LOCK_REFUSED_EXIT,
    lockPathFor,
    lockRefusal,
    canonicalPath,
    samePath,
    selectJobs,
    shellQuote,
    slotPathFor,
    stripSlotOptions,
    mutationProblems,
    unmetRequirements,
    verdictOf,
    installedPackages,
    patchReinstallPackages,
    PATCH_LOCK_RECORD,
    residuePaths,
    syncPatchedVendor,
    acquireDatabaseLock,
    databaseLockPath,
    databaseLocksFor,
    jobEnvironment,
} from './local-ci.mjs'

const CONFIG = {
    base: 'main',
    php: '8.2',
    guard: { deny: ['sentinel-key\\.php$', '\\.sql(\\.gz)?$'] },
    jobs: [
        { name: 'pint', run: 'vendor/bin/pint --test', paths: ['**/*.php', 'pint.json'] },
        { name: 'pest', run: 'vendor/bin/pest', paths: ['**/*.php', 'phpunit.xml*'] },
        { name: 'lint', run: 'php -l', each: ['**/*.php'], always: true },
        { name: 'docs', run: 'node scripts/check-docs.mjs', paths: ['docs/**/*.md'] },
    ],
}

const tree = { sha: 'a'.repeat(40), subject: 'Add the guard', dirty: null }

describe('parseArgs', () => {
    it('defaults to a diff-selected run', () => {
        assert.deepEqual(parseArgs([]), { all: false, jobs: null, base: null, dryRun: false, comment: false, commitStatus: false, branch: null, worktreePath: null, help: false })
    })

    it('reads --jobs as a list and --base as a ref', () => {
        const opts = parseArgs(['--jobs', 'pint, pest', '--base', 'dev', '--dry-run', '--comment'])
        assert.deepEqual(opts.jobs, ['pint', 'pest'])
        assert.equal(opts.base, 'dev')
        assert.equal(opts.dryRun, true)
        assert.equal(opts.comment, true)
    })

    it('refuses an unknown option, a missing value, and --all with --jobs', () => {
        assert.throws(() => parseArgs(['--nope', 'x']), /Unknown option: --nope/)
        assert.throws(() => parseArgs(['--jobs']), /requires a value/)
        assert.throws(() => parseArgs(['--jobs', '--all']), /requires a value/)
        assert.throws(() => parseArgs(['--all', '--jobs', 'pint']), /exclude each other/)
    })
})

describe('parseConfig', () => {
    it('normalizes a full config', () => {
        const config = parseConfig(JSON.stringify(CONFIG))
        assert.equal(config.base, 'main')
        assert.equal(config.php, '8.2')
        assert.equal(config.chain, false)
        assert.equal(config.deny.length, 2)
        assert.ok(config.deny[0] instanceof RegExp)
        assert.deepEqual(config.jobs.map((j) => j.name), ['pint', 'pest', 'lint', 'docs'])
        assert.equal(config.jobs[2].always, true)
    })

    it('defaults base to main, chain to false, and php to null', () => {
        const config = parseConfig({ jobs: [] })
        assert.equal(config.base, 'main')
        assert.equal(config.chain, false)
        assert.equal(config.php, null)
        assert.deepEqual(config.deny, [])
    })

    it('lets an each-only job select itself by the same globs', () => {
        const config = parseConfig({ jobs: [{ name: 'lint', run: 'php -l', each: ['**/*.php'] }] })
        assert.deepEqual(config.jobs[0].paths, ['**/*.php'])
        assert.deepEqual(config.jobs[0].each, ['**/*.php'])
    })

    it('names the key that is wrong', () => {
        assert.throws(() => parseConfig({ jobs: 'pint' }), /"jobs" must be an array/)
        assert.throws(() => parseConfig({ jobs: [{ name: 'pint' }] }), /jobs\[0\]\.run/)
        assert.throws(() => parseConfig({ jobs: [{ name: 'a b', run: 'x' }] }), /jobs\[0\]\.name/)
        assert.throws(() => parseConfig({ jobs: [{ name: 'a', run: 'x' }, { name: 'a', run: 'y' }] }), /used twice/)
        assert.throws(() => parseConfig({ php: 8, jobs: [] }), /"php" must be a version/)
        assert.throws(() => parseConfig({ chain: 'yes', jobs: [] }), /"chain" must be true or false/)
        assert.throws(() => parseConfig({ guard: { deny: ['('] }, jobs: [] }), /not a valid regular expression/)
        assert.throws(() => parseConfig({ guard: { deny: ['x'] }, jobs: [{ name: 'guard', run: 'x' }] }), /reserved/)
    })

    it('allows a job named guard when there is no guard.deny', () => {
        assert.equal(parseConfig({ jobs: [{ name: 'guard', run: 'x' }] }).jobs[0].name, 'guard')
    })
})

describe('globs', () => {
    it('** crosses directories and * stays inside one', () => {
        assert.equal(matchesGlob('a.php', '**/*.php'), true)
        assert.equal(matchesGlob('src/a.php', '**/*.php'), true)
        assert.equal(matchesGlob('src/deep/er/a.php', '**/*.php'), true)
        assert.equal(matchesGlob('src/a.php', '*.php'), false)
        assert.equal(matchesGlob('a.php', '*.php'), true)
        assert.equal(matchesGlob('src/a.php', 'src/*.php'), true)
        assert.equal(matchesGlob('src/x/a.php', 'src/*.php'), false)
        assert.equal(matchesGlob('src/x/a.php', 'src/**/*.php'), true)
        assert.equal(matchesGlob('src/a.php', 'src/**/*.php'), true)
    })

    it('matches a suffix wildcard, ? and {a,b}', () => {
        assert.equal(matchesGlob('phpunit.xml.dist', 'phpunit.xml*'), true)
        assert.equal(matchesGlob('phpunit.xml', 'phpunit.xml*'), true)
        assert.equal(matchesGlob('a1.php', 'a?.php'), true)
        assert.equal(matchesGlob('a12.php', 'a?.php'), false)
        assert.equal(matchesGlob('rector.php', '{rector,pint}.{php,json}'), true)
        assert.equal(matchesGlob('pint.json', '{rector,pint}.{php,json}'), true)
        assert.equal(matchesGlob('other.json', '{rector,pint}.{php,json}'), false)
    })

    it('a leading ! excludes, and one exclusion outranks every match', () => {
        const { selected, skipped } = selectJobs(
            parseConfig({ jobs: [{ name: 'lint', run: 'php -l', each: ['**/*.php', '!vendor/**'] }] }),
            ['vendor/autoload.php', 'vendor/x/y.php'],
            {},
        )
        assert.deepEqual(selected, [])
        assert.equal(skipped[0].reason, 'no changed file matches **/*.php, !vendor/**')
        assert.equal(selectJobs(parseConfig({ jobs: [{ name: 'lint', run: 'php -l', each: ['**/*.php', '!vendor/**'] }] }), ['src/a.php'], {}).selected[0].reason, 'src/a.php matches **/*.php')
    })

    it('escapes regex characters and reads Windows separators as /', () => {
        assert.equal(matchesGlob('a.php', 'a.php'), true)
        assert.equal(matchesGlob('axphp', 'a.php'), false)
        assert.equal(matchesGlob('src\\a.php', '**/*.php'), true)
        assert.equal(matchesGlob('src/a.php', './src/*.php'), true)
        assert.equal(globToRegExp('a+b').test('a+b'), true)
    })
})

describe('selectJobs', () => {
    const config = parseConfig(CONFIG)

    it('puts the guard first whenever guard.deny is set', () => {
        const { selected } = selectJobs(config, [], {})
        assert.equal(selected[0].name, 'guard')
        assert.equal(selected[0].guard, true)
    })

    it('omits the guard when there is no guard.deny', () => {
        const { selected } = selectJobs(parseConfig({ jobs: CONFIG.jobs }), [], {})
        assert.deepEqual(selected.map((e) => e.name), ['lint'])
    })

    it('selects by changed path, always, and reports why each job was skipped', () => {
        const { selected, skipped } = selectJobs(config, ['README.md', 'src/Foo.php'], {})
        assert.deepEqual(selected.map((e) => e.name), ['guard', 'pint', 'pest', 'lint'])
        assert.equal(selected[1].reason, 'src/Foo.php matches **/*.php')
        assert.equal(selected[3].reason, 'always: true')
        assert.deepEqual(skipped, [{ name: 'docs', reason: 'no changed file matches docs/**/*.md' }])
    })

    it('selects nothing but the guard and always-jobs for an unrelated diff', () => {
        const { selected, skipped } = selectJobs(config, ['README.md'], {})
        assert.deepEqual(selected.map((e) => e.name), ['guard', 'lint'])
        assert.deepEqual(skipped.map((e) => e.name), ['pint', 'pest', 'docs'])
    })

    it('--all selects every job and --jobs selects the named ones plus the guard', () => {
        assert.deepEqual(selectJobs(config, [], { all: true }).selected.map((e) => e.name), ['guard', 'pint', 'pest', 'lint', 'docs'])
        const named = selectJobs(config, ['src/Foo.php'], { jobs: ['docs'] })
        assert.deepEqual(named.selected.map((e) => e.name), ['guard', 'docs'])
        assert.deepEqual(named.skipped.map((e) => e.reason), ['not named by --jobs', 'not named by --jobs', 'not named by --jobs'])
    })

    it('refuses a --jobs name the config does not define', () => {
        assert.throws(() => selectJobs(config, [], { jobs: ['pint', 'phpstan'] }), /phpstan, which local-ci\.json does not define/)
    })

    it('accounts for every configured job exactly once', () => {
        for (const opts of [{}, { all: true }, { jobs: ['pint'] }]) {
            const { selected, skipped } = selectJobs(config, ['a.php'], opts)
            const names = [...selected, ...skipped].map((e) => e.name).filter((n) => n !== 'guard').sort()
            assert.deepEqual(names, ['docs', 'lint', 'pest', 'pint'])
        }
    })
})

describe('guardViolations', () => {
    it('names every tracked file a deny pattern matches', () => {
        const { deny } = parseConfig(CONFIG)
        const tracked = ['src/a.php', 'sentinel-key.php', 'keys/sentinel-key.php', 'dump.sql', 'dump.sql.gz', 'notes.sqlite', 'sentinel-key.php.example']
        assert.deepEqual(guardViolations(tracked, deny), ['sentinel-key.php', 'keys/sentinel-key.php', 'dump.sql', 'dump.sql.gz'])
    })

    it('matches case-insensitively, as the hook does, and still leaves an ordinary file alone (control)', () => {
        const { deny } = parseConfig(CONFIG)
        const tracked = ['DUMP.SQL', 'dump.Sql', 'Dump.Sql.Gz', 'Sentinel-Key.PHP', 'notes.txt']
        assert.deepEqual(guardViolations(tracked, deny), ['DUMP.SQL', 'dump.Sql', 'Dump.Sql.Gz', 'Sentinel-Key.PHP'])
    })
})

describe('each targets, file arguments, and git status parsing', () => {
    it('an each job skips a tracked file that is no longer on disk', () => {
        const present = new Set(['src/a.php'])
        assert.deepEqual(eachTargets(['src/a.php', 'src/gone.php', 'README.md'], ['**/*.php'], (f) => present.has(f)), ['src/a.php'])
    })

    it('a file name starting with - is passed as a path, not an option', () => {
        assert.equal(fileArgument('-v.php'), './-v.php')
        assert.equal(fileArgument('src/-v.php'), 'src/-v.php')
        assert.equal(fileArgument('a.php'), 'a.php')
    })

    it('reads -z porcelain entries, deletions included, with names git would quote', () => {
        assert.deepEqual(parseStatusZ([' D src/Foo.php', 'D  gone.php', '?? a"b.sql', 'M  tab\tx.php']), [
            { code: ' D', path: 'src/Foo.php' },
            { code: 'D ', path: 'gone.php' },
            { code: '??', path: 'a"b.sql' },
            { code: 'M ', path: 'tab\tx.php' },
        ])
    })

    it('no merge base refuses a diff-selected run and lets --all and --jobs proceed', () => {
        const changed = { files: [], against: 'origin/main', mergeBase: null }
        assert.throws(() => requireMergeBase(changed, {}), /share no history.*git fetch --unshallow/s)
        assert.match(requireMergeBase(changed, { all: true }), /--all/)
        assert.match(requireMergeBase(changed, { jobs: ['pint'] }), /--jobs/)
        assert.equal(requireMergeBase({ ...changed, mergeBase: 'abc' }, {}), null)
    })

    it('an unresolved base is a warning naming it under --all and --jobs, and a refusal otherwise', () => {
        const changed = { files: [], against: 'origin/main', mergeBase: null, unresolved: 'main' }
        assert.match(requireMergeBase(changed, { all: true }), /Neither origin[/]main nor main resolves.*--all/s)
        assert.match(requireMergeBase(changed, { jobs: ['pint'] }), /Neither origin[/]main nor main resolves.*--jobs/s)
    })
})

describe('runJobs, exitStatus and the verdict', () => {
    const config = parseConfig(CONFIG)
    const selection = selectJobs(config, ['src/Foo.php'], {}) // guard, pint, pest, lint; docs skipped
    const fake = (codes) => (entry) => ({ code: codes[entry.name] ?? 0 })
    const clock = () => { let t = 0; return () => (t += 1000) }

    it('a failing job fails the run with chain: false, and every other selected job still runs', () => {
        const results = runJobs(selection, { chain: false, execute: fake({ pint: 1 }), now: clock() })
        assert.deepEqual(results.map((r) => [r.name, r.status]), [['guard', 'PASS'], ['pint', 'FAIL'], ['pest', 'PASS'], ['lint', 'PASS'], ['docs', 'SKIPPED']])
        assert.equal(exitStatus(results), 1)
        assert.match(verdictOf(results), /^FAIL: 1 of 4 jobs run failed: pint$/)
    })

    it('a failing job fails the run with chain: true, and the later jobs are SKIPPED naming it', () => {
        const results = runJobs(selection, { chain: true, execute: fake({ pint: 2 }), now: clock() })
        assert.deepEqual(results.map((r) => [r.name, r.status]), [['guard', 'PASS'], ['pint', 'FAIL'], ['pest', 'SKIPPED'], ['lint', 'SKIPPED'], ['docs', 'SKIPPED']])
        assert.equal(results[2].reason, 'chain: stopped after pint failed')
        assert.equal(results[4].reason, 'not selected: no changed file matches docs/**/*.md')
        assert.equal(exitStatus(results), 1)
        assert.match(verdictOf(results), /^FAIL: 1 of 2 jobs run failed: pint$/)
    })

    it('a guard failure fails the run under both chain settings', () => {
        for (const chain of [false, true]) {
            const results = runJobs(selection, { chain, execute: fake({ guard: 1 }), now: clock() })
            assert.equal(results[0].status, 'FAIL')
            assert.equal(exitStatus(results), 1)
        }
    })

    it('every job passing exits 0 and the verdict says PASS', () => {
        const results = runJobs(selection, { chain: false, execute: fake({}), now: clock() })
        assert.equal(exitStatus(results), 0)
        assert.equal(verdictOf(results), 'PASS: 4 of 4 jobs run passed')
        assert.equal(results[1].ms, 1000)
    })

    it('a run in which no job ran exits 0 but never says PASS, in the summary or the comment', () => {
        const results = runJobs({ selected: [], skipped: [{ name: 'docs', reason: 'x' }] }, { chain: false, execute: fake({}) })
        assert.equal(exitStatus(results), 0)
        assert.equal(verdictOf(results), 'NOTHING RAN: no job was selected, so nothing was checked')
        assert.doesNotMatch(formatSummary(tree, results), /PASS/)
        const body = commentBody(tree, results, { platform: 'darwin', when: new Date(0) })
        assert.match(body, /^\*\*local-ci: NOTHING RAN\*\* on /)
        assert.doesNotMatch(body.split('\n')[0], /PASS/)
    })

    it('the exit status and the summary verdict agree in both directions', () => {
        // Every combination of outcomes for the four selected jobs, under both chain settings.
        for (let mask = 0; mask < 16; mask++) {
            const codes = {}
            selection.selected.forEach((entry, i) => { codes[entry.name] = (mask >> i) & 1 })
            for (const chain of [false, true]) {
                const results = runJobs(selection, { chain, execute: fake(codes), now: clock() })
                const summary = formatSummary(tree, results)
                const status = exitStatus(results)
                const verdictLine = summary.split('\n').find((l) => l.startsWith('  Verdict:'))
                if (status === 0) {
                    assert.match(verdictLine, /Verdict:  PASS/, summary)
                    assert.equal(results.some((r) => r.status === 'FAIL'), false)
                } else {
                    assert.match(verdictLine, /Verdict:  FAIL/, summary)
                    assert.equal(results.some((r) => r.status === 'FAIL'), true)
                }
                // And the other direction: the words imply the number.
                assert.equal(/Verdict:  (PASS|NOTHING RAN)/.test(verdictLine) ? 0 : 1, status)
            }
        }
    })
})

describe('formatSummary and commentBody', () => {
    const results = [
        { name: 'guard', status: 'PASS', ms: 120, code: 0 },
        { name: 'pint', status: 'FAIL', ms: 3200, code: 1 },
        { name: 'pest', status: 'SKIPPED', ms: 0, reason: 'chain: stopped after pint failed' },
        { name: 'docs', status: 'SKIPPED', ms: 0, reason: 'not selected: no changed file matches docs/**/*.md' },
    ]

    it('names the commit, each job with its status and duration, and the verdict', () => {
        const summary = formatSummary(tree, results)
        assert.match(summary, new RegExp(`Commit:   ${tree.sha} Add the guard`))
        assert.match(summary, /Tree:     clean: this verdict describes the commit/)
        assert.match(summary, /guard\s+PASS\s+0\.1s/)
        assert.match(summary, /pint\s+FAIL\s+3\.2s\s+\(exit 1\)/)
        assert.match(summary, /pest\s+SKIPPED\s+\(chain: stopped after pint failed\)/)
        assert.match(summary, /docs\s+SKIPPED\s+\(not selected: no changed file matches docs\/\*\*\/\*\.md\)/)
        assert.match(summary, /Verdict:  FAIL: 1 of 2 jobs run failed: pint/)
        assert.doesNotMatch(summary, /\x1b\[/, 'no color codes unless asked')
    })

    it('says plainly when the tree is dirty', () => {
        const summary = formatSummary({ ...tree, dirty: { tracked: 2, untracked: 1 } }, results)
        assert.match(summary, /Tree:     DIRTY \(2 tracked changes, 1 untracked file\): this verdict describes the working tree, not the commit/)
    })

    it('the comment names the full SHA, the jobs that ran, and the jobs that did not', () => {
        const body = commentBody(tree, results, { platform: 'darwin', when: new Date('2026-10-02T15:00:00Z') })
        assert.match(body, new RegExp(`^\\*\\*local-ci: FAIL\\*\\* on \`${tree.sha}\`: Add the guard`))
        assert.match(body, /\| `guard` \| PASS \| 0\.1s \|/)
        assert.match(body, /\| `pint` \| FAIL \(exit 1\) \| 3\.2s \|/)
        assert.doesNotMatch(body, /\| `pest` \|/)
        assert.match(body, /Not run: `pest` \(chain: stopped after pint failed\); `docs` \(not selected/)
        assert.match(body, /darwin, 2026-10-02T15:00:00\.000Z/)
        assert.match(body, /RUN_REMOTE_CI/)
    })

    it('formats durations', () => {
        assert.equal(formatDuration(0), '0.0s')
        assert.equal(formatDuration(59_949), '59.9s')
        assert.equal(formatDuration(63_000), '1m 03s')
        assert.equal(formatDuration(600_000), '10m 00s')
    })
})

describe('commands and PHP', () => {
    it('recognizes which commands run under PHP', () => {
        assert.equal(needsPhp('php -l'), true)
        assert.equal(needsPhp('vendor/bin/pint --test'), true)
        assert.equal(needsPhp('vendor\\bin\\pest'), true)
        assert.equal(needsPhp('  php scripts/check.php'), true)
        assert.equal(needsPhp('composer validate'), false)
        assert.equal(needsPhp('node --test "scripts/*.test.mjs"'), false)
        assert.equal(needsPhp('phpstan analyse'), false)
    })

    it('rewrites a PHP command onto the resolved interpreter, POSIX quoting', () => {
        assert.equal(commandFor('php -l', '/usr/bin/php', 'darwin'), '/usr/bin/php -l')
        assert.equal(commandFor('vendor/bin/pint --test', '/Users/me/Library/Application Support/Herd/bin/php82', 'darwin'), "'/Users/me/Library/Application Support/Herd/bin/php82' vendor/bin/pint --test")
        assert.equal(commandFor('composer validate', '/usr/bin/php', 'darwin'), 'composer validate')
    })

    it('rewrites with Windows quoting on win32', () => {
        assert.equal(commandFor('vendor/bin/pest', 'C:\\Program Files\\Herd\\php.exe', 'win32'), '"C:\\Program Files\\Herd\\php.exe" vendor/bin/pest')
        assert.equal(commandFor('php -l', 'C:\\Herd\\php.exe', 'win32'), 'C:\\Herd\\php.exe -l')
    })

    it('shellQuote protects spaces and quotes on each platform', () => {
        assert.equal(shellQuote('plain/path.php', 'darwin'), 'plain/path.php')
        assert.equal(shellQuote("it's here.php", 'darwin'), "'it'\\''s here.php'")
        assert.equal(shellQuote('a b', 'win32'), '"a b"')
        assert.equal(shellQuote('say "hi"', 'win32'), '"say \\"hi\\""')
    })

    it('prefers LOCAL_CI_PHP, then the Herd pinned binary on macOS, and nothing else before PATH', () => {
        const darwin = phpCandidates({ version: '8.2', env: { LOCAL_CI_PHP: '/opt/php' }, platform: 'darwin', home: '/Users/me' })
        assert.deepEqual(darwin.map((c) => c.source), ['$LOCAL_CI_PHP', 'Herd php82'])
        assert.equal(darwin[1].path, '/Users/me/Library/Application Support/Herd/bin/php82')
        assert.deepEqual(phpCandidates({ version: '8.4', env: {}, platform: 'darwin', home: '/Users/me' }).map((c) => c.source), ['Herd php84'])
        assert.deepEqual(phpCandidates({ version: null, env: {}, platform: 'darwin', home: '/Users/me' }), [])
        assert.deepEqual(phpCandidates({ version: '8.2', env: {}, platform: 'linux', home: '/home/me' }), [])
        assert.deepEqual(phpCandidates({ version: '8.2', env: {}, platform: 'win32', home: 'C:\\Users\\me' }), [])
    })
})

// --- end-to-end: the runner and the hook against temporary repositories -----
//
// These run the real local-ci.mjs (copied into each scratch repository, since
// the runner finds the repository from its own location) and the real
// templates/pre-commit, so they cover the git plumbing the pure tests cannot.

const HERE = dirname(fileURLToPath(import.meta.url))
const RUNNER = join(HERE, 'local-ci.mjs')
const HOOK = join(HERE, 'templates', 'pre-commit')
const POSIX = process.platform !== 'win32'
const scratch = []
after(() => {
    for (const dir of scratch) {
        rmSync(dir, { recursive: true, force: true })
    }
})

function git(cwd, ...args) {
    const res = spawnSync('git', args, { cwd, encoding: 'utf8' })
    if (res.status !== 0) {
        throw new Error(`git ${args.join(' ')} failed: ${res.stderr}`)
    }
    return res.stdout
}

function newRepo(dir) {
    mkdirSync(dir, { recursive: true })
    git(dir, 'init', '-q', '-b', 'main')
    git(dir, 'config', 'user.email', 'test@example.com')
    git(dir, 'config', 'user.name', 'test')
    git(dir, 'config', 'commit.gpgsign', 'false')
}

// A repository with an origin, `files` committed on main and pushed, and a
// `feature` branch checked out. Returns the work tree.
function scratchRepo(config, files) {
    const root = mkdtempSync(join(tmpdir(), 'local-ci-test-'))
    scratch.push(root)
    const work = join(root, 'work')
    git(root, 'init', '-q', '--bare', '-b', 'main', 'origin.git')
    newRepo(work)
    mkdirSync(join(work, '.claude', 'skills', 'local-ci'), { recursive: true })
    copyFileSync(RUNNER, join(work, '.claude', 'skills', 'local-ci', 'local-ci.mjs'))
    writeFileSync(join(work, 'local-ci.json'), JSON.stringify(config))
    for (const [name, content] of Object.entries(files)) {
        mkdirSync(dirname(join(work, name)), { recursive: true })
        writeFileSync(join(work, name), content)
    }
    git(work, 'add', '-A')
    git(work, 'commit', '-q', '-m', 'init')
    git(work, 'remote', 'add', 'origin', join(root, 'origin.git'))
    git(work, 'push', '-q', 'origin', 'main')
    git(work, 'checkout', '-q', '-b', 'feature')
    return work
}

function runCi(work, args = [], env = {}) {
    const res = spawnSync(process.execPath, ['.claude/skills/local-ci/local-ci.mjs', ...args], {
        cwd: work,
        encoding: 'utf8',
        env: { ...process.env, NO_COLOR: '1', ...env },
        maxBuffer: Infinity,
    })
    return { code: res.status, out: `${res.stdout}${res.stderr}` }
}

// A job that fails when src/Foo.txt is missing: a stand-in for code that
// requires a file the branch deletes.
const requiresFoo = { name: 'smoke', run: 'node -e "process.exit(require(\'fs\').existsSync(\'src/Foo.txt\') ? 0 : 1)"', paths: ['src/**'] }
const readsEach = { name: 'read', run: 'node -e "require(\'fs\').readFileSync(process.argv[1])"', each: ['src/**'] }

describe('end to end: selection from the branch diff', () => {
    it('a committed deletion selects the jobs whose paths match it, and the run fails', () => {
        const work = scratchRepo({ jobs: [requiresFoo] }, { 'src/Foo.txt': 'foo\n', 'src/Bar.txt': 'bar\n' })
        git(work, 'rm', '-q', 'src/Foo.txt')
        git(work, 'commit', '-q', '-m', 'delete Foo')
        const { code, out } = runCi(work)
        assert.match(out, /Changed: +1 file/, out)
        assert.match(out, /smoke +FAIL/, out)
        assert.equal(code, 1, out)
    })

    it('an uncommitted deletion selects too, and an each job skips the file that is gone', () => {
        const work = scratchRepo({ jobs: [requiresFoo, readsEach] }, { 'src/Foo.txt': 'foo\n', 'src/Bar.txt': 'bar\n' })
        unlinkSync(join(work, 'src/Foo.txt'))
        const { code, out } = runCi(work)
        assert.match(out, /smoke +FAIL/, out)
        assert.match(out, /1 of 1 passed/, out)
        assert.match(out, /read +PASS/, out)
        assert.equal(code, 1, out)
    })

    it('a shallow clone with no merge base refuses rather than selecting nothing, and --all still runs', () => {
        const work = scratchRepo({ jobs: [requiresFoo] }, { 'src/Foo.txt': 'foo\n' })
        git(work, 'rm', '-q', 'src/Foo.txt')
        git(work, 'commit', '-q', '-m', 'delete Foo')
        git(work, 'push', '-q', 'origin', 'feature')
        const shallow = join(dirname(work), 'shallow')
        const originUrl = new URL(`file://${join(dirname(work), 'origin.git')}`).href
        git(dirname(work), 'clone', '-q', '--depth', '1', '--branch', 'feature', originUrl, shallow)
        git(shallow, 'fetch', '-q', '--depth', '1', 'origin', 'main:refs/remotes/origin/main')
        const refused = runCi(shallow)
        assert.equal(refused.code, 1, refused.out)
        assert.match(refused.out, /share no history/, refused.out)
        assert.match(refused.out, /git fetch --unshallow/, refused.out)
        assert.doesNotMatch(refused.out, /Verdict/, refused.out)
        const all = runCi(shallow, ['--all'])
        assert.match(all.out, /smoke +FAIL/, all.out)
        assert.equal(all.code, 1, all.out)
    })

    it('a single-branch clone with no base ref refuses a diff-selected run, and --all and --jobs run', () => {
        const work = scratchRepo({ jobs: [requiresFoo, { name: 'other', run: 'node -e "process.exit(0)"', paths: ['nothing/**'] }] }, { 'src/Foo.txt': 'foo\n' })
        git(work, 'push', '-q', 'origin', 'feature')
        const single = join(dirname(work), 'single')
        const originUrl = new URL(`file://${join(dirname(work), 'origin.git')}`).href
        git(dirname(work), 'clone', '-q', '--single-branch', '--branch', 'feature', originUrl, single)
        assert.equal(spawnSync('git', ['rev-parse', '--verify', '--quiet', 'origin/main'], { cwd: single }).status, 1, 'the clone must not have origin/main')
        assert.equal(spawnSync('git', ['rev-parse', '--verify', '--quiet', 'main'], { cwd: single }).status, 1, 'the clone must not have a local main')
        const refused = runCi(single)
        assert.equal(refused.code, 1, refused.out)
        assert.match(refused.out, /Neither origin[/]main nor main resolves.*--all/s, refused.out)
        assert.doesNotMatch(refused.out, /Verdict/, refused.out)
        const all = runCi(single, ['--all'])
        assert.match(all.out, /Neither origin[/]main nor main resolves, so the branch's changes were not read/, all.out)
        assert.match(all.out, /Changed: +not read/, all.out)
        assert.match(all.out, /smoke +PASS/, all.out)
        assert.match(all.out, /other +PASS/, all.out)
        assert.equal(all.code, 0, all.out)
        const named = runCi(single, ['--jobs', 'smoke'])
        assert.match(named.out, /smoke +PASS/, named.out)
        assert.equal(named.code, 0, named.out)
    })

    it('an each job passes a file whose name starts with - as a path', { skip: !POSIX && 'POSIX shell quoting' }, () => {
        const work = scratchRepo({ jobs: [{ name: 'argv', run: 'node -e "process.exit(process.argv[1].startsWith(\'-\') ? 3 : 0)" --', each: ['*.txt'], always: true }] }, { '-v.txt': 'x\n' })
        const { code, out } = runCi(work)
        assert.match(out, /argv +PASS/, out)
        assert.equal(code, 0, out)
    })

    it('an each job whose output passes 1 MB is judged by its exit status', () => {
        const work = scratchRepo({ jobs: [{ name: 'loud', run: 'node -e "process.stdout.write(\'x\'.repeat(2000000))"', each: ['*.txt'], always: true }] }, { 'a.txt': 'x\n' })
        const { code, out } = runCi(work)
        assert.doesNotMatch(out, /ENOBUFS/, out.slice(0, 2000))
        assert.match(out, /loud +PASS/, out.slice(-2000))
        assert.equal(code, 0)
    })

    it('a LOCAL_CI_PHP that does not exist is an error, not a fall-through', () => {
        const work = scratchRepo({ php: '8.2', jobs: [{ name: 'v', run: 'php -v', always: true }] }, { 'a.txt': 'x\n' })
        const { code, out } = runCi(work, [], { LOCAL_CI_PHP: join(tmpdir(), 'no-such-dir', 'phpp') })
        assert.match(out, /LOCAL_CI_PHP points at .*phpp, which does not exist/, out)
        assert.equal(code, 1, out)
    })
})

describe('end to end: the guard reads every tracked path unquoted', () => {
    it('denies files whose names git would C-quote', { skip: !POSIX && 'tab and quote are not legal Windows file names' }, () => {
        const work = scratchRepo({ guard: { deny: ['\\.sql$'] }, jobs: [] }, { 'a"b.sql': '', 'back\\slash.sql': '', 'tab\tdump.sql': '', 'README.md': 'x\n' })
        const { code, out } = runCi(work)
        assert.match(out, /3 tracked files match a guard\.deny pattern/, out)
        assert.match(out, /denied: a"b\.sql/, out)
        assert.match(out, /denied: tab\tdump\.sql/, out)
        assert.equal(code, 1, out)
    })
})

describe('end to end: --comment', { skip: !POSIX && 'the fake gh is a POSIX shell script' }, () => {
    // A fake gh on PATH: `pr view` prints $FAKE_VIEW, every call is logged.
    function withFakeGh(work, view) {
        const bin = join(dirname(work), 'bin')
        mkdirSync(bin, { recursive: true })
        writeFileSync(join(bin, 'gh'), '#!/bin/sh\necho "gh $*" >> "$FAKE_LOG"\nif [ "$1" = pr ] && [ "$2" = view ]; then printf \'%s\\n\' "$FAKE_VIEW"; fi\nexit 0\n')
        chmodSync(join(bin, 'gh'), 0o755)
        const log = join(dirname(work), 'gh.log')
        writeFileSync(log, '')
        const res = runCi(work, ['--comment'], { PATH: `${bin}:${process.env.PATH}`, FAKE_LOG: log, FAKE_VIEW: view })
        return { ...res, calls: readFileSync(log, 'utf8') }
    }

    it('does not post on a merged pull request', () => {
        const work = scratchRepo({ jobs: [{ name: 'ok', run: 'node -e "0"', always: true }] }, { 'a.txt': 'x\n' })
        const head = git(work, 'rev-parse', 'HEAD').trim()
        const { code, out, calls } = withFakeGh(work, JSON.stringify({ number: 5, state: 'MERGED', headRefOid: head, url: 'https://example/pull/5' }))
        assert.match(out, /comment not posted: .*#5 is merged/, out)
        assert.doesNotMatch(calls, /pr comment/, calls)
        assert.equal(code, 0, out)
    })

    it('posts on an open pull request', () => {
        const work = scratchRepo({ jobs: [{ name: 'ok', run: 'node -e "0"', always: true }] }, { 'a.txt': 'x\n' })
        const head = git(work, 'rev-parse', 'HEAD').trim()
        const { code, out, calls } = withFakeGh(work, JSON.stringify({ number: 6, state: 'OPEN', headRefOid: head, url: 'https://example/pull/6' }))
        assert.match(calls, /gh pr comment 6 --body-file/, calls)
        assert.match(out, /Comment posted on https:\/\/example\/pull\/6/, out)
        assert.equal(code, 0, out)
    })

    it('a reply it cannot parse warns and leaves a PASS exit status alone', () => {
        const work = scratchRepo({ jobs: [{ name: 'ok', run: 'node -e "0"', always: true }] }, { 'a.txt': 'x\n' })
        const { code, out } = withFakeGh(work, 'not json')
        assert.match(out, /Verdict: +PASS/, out)
        assert.match(out, /comment not posted:/, out)
        assert.equal(code, 0, out)
    })
})

describe('end to end: the pre-commit hook', { skip: !POSIX && 'the hook is run through sh' }, () => {
    // Commit `file` in a repository whose hook is templates/pre-commit and
    // whose local-ci.json is `config`. Returns the commit's exit status.
    function commitWithHook(config, file, env = {}) {
        const root = mkdtempSync(join(tmpdir(), 'local-ci-hook-'))
        scratch.push(root)
        newRepo(root)
        mkdirSync(join(root, '.githooks'))
        copyFileSync(HOOK, join(root, '.githooks', 'pre-commit'))
        chmodSync(join(root, '.githooks', 'pre-commit'), 0o755)
        writeFileSync(join(root, 'local-ci.json'), typeof config === 'string' ? config : JSON.stringify(config))
        git(root, 'add', '-A')
        git(root, 'commit', '-q', '--no-verify', '-m', 'init')
        git(root, 'config', 'core.hooksPath', '.githooks')
        writeFileSync(join(root, file), '')
        git(root, 'add', '--', file)
        const res = spawnSync('git', ['commit', '-q', '-m', `add ${file}`], { cwd: root, encoding: 'utf8', env: { ...process.env, ...env } })
        return { code: res.status, out: `${res.stdout}${res.stderr}` }
    }

    const NO_NODE = { PATH: '/usr/bin:/bin' }
    const lacksNode = spawnSync('sh', ['-c', 'command -v node'], { env: NO_NODE }).status !== 0
    const hasGit = spawnSync('sh', ['-c', 'command -v git'], { env: NO_NODE }).status === 0
    const fallback = { skip: !(lacksNode && hasGit) && 'needs a PATH with git and without node' }

    it('refuses a denied file, and allows an ordinary one (control)', () => {
        assert.equal(commitWithHook({ guard: { deny: ['\\.sql$'] }, jobs: [] }, 'dump.sql').code, 1)
        assert.equal(commitWithHook({ guard: { deny: ['\\.sql$'] }, jobs: [] }, 'notes.txt').code, 0)
    })

    it('with node, refuses when guard.deny is not an array', () => {
        const { code, out } = commitWithHook({ guard: { deny: '\\.sql$' }, jobs: [] }, 'dump.sql')
        assert.match(out, /guard\.deny .* must be an array/, out)
        assert.equal(code, 1, out)
    })

    it('without node, refuses a pattern containing ] rather than truncating the list', fallback, () => {
        const { code, out } = commitWithHook({ guard: { deny: ['a]b', '\\.sql$'] }, jobs: [] }, 'dump.sql', NO_NODE)
        assert.match(out, /could not read guard\.deny/, out)
        assert.equal(code, 1, out)
        assert.equal(commitWithHook({ guard: { deny: ['[.]sql$'] }, jobs: [] }, 'dump.sql', NO_NODE).code, 1)
    })

    it('without node, refuses when grep -E cannot compile a pattern, even for a file it would not match', fallback, () => {
        const { code, out } = commitWithHook({ guard: { deny: ['('] }, jobs: [] }, 'notes.txt', NO_NODE)
        assert.match(out, /grep -E rejected the pattern/, out)
        assert.equal(code, 1, out)
    })

    it('without node, allows a commit under an empty deny list, and still refuses a denied file (control)', fallback, () => {
        assert.equal(commitWithHook({ guard: { deny: [] }, jobs: [] }, 'notes.txt', NO_NODE).code, 0)
        assert.equal(commitWithHook({ guard: { deny: ['\\.sql$'] }, jobs: [] }, 'dump.sql', NO_NODE).code, 1)
        assert.equal(commitWithHook({ guard: { deny: ['\\.sql$'] }, jobs: [] }, 'notes.txt', NO_NODE).code, 0)
    })

    const template = readFileSync(join(HERE, 'templates', 'local-ci.json'), 'utf8')
    const templateDenied = ['DUMP.SQL', 'dump.sql.gz', 'Export.CSV', 'cert.PEM', 'site.KEY', 'dump.Sql', 'Cert.Pem', 'Data.Xlsx']

    it('the template denies upper-case extensions with node, naming the file, and allows an ordinary file (control)', () => {
        for (const file of templateDenied) {
            const { code, out } = commitWithHook(template, file)
            assert.equal(code, 1, `${file}: ${out}`)
            assert.match(out, new RegExp(`^ +${file.replace(/\./g, '\\.')} +\\(pattern: `, 'm'), out)
        }
        assert.equal(commitWithHook(template, 'notes.txt').code, 0)
    })

    it('the template is readable without node: an ordinary commit passes and upper-case extensions are refused by name', fallback, () => {
        const allowed = commitWithHook(template, 'notes.txt', NO_NODE)
        assert.equal(allowed.code, 0, allowed.out)
        for (const file of templateDenied) {
            const { code, out } = commitWithHook(template, file, NO_NODE)
            assert.equal(code, 1, `${file} without node: ${out}`)
            assert.match(out, new RegExp(`^ +${file.replace(/\./g, '\\.')} +\\(pattern: `, 'm'), out)
        }
    })
})

describe('requires: a job whose tooling is absent is skipped, never failed and never passed', () => {
    const machine = (present) => ({ exists: (path) => present.includes(path), hasBin: (name) => present.includes(`bin:${name}`) })
    const spellJob = { name: 'spell', run: 'npm run spell', paths: ['**/*.md'], requires: ['cspell.json', 'bin:npm'] }
    const toolless = () => unmetRequirements(['cspell.json', 'bin:npm'], machine([]))

    it('parses requires as paths and bin: entries, and defaults to none', () => {
        const [plain, withRequires] = parseConfig({ jobs: [{ name: 'a', run: 'x' }, { name: 'b', run: 'x', requires: ['./scripts\\check.mjs', 'bin:python', 'node_modules'] }] }).jobs
        assert.deepEqual(plain.requires, [])
        assert.deepEqual(withRequires.requires, ['scripts/check.mjs', 'bin:python', 'node_modules'])
    })

    it('names the entry that is wrong', () => {
        const bad = (requires) => parseConfig({ jobs: [{ name: 'a', run: 'x', requires }] })
        assert.throws(() => bad('cspell.json'), /jobs\[0\]\.requires must be an array/)
        assert.throws(() => bad(['']), /jobs\[0\]\.requires must be an array/)
        assert.throws(() => bad(['/etc/hosts']), /"\/etc\/hosts" must be a path inside the repository/)
        assert.throws(() => bad(['C:\\tools\\x']), /must be a path inside the repository/)
        assert.throws(() => bad(['../sibling/x']), /must be a path inside the repository/)
        assert.throws(() => bad(['a/../../x']), /must be a path inside the repository/)
        assert.throws(() => bad(['bin:']), /"bin:" must be followed by a command name/)
        assert.throws(() => bad(['bin:a b']), /"bin:" must be followed by a command name/)
    })

    it('reports exactly the unmet entries, in the order written, and none on a machine that has them all (control)', () => {
        const requires = ['cspell.json', 'bin:npm', 'node_modules']
        assert.deepEqual(unmetRequirements(requires, machine([])), requires)
        assert.deepEqual(unmetRequirements(requires, machine(['bin:npm'])), ['cspell.json', 'node_modules'])
        assert.deepEqual(unmetRequirements(requires, machine(['cspell.json', 'bin:npm', 'node_modules'])), [])
        assert.deepEqual(unmetRequirements([], machine([])), [])
    })

    it('a path entry never reads as a command, nor a bin: entry as a path', () => {
        assert.deepEqual(unmetRequirements(['npm'], machine(['bin:npm'])), ['npm'])
        assert.deepEqual(unmetRequirements(['bin:npm'], machine(['npm'])), ['bin:npm'])
    })

    it('skips a job the diff selected when its tooling is absent, naming what is missing', () => {
        const config = parseConfig({ jobs: [spellJob] })
        const { selected, skipped } = selectJobs(config, ['README.md'], { unmet: (job) => unmetRequirements(job.requires, machine([])) })
        assert.deepEqual(selected, [])
        assert.equal(skipped.length, 1)
        assert.equal(skipped[0].absent, true)
        assert.equal(skipped[0].reason, 'requires cspell.json, bin:npm, which are absent')
    })

    it('says "is" for one missing entry and runs the job once the tooling is there (control)', () => {
        const config = parseConfig({ jobs: [spellJob] })
        const half = selectJobs(config, ['README.md'], { unmet: (job) => unmetRequirements(job.requires, machine(['bin:npm'])) })
        assert.equal(half.skipped[0].reason, 'requires cspell.json, which is absent')
        const full = selectJobs(config, ['README.md'], { unmet: (job) => unmetRequirements(job.requires, machine(['cspell.json', 'bin:npm'])) })
        assert.deepEqual(full.selected.map((e) => e.name), ['spell'])
        assert.deepEqual(full.skipped, [])
    })

    it('applies to always, --all and --jobs alike, and leaves the guard alone', () => {
        const config = parseConfig({ guard: { deny: ['x'] }, jobs: [{ ...spellJob, always: true }] })
        const unmet = (job) => unmetRequirements(job.requires, machine([]))
        for (const opts of [{}, { all: true }, { jobs: ['spell'] }]) {
            const { selected, skipped } = selectJobs(config, [], { ...opts, unmet })
            assert.deepEqual(selected.map((e) => e.name), ['guard'], JSON.stringify(opts))
            assert.equal(skipped[0].absent, true, JSON.stringify(opts))
        }
    })

    it('a job the diff did not select keeps its "not selected" reason and is not asked about tooling', () => {
        const asked = []
        const config = parseConfig({ jobs: [spellJob] })
        const { skipped } = selectJobs(config, ['src/a.php'], { unmet: (job) => { asked.push(job.name); return [] } })
        assert.deepEqual(asked, [])
        assert.equal(skipped[0].absent, undefined)
        assert.match(skipped[0].reason, /no changed file matches/)
    })

    it('every configured job still appears exactly once', () => {
        const config = parseConfig({ jobs: [spellJob, { name: 'lint', run: 'php -l', always: true }] })
        const { selected, skipped } = selectJobs(config, ['README.md'], { unmet: (job) => (job.name === 'spell' ? toolless() : []) })
        assert.deepEqual([...selected.map((e) => e.name), ...skipped.map((e) => e.name)].sort(), ['lint', 'spell'])
    })

    it('reports the skip as tooling absent, exits 0, and never reads as a pass', () => {
        const config = parseConfig({ jobs: [spellJob] })
        const selection = selectJobs(config, ['README.md'], { unmet: (job) => unmetRequirements(job.requires, machine([])) })
        const results = runJobs(selection, { chain: false, execute: () => assert.fail('a job with absent tooling must not run') })
        assert.equal(results[0].status, 'SKIPPED')
        assert.equal(results[0].reason, 'tooling absent: requires cspell.json, bin:npm, which are absent')
        assert.equal(exitStatus(results), 0)
        assert.match(verdictOf(results), /^NOTHING RAN/)
        assert.doesNotMatch(formatSummary(tree, results), /PASS/)
        assert.doesNotMatch(commentBody(tree, results), /PASS/)
        assert.match(commentBody(tree, results), /Not run: `spell` \(tooling absent: requires cspell\.json/)
    })

    it('a partial tooling skip is PASS (incomplete) in the verdict, the summary and the comment headline, never a bare PASS', () => {
        const results = [
            { name: 'pest', status: 'PASS', ms: 1 },
            { name: 'spell', status: 'SKIPPED', reason: 'tooling absent: requires node_modules, which is absent', ms: 0, absent: true },
        ]
        assert.equal(verdictOf(results), 'PASS (incomplete): 1 of 2 selected jobs ran and passed; spell skipped, tooling absent')
        assert.equal(exitStatus(results), 0)
        assert.match(formatSummary(tree, results), /Verdict:\s+PASS \(incomplete\):/)
        assert.doesNotMatch(formatSummary(tree, results), /Verdict:\s+PASS:/)
        assert.match(commentBody(tree, results), /^\*\*local-ci: PASS \(incomplete\)\*\*/)
        // control: the same pass beside a job that was merely not selected stays a plain PASS
        const plain = [results[0], { name: 'spell', status: 'SKIPPED', reason: 'not selected: no changed file matches', ms: 0 }]
        assert.equal(verdictOf(plain), 'PASS: 1 of 1 job run passed')
        assert.match(commentBody(tree, plain), /^\*\*local-ci: PASS\*\*/)
        // a failure still wins over a tooling skip
        assert.match(verdictOf([{ name: 'a', status: 'FAIL', code: 1, ms: 1 }, results[1]]), /^FAIL/)
    })

    it('an all-skipped run says tooling was absent rather than that no job was selected', () => {
        const results = runJobs(selectJobs(parseConfig({ jobs: [spellJob] }), ['README.md'], { unmet: (job) => unmetRequirements(job.requires, machine([])) }), { chain: false, execute: () => assert.fail('must not run') })
        assert.equal(verdictOf(results), 'NOTHING RAN: 1 selected job skipped, tooling absent (spell), so nothing was checked')
        assert.match(verdictOf([{ name: 'x', status: 'SKIPPED', reason: 'not selected: y', ms: 0 }]), /no job was selected/)
    })

    describe('end to end', () => {
        const failsIfRun = (extra) => ({ name: 'tool', run: 'node -e "process.exit(9)"', always: true, ...extra })

        it('skips a job whose required path is absent, and runs it once the path exists (control)', () => {
            const work = scratchRepo({ jobs: [failsIfRun({ requires: ['node_modules/.bin/cspell'] })] }, { 'a.txt': 'x\n' })
            const skipped = runCi(work)
            assert.match(skipped.out, /tool +SKIPPED +\(tooling absent: requires node_modules\/\.bin\/cspell, which is absent\)/, skipped.out)
            assert.match(skipped.out, /Verdict: +NOTHING RAN/, skipped.out)
            assert.equal(skipped.code, 0, skipped.out)

            mkdirSync(join(work, 'node_modules', '.bin'), { recursive: true })
            writeFileSync(join(work, 'node_modules', '.bin', 'cspell'), '')
            const ran = runCi(work)
            assert.match(ran.out, /tool +FAIL +.*\(exit 9\)/, ran.out)
            assert.equal(ran.code, 1, ran.out)
        })

        it('skips a job whose required command is not on PATH, and runs it when it is (control)', () => {
            const absent = scratchRepo({ jobs: [failsIfRun({ requires: ['bin:local-ci-no-such-tool'] })] }, { 'a.txt': 'x\n' })
            const skipped = runCi(absent)
            assert.match(skipped.out, /tool +SKIPPED +\(tooling absent: requires bin:local-ci-no-such-tool/, skipped.out)
            assert.equal(skipped.code, 0, skipped.out)

            const present = scratchRepo({ jobs: [failsIfRun({ requires: ['bin:git'] })] }, { 'a.txt': 'x\n' })
            const ran = runCi(present)
            assert.match(ran.out, /tool +FAIL/, ran.out)
            assert.equal(ran.code, 1, ran.out)
        })

        it('--dry-run shows the tooling skip in the plan', () => {
            const work = scratchRepo({ jobs: [failsIfRun({ requires: ['cspell.json'] })] }, { 'a.txt': 'x\n' })
            const { out, code } = runCi(work, ['--dry-run'])
            assert.match(out, /SKIP tool +requires cspell\.json, which is absent/, out)
            assert.equal(code, 0, out)
        })
    })
})

describe('the application job examples', () => {
    const examples = JSON.parse(readFileSync(join(HERE, 'templates', 'local-ci.app-jobs.json'), 'utf8'))
    // The jobs UAMS-Web/uams-claude-skills#26 lists for each consumer.
    const listed = {
        'uams-statamic': ['mutate', 'build', 'js', 'spell', 'browser', 'composer-validate', 'no-emoji-guard', 'encoding-guard', 'test-isolation-guard'],
        'wordpress-importer': ['validate', 'spell', 'node-test', 'python-test', 'exports-gitlink'],
        'uamswp-migration-api': ['load-guard', 'validate', 'glyph', 'spell', 'node-test'],
    }

    it('declares exactly the listed jobs for each consumer, and nothing for another', () => {
        assert.deepEqual(Object.keys(examples).sort(), Object.keys(listed).sort())
        for (const [repo, names] of Object.entries(listed)) {
            assert.deepEqual(parseConfig(examples[repo]).jobs.map((j) => j.name), names, repo)
        }
    })

    it('every job declares what it needs, so a consumer without the tooling runs none of them', () => {
        const nothing = { exists: () => false, hasBin: () => false }
        for (const repo of Object.keys(listed)) {
            const config = parseConfig(examples[repo])
            const { selected, skipped } = selectJobs(config, ['composer.json'], { all: true, unmet: (job) => unmetRequirements(job.requires, nothing) })
            assert.deepEqual(selected, [], `${repo} would run a job with no tooling`)
            assert.equal(skipped.length, config.jobs.length, repo)
            assert.ok(skipped.every((entry) => entry.absent), repo)
        }
    })

    it('declares the mutation smoke assertions on the statamic mutate job and on no other example job', () => {
        const withMutation = Object.entries(examples).flatMap(([repo, config]) => parseConfig(config).jobs.filter((j) => j.mutation).map((j) => `${repo}/${j.name}`))
        assert.deepEqual(withMutation, ['uams-statamic/mutate'])
        assert.deepEqual(parseConfig(examples['uams-statamic']).jobs[0].mutation, { minScore: 100 })
    })

    it('selects a job by the files it reads, and reads its requirements back (control)', () => {
        const config = parseConfig(examples['wordpress-importer'])
        const here = { exists: () => true, hasBin: () => true }
        const { selected } = selectJobs(config, ['project-words.txt'], { unmet: (job) => unmetRequirements(job.requires, here) })
        assert.deepEqual(selected.map((e) => e.name), ['spell', 'exports-gitlink'])
    })
})

describe('the template', () => {
    it('selects pint when only composer.lock changed, as it selects the other vendor tools', () => {
        const template = parseConfig(readFileSync(join(HERE, 'templates', 'local-ci.json'), 'utf8'))
        const { selected } = selectJobs(template, ['composer.lock'], {})
        assert.deepEqual(selected.map((e) => e.name), ['guard', 'lint', 'synced-files', 'pint', 'rector', 'phpstan', 'pest'])
    })
})

describe('mutation: the output of a mutation run is part of its verdict', () => {
    const PASSING = '  Mutations: 12 for 1 Files created\n  Score: 100.00%\n'
    const summary = (mutations, files) => `  ${mutations} Mutations for ${files} Files created\n`
    const ESC = String.fromCharCode(27)

    it('parses mutation as an object, defaults the threshold to 100, and defaults to none', () => {
        const [plain, bare, custom] = parseConfig({ jobs: [
            { name: 'a', run: 'x' },
            { name: 'b', run: 'x', mutation: {} },
            { name: 'c', run: 'x', mutation: { minScore: 85.5 } },
        ] }).jobs
        assert.equal(plain.mutation, null)
        assert.deepEqual(bare.mutation, { minScore: 100 })
        assert.deepEqual(custom.mutation, { minScore: 85.5 })
    })

    it('names the field that is wrong', () => {
        const bad = (mutation, extra = {}) => parseConfig({ jobs: [{ name: 'a', run: 'x', mutation, ...extra }] })
        assert.throws(() => bad(true), /jobs\[0\]\.mutation must be an object/)
        assert.throws(() => bad([]), /jobs\[0\]\.mutation must be an object/)
        assert.throws(() => bad({ minScore: '100' }), /jobs\[0\]\.mutation\.minScore must be a number from 0 to 100/)
        assert.throws(() => bad({ minScore: 101 }), /minScore must be a number from 0 to 100/)
        assert.throws(() => bad({ minScore: -1 }), /minScore must be a number from 0 to 100/)
        assert.throws(() => bad({}, { each: ['**/*.php'] }), /mutation cannot be combined with "each"/)
    })

    it('passes a run that created mutations and scored at or above the threshold (control)', () => {
        assert.deepEqual(mutationProblems(`${summary(12, 1)}  Score: 100.00%\n`, 100), [])
        assert.deepEqual(mutationProblems(`${summary(3, 2)}Score: 87.5%\n`, 85), [])
        assert.deepEqual(mutationProblems(`${summary(1, 1)}Score: 100%\n`, 100), [])
    })

    it('fails a run that mutated nothing, in either count, whatever the score line says', () => {
        const none = mutationProblems(`${summary(0, 0)}  Score: 100.00%\n`, 100)
        assert.equal(none.length, 1)
        assert.match(none[0], /reported 0 mutations for 0 files: nothing was mutated/)
        assert.match(mutationProblems(`${summary(0, 3)}  Score: 100%\n`, 100)[0], /0 mutations for 3 files/)
        assert.match(mutationProblems(`${summary(5, 0)}  Score: 100%\n`, 100)[0], /5 mutations for 0 files/)
    })

    it('fails a score below the threshold and names both numbers', () => {
        const problems = mutationProblems(`${summary(10, 1)}  Score: 90.00%\n`, 100)
        assert.equal(problems.length, 1)
        assert.match(problems[0], /mutation score 90\.00% is below the configured minimum of 100%/)
        assert.deepEqual(mutationProblems(`${summary(10, 1)}  Score: 90.00%\n`, 90), [])
    })

    it('fails output that lacks the summary line or the score line, rather than reading silence as a pass', () => {
        assert.match(mutationProblems('', 100)[0], /did not report "N Mutations for M Files created"/)
        assert.match(mutationProblems('All tests passed\n', 100)[0], /did not report/)
        assert.match(mutationProblems(summary(4, 1), 100)[0], /did not report a "Score: N%" line/)
    })

    it('reads through ANSI color codes around the numbers', () => {
        const colored = `  ${ESC}[32m12${ESC}[39m Mutations for 1 Files created\n  Score: ${ESC}[32m100.00${ESC}[39m%\n`
        assert.deepEqual(mutationProblems(colored, 100), [])
        assert.equal(mutationProblems(`  ${ESC}[31m0${ESC}[39m Mutations for 0 Files created\n  Score: 100%\n`, 100).length, 1)
    })

    describe('end to end', () => {
        const job = { name: 'mutate', run: 'node fake-pest.mjs', always: true, mutation: { minScore: 100 } }
        const fake = 'import { readFileSync } from "node:fs"\nprocess.stdout.write(readFileSync("out.txt", "utf8"))\nprocess.exit(Number(readFileSync("code.txt", "utf8")))\n'
        const run = (output, code = 0, mutation = job.mutation) => runCi(
            scratchRepo({ jobs: [{ ...job, mutation }] }, { 'fake-pest.mjs': fake, 'out.txt': output, 'code.txt': String(code) }),
        )

        it('a passing mutation run still passes, and its output is still shown', () => {
            const { out, code } = run(PASSING.replace('Mutations: 12 for 1', '12 Mutations for 1'))
            assert.equal(code, 0, out)
            assert.match(out, /12 Mutations for 1 Files created/)
            assert.match(out, /Verdict:\s+PASS: 1 of 1 job run passed/)
        })

        it('fails when the run reports zero mutations for zero files, though the command exits 0', () => {
            const { out, code } = run('  0 Mutations for 0 Files created\n  Score: 100.00%\n')
            assert.equal(code, 1, out)
            assert.match(out, /nothing was mutated/)
            assert.match(out, /FAIL: 1 of 1 job run failed: mutate/)
        })

        it('fails when the score is below the configured threshold, though the command exits 0', () => {
            const { out, code } = run('  8 Mutations for 1 Files created\n  Score: 75.00%\n')
            assert.equal(code, 1, out)
            assert.match(out, /mutation score 75\.00% is below the configured minimum of 100%/)
        })

        it('passes the same 75% run when the threshold is lowered (control for the threshold)', () => {
            const { out, code } = run('  8 Mutations for 1 Files created\n  Score: 75.00%\n', 0, { minScore: 70 })
            assert.equal(code, 0, out)
        })

        it('fails a command that exits non-zero even when its output looks perfect', () => {
            const { out, code } = run('  8 Mutations for 1 Files created\n  Score: 100.00%\n', 3)
            assert.equal(code, 1, out)
        })

        it('a job without mutation ignores the output entirely (control)', () => {
            const { out, code } = runCi(scratchRepo({ jobs: [{ ...job, mutation: undefined }] }, { 'fake-pest.mjs': fake, 'out.txt': '  0 Mutations for 0 Files created\n', 'code.txt': '0' }))
            assert.equal(code, 0, out)
        })
    })
})

describe('--branch: pure helpers', () => {
    it('slotPathFor puts the slot beside the primary', () => {
        assert.equal(slotPathFor(join('x', 'repo')), join('x', 'repo-ci'))
    })

    it('stripSlotOptions drops --branch and --worktree-path with their values only', () => {
        assert.deepEqual(
            stripSlotOptions(['--all', '--branch', 'feature', '--worktree-path', 'd', '--dry-run']),
            ['--all', '--dry-run'],
        )
    })

    it('sameRunner ignores line-ending differences and nothing else', () => {
        assert.equal(sameRunner('a\nb\n', 'a\r\nb\r\n'), true)
        assert.equal(sameRunner('a\nb\n', 'a\nc\n'), false)
    })

    it('parseArgs accepts --branch and refuses --worktree-path alone', () => {
        assert.equal(parseArgs(['--branch', 'f']).branch, 'f')
        assert.throws(() => parseArgs(['--worktree-path', 'd']), /--worktree-path/)
    })

    it('parseArgs refuses --comment for a branch named only with digits, and only then', () => {
        assert.throws(() => parseArgs(['--branch', '123', '--comment']), /bare number/)
        assert.equal(parseArgs(['--branch', '123']).branch, '123')
        assert.equal(parseArgs(['--branch', 'v123', '--comment']).comment, true)
    })
})

describe('end to end: --branch validates in a runner-owned slot', () => {
    const cwdJob = { name: 'where', run: 'node -e "console.log(\'CWD=\' + process.cwd())"' }

    function pushed(files = {}) {
        const work = scratchRepo({ jobs: [cwdJob] }, files)
        git(work, 'push', '-q', 'origin', 'feature')
        return work
    }

    function snapshot(work) {
        return {
            head: git(work, 'rev-parse', 'HEAD').trim(),
            ref: git(work, 'symbolic-ref', 'HEAD').trim(),
            status: git(work, 'status', '--porcelain', '--untracked-files=all'),
            index: git(work, 'ls-files', '-s'),
        }
    }

    it('runs the chain in <primary>-ci at the remote head and leaves the invoking checkout untouched', () => {
        const work = pushed()
        writeFileSync(join(work, 'dirty.txt'), 'uncommitted\n')
        writeFileSync(join(work, 'local-ci.json'), JSON.stringify({ jobs: [cwdJob], note: 1 }))
        const before = snapshot(work)
        const { code, out } = runCi(work, ['--all', '--branch', 'feature'])
        assert.equal(code, 0, out)
        assert.match(out, /CWD=.*work-ci/)
        const slot = `${work}-ci`
        assert.equal(git(slot, 'rev-parse', 'HEAD').trim(), git(work, 'rev-parse', 'origin/feature').trim())
        assert.deepEqual(snapshot(work), before)
    })

    it('validates the remote head, not an unpushed local commit', () => {
        const work = pushed()
        const remote = git(work, 'rev-parse', 'origin/feature').trim()
        writeFileSync(join(work, 'unpushed.txt'), 'x\n')
        git(work, 'add', '-A')
        git(work, 'commit', '-q', '-m', 'local only')
        const { code, out } = runCi(work, ['--all', '--branch', 'feature'])
        assert.equal(code, 0, out)
        assert.equal(git(`${work}-ci`, 'rev-parse', 'HEAD').trim(), remote)
    })

    it('refuses a branch that is not on the remote before creating anything', () => {
        const work = pushed()
        const { code, out } = runCi(work, ['--all', '--branch', 'nope'])
        assert.equal(code, 1, out)
        assert.match(out, /origin has no branch nope/)
        assert.equal(existsSync(`${work}-ci`), false)
        assert.equal(git(work, 'worktree', 'list').trim().split('\n').length, 1)
    })

    it('runs the branch copy of the runner once when it differs, and does not hand off twice', () => {
        const work = pushed()
        const rel = join('.claude', 'skills', 'local-ci', 'local-ci.mjs')
        const text = readFileSync(join(work, rel), 'utf8')
        writeFileSync(join(work, rel), `${text}\nconsole.error('BRANCH-COPY-MARKER')\n`)
        git(work, 'add', '-A')
        git(work, 'commit', '-q', '-m', 'branch runner')
        git(work, 'push', '-q', 'origin', 'feature')
        // The executing copy goes back to the original, so it differs from the pushed one.
        writeFileSync(join(work, rel), text)
        const { code, out } = runCi(work, ['--all', '--branch', 'feature'])
        assert.equal(code, 0, out)
        assert.equal(out.split('BRANCH-COPY-MARKER').length - 1, 1, out)
        assert.equal(out.split('Handoff:').length - 1, 1, out)
    })

    it('does not hand off when the branch copy is identical', () => {
        const work = pushed()
        const { code, out } = runCi(work, ['--all', '--branch', 'feature'])
        assert.equal(code, 0, out)
        assert.doesNotMatch(out, /Handoff:/)
    })

    it('refuses --branch inside a handoff', () => {
        const work = pushed()
        const { code, out } = runCi(work, ['--all', '--branch', 'feature'], { [HANDOFF_ENV]: String(process.pid) })
        assert.notEqual(code, 0, out)
        assert.match(out, /handed-off run/, out)
        assert.equal(existsSync(`${work}-ci`), false, 'the refusal came before any slot was created')
    })

    it('does not pass the handoff markers on to the jobs', () => {
        const seen = { name: 'seen', run: 'node -e "console.log([\'LC=\' + process.env.LOCAL_CI, \'H=\' + (process.env.LOCAL_CI_HANDOFF ?? \'\'), \'B=\' + (process.env.LOCAL_CI_BRANCH ?? \'\')].join(\';\'))"' }
        const work = scratchRepo({ jobs: [seen] }, {})
        const { code, out } = runCi(work, ['--all'], { [HANDOFF_ENV]: String(process.pid), LOCAL_CI_BRANCH: 'stale' })
        assert.equal(code, 0, out)
        assert.match(out, /LC=1;H=;B=(\s|$)/, out)
    })

    it('treats the marker as a handoff only when it names the parent process (#89)', () => {
        assert.equal(isHandedOff({ [HANDOFF_ENV]: '4242' }, 4242), true)
        for (const marker of [undefined, '', '1', '4243', '0', '-4242', '4242x', ' 4242', '4242.0']) {
            assert.equal(isHandedOff({ [HANDOFF_ENV]: marker }, 4242), false, JSON.stringify(marker))
        }
        assert.equal(isHandedOff({}, 4242), false)
    })

    it('a marker left in the environment by some other process refuses nothing and skips no lock (#89)', () => {
        const work = pushed()
        const stale = { [HANDOFF_ENV]: String(process.pid + 100000) }
        const { code, out } = runCi(work, ['--all', '--branch', 'feature'], stale)
        assert.equal(code, 0, out)
        assert.doesNotMatch(out, /handed-off run/, out)

        // And in place: a stale marker does not let a run past a live holder.
        const plain = scratchRepo({ jobs: [{ name: 'ok', run: 'node -e "0"', always: true }] }, { 'a.txt': 'x\n' })
        mkdirSync(lockPathFor(plain), { recursive: true })
        writeFileSync(join(lockPathFor(plain), 'holder.json'), JSON.stringify({ pid: process.pid, branch: null, worktree: plain, startedAt: 't' }))
        const refused = runCi(plain, ['--all'], stale)
        assert.equal(refused.code, LOCK_REFUSED_EXIT, refused.out)
    })

    it('refuses a run started inside the slot itself', () => {
        const work = pushed()
        assert.equal(runCi(work, ['--all', '--branch', 'feature']).code, 0)
        const { code, out } = runCi(`${work}-ci`, ['--all', '--branch', 'feature'])
        assert.notEqual(code, 0, out)
        assert.match(out, /checkout this run was started from/, out)
    })

    it('moves a reused slot to the new head and refuses one with a dirty tracked file', () => {
        const work = pushed()
        assert.equal(runCi(work, ['--all', '--branch', 'feature']).code, 0)
        writeFileSync(join(work, 'more.txt'), 'm\n')
        git(work, 'add', '-A')
        git(work, 'commit', '-q', '-m', 'more')
        git(work, 'push', '-q', 'origin', 'feature')
        const second = runCi(work, ['--all', '--branch', 'feature'])
        assert.equal(second.code, 0, second.out)
        assert.equal(git(`${work}-ci`, 'rev-parse', 'HEAD').trim(), git(work, 'rev-parse', 'origin/feature').trim())
        writeFileSync(join(`${work}-ci`, 'local-ci.json'), '{}')
        const third = runCi(work, ['--all', '--branch', 'feature'])
        assert.notEqual(third.code, 0, third.out)
    })

    it('refuses a standing worktree at the default path and leaves it where it is (#60 review)', () => {
        const work = pushed()
        const standing = `${work}-ci`
        git(work, 'worktree', 'add', '-q', '--detach', standing, 'HEAD')
        writeFileSync(join(work, 'ahead.txt'), 'a\n')
        git(work, 'add', '-A')
        git(work, 'commit', '-q', '-m', 'feature moves on')
        git(work, 'push', '-q', 'origin', 'feature')
        const before = git(standing, 'rev-parse', 'HEAD').trim()
        assert.notEqual(before, git(work, 'rev-parse', 'origin/feature').trim(), 'the fixture must start the standing tree somewhere else')
        const { code, out } = runCi(work, ['--all', '--branch', 'feature'])
        assert.notEqual(code, 0, out)
        assert.match(out, /did not create.*--worktree-path/s, out)
        assert.equal(git(standing, 'rev-parse', 'HEAD').trim(), before, 'the standing checkout did not move')
        const dry = runCi(work, ['--all', '--dry-run', '--branch', 'feature'])
        assert.notEqual(dry.code, 0, dry.out)
        assert.equal(git(standing, 'rev-parse', 'HEAD').trim(), before, '--dry-run did not move it either')
    })

    it('still creates and reuses a slot it made itself, and takes --worktree-path past a standing one', () => {
        const work = pushed()
        git(work, 'worktree', 'add', '-q', '--detach', `${work}-ci`, 'main')
        const elsewhere = `${work}-runner-slot`
        const first = runCi(work, ['--all', '--branch', 'feature', '--worktree-path', elsewhere])
        assert.equal(first.code, 0, first.out)
        assert.match(first.out, /(created)/, first.out)
        const second = runCi(work, ['--all', '--branch', 'feature', '--worktree-path', elsewhere])
        assert.equal(second.code, 0, second.out)
        assert.match(second.out, /(reused)/, second.out)
    })

    it('reuses a slot it made when the path is spelled through a link (#111)', () => {
        // git records a worktree by its resolved path, so a slot passed as a path through
        // a link was refused as "not a worktree of this repository" on its second run.
        const work = pushed()
        const real = `${work}-links`
        mkdirSync(real)
        const link = `${work}-via-link`
        symlinkSync(real, link, process.platform === 'win32' ? 'junction' : 'dir')
        scratch.push(real, link)
        const slot = join(link, 'slot')
        const first = runCi(work, ['--all', '--branch', 'feature', '--worktree-path', slot])
        assert.equal(first.code, 0, first.out)
        assert.match(first.out, /(created)/, first.out)
        const second = runCi(work, ['--all', '--branch', 'feature', '--worktree-path', slot])
        assert.equal(second.code, 0, second.out)
        assert.match(second.out, /(reused)/, second.out)
    })

    it('without --branch creates no slot (control)', () => {
        const work = pushed()
        const { code, out } = runCi(work, ['--all'])
        assert.equal(code, 0, out)
        assert.equal(existsSync(`${work}-ci`), false)
        assert.match(out, /CWD=.*work\b/)
    })
})

describe('single-flight lock: pure helpers', () => {
    const alive = () => true
    const dead = () => false

    it('holderDisposition reads a live pid as held and a dead pid as stale', () => {
        const raw = JSON.stringify({ pid: 4242, branch: 'feature', startedAt: 't' })
        assert.deepEqual(holderDisposition(raw, alive), { state: 'held', holder: { pid: 4242, branch: 'feature', startedAt: 't' } })
        assert.equal(holderDisposition(raw, dead).state, 'stale')
        assert.equal(holderDisposition(raw, dead).holder.pid, 4242)
    })

    it('holderDisposition fails closed on a record it cannot use, whatever the liveness check says', () => {
        for (const raw of [null, undefined, '', '   ', 'not json', 'null', '[]', '{}', '{"pid":0}', '{"pid":-3}', '{"pid":"12"}', '{"pid":1.5}']) {
            assert.deepEqual(holderDisposition(raw, dead), { state: 'held', holder: null }, JSON.stringify(raw))
        }
    })

    it('lockPathFor is a sibling of the worktree, never inside it', () => {
        const worktree = join(tmpdir(), 'some-worktree')
        const lock = lockPathFor(worktree)
        assert.equal(dirname(lock), dirname(lockPathFor(join(tmpdir(), 'other'))))
        assert.match(lock, /some-worktree\.lock$/i)
        assert.equal(lock.toLowerCase().startsWith(`${worktree.toLowerCase()}${sep}`), false)
    })

    it('lockPathFor gives two spellings of one worktree one lock (#89)', () => {
        const root = mkdtempSync(join(tmpdir(), 'local-ci-spell-'))
        scratch.push(root)
        const real = join(root, 'real')
        mkdirSync(join(real, 'tree'), { recursive: true })
        const link = join(root, 'link')
        symlinkSync(real, link, process.platform === 'win32' ? 'junction' : 'dir')
        // A parent reached through a link, for a worktree that does not exist yet.
        assert.equal(lockPathFor(join(link, 'future')), lockPathFor(join(real, 'future')), 'parent link')
        // A leaf that is itself a link to the worktree.
        const leaf = join(root, 'leaf')
        symlinkSync(join(real, 'tree'), leaf, process.platform === 'win32' ? 'junction' : 'dir')
        assert.equal(lockPathFor(leaf), lockPathFor(join(real, 'tree')), 'leaf link')
    })

    it('samePath treats a path reached through a link and its resolved form as one (#111)', () => {
        const root = mkdtempSync(join(tmpdir(), 'local-ci-same-'))
        scratch.push(root)
        const real = join(root, 'real')
        mkdirSync(join(real, 'tree'), { recursive: true })
        const link = join(root, 'link')
        symlinkSync(real, link, process.platform === 'win32' ? 'junction' : 'dir')
        // The case macOS meets with /tmp and /private/tmp: one side resolved, one not.
        assert.equal(samePath(join(link, 'tree'), join(real, 'tree')), true, 'existing leaf through a parent link')
        assert.equal(samePath(join(link, 'future'), join(real, 'future')), true, 'a path that does not exist yet')
        assert.equal(samePath(join(link, 'tree'), join(real, 'future')), false, 'different places stay different')
        assert.equal(canonicalPath(join(link, 'future')), join(canonicalPath(real), 'future'))
        // Nothing on the path exists: left as written, never an error.
        assert.equal(samePath(join(root, 'no', 'such', 'a'), join(root, 'no', 'such', 'b')), false)
    })

    it('lockPathFor folds case where the filesystem does, and only there (#89)', () => {
        const root = mkdtempSync(join(tmpdir(), 'local-ci-case-'))
        scratch.push(root)
        const folded = lockPathFor(join(root, 'Tree')) === lockPathFor(join(root, 'tree'))
        assert.equal(folded, process.platform === 'win32' || process.platform === 'darwin')
    })

    it('lockRefusal names the holder, the worktree and what it is validating', () => {
        const message = lockRefusal({ worktreePath: '/w/repo-ci', lockPath: '/w/repo-ci.lock', holder: { pid: 77, branch: 'feature', startedAt: '2026-10-05T10:00:00.000Z' } })
        assert.match(message, /\/w\/repo-ci\b/)
        assert.match(message, /pid 77/)
        assert.match(message, /origin\/feature in the slot/)
        assert.match(message, /2026-10-05T10:00:00\.000Z/)
        assert.match(message, /remove \/w\/repo-ci\.lock/)
        const inPlace = lockRefusal({ worktreePath: '/w/repo', lockPath: '/w/repo.lock', holder: { pid: 78, branch: null, startedAt: 't' } })
        assert.match(inPlace, /validating the working tree/)
        assert.match(lockRefusal({ worktreePath: '/w/repo', lockPath: '/w/repo.lock', holder: null }), /cannot be read/)
    })

    it('interruptExitCode maps the stop signals to 128 plus the number, and nothing else', () => {
        assert.equal(interruptExitCode('SIGINT'), 130)
        assert.equal(interruptExitCode('SIGTERM'), 143)
        assert.equal(interruptExitCode('SIGHUP'), 129)
        assert.equal(interruptExitCode('SIGKILL'), null)
        assert.equal(interruptExitCode(null), null)
    })
})

describe('single-flight lock: acquire, refuse, reclaim, release', () => {
    function freshWorktree() {
        const root = mkdtempSync(join(tmpdir(), 'local-ci-lock-'))
        scratch.push(root)
        return join(root, 'tree')
    }

    it('acquires, writes the holder, refuses a second taker naming it, and releases', () => {
        const tree = freshWorktree()
        const first = acquireWorktreeLock(tree, { branch: 'feature', pid: process.pid })
        assert.equal(first.ok, true)
        const record = JSON.parse(readFileSync(join(first.lockPath, 'holder.json'), 'utf8'))
        assert.equal(record.pid, process.pid)
        assert.equal(record.branch, 'feature')
        assert.equal(record.worktree, resolve(tree))

        const second = acquireWorktreeLock(tree, { branch: null, pid: process.pid + 1, isAlive: () => true })
        assert.equal(second.ok, false)
        assert.match(second.message, new RegExp(`pid ${process.pid}\\b`))
        assert.match(second.message, /origin\/feature/)
        assert.equal(JSON.parse(readFileSync(join(first.lockPath, 'holder.json'), 'utf8')).pid, process.pid, 'the refused taker did not touch the lock')

        first.release()
        assert.equal(existsSync(first.lockPath), false)
        assert.equal(acquireWorktreeLock(tree, { pid: process.pid + 1 }).ok, true, 'free again after release')
    })

    it('reclaims a lock whose holder is gone, announces it, and takes it', () => {
        const tree = freshWorktree()
        const dead = acquireWorktreeLock(tree, { branch: 'old', pid: 999001 })
        assert.equal(dead.ok, true)
        const announced = []
        const taken = acquireWorktreeLock(tree, { branch: 'new', pid: 999002, isAlive: () => false, onReclaim: (line) => announced.push(line) })
        assert.equal(taken.ok, true)
        assert.equal(announced.length, 1)
        assert.match(announced[0], /pid 999001/)
        const record = JSON.parse(readFileSync(join(taken.lockPath, 'holder.json'), 'utf8'))
        assert.deepEqual([record.pid, record.branch], [999002, 'new'])
    })

    it('two runs reclaiming one dead lock cannot both take it', () => {
        const tree = freshWorktree()
        assert.equal(acquireWorktreeLock(tree, { branch: 'old', pid: 999001 }).ok, true)
        let rival = null
        // Run A reclaims after B's last look at the dead record and before B's rename.
        const b = acquireWorktreeLock(tree, {
            branch: 'b',
            pid: 999003,
            isAlive: () => false,
            beforeRename: () => {
                rival = acquireWorktreeLock(tree, { branch: 'a', pid: 999002, isAlive: () => false })
            },
        })
        assert.equal(rival.ok, true, 'A took the dead lock')
        assert.equal(b.ok, false, 'B was refused rather than deleting A')
        assert.equal(JSON.parse(readFileSync(join(rival.lockPath, 'holder.json'), 'utf8')).pid, 999002, "A's record is intact")
    })

    it('does not rename a lock whose record changed after it was read (#89)', () => {
        const tree = freshWorktree()
        const old = acquireWorktreeLock(tree, { branch: 'old', pid: 999001 })
        const holder = join(old.lockPath, 'holder.json')
        let renamed = false
        const result = acquireWorktreeLock(tree, {
            branch: 'b',
            pid: 999003,
            // The record is replaced between the read and the liveness answer.
            isAlive: () => (writeFileSync(holder, JSON.stringify({ pid: 999009, branch: 'c', worktree: tree, startedAt: 't' })), false),
            beforeRename: () => {
                renamed = true
            },
        })
        assert.equal(result.ok, false)
        assert.equal(renamed, false, 'the lock was about to be renamed from under its new holder')
        assert.equal(JSON.parse(readFileSync(holder, 'utf8')).pid, 999009, "the new holder's record is intact")
    })

    it('reclaims once: a lock recreated during the reclaim is refused, not reclaimed again (#89)', () => {
        const tree = freshWorktree()
        const lockPath = lockPathFor(tree)
        assert.equal(acquireWorktreeLock(tree, { branch: 'old', pid: 999001 }).ok, true)
        let reclaims = 0
        const result = acquireWorktreeLock(tree, {
            branch: 'new',
            pid: 999002,
            isAlive: () => false,
            onReclaim: () => {
                reclaims++
                // Another run takes the path in the window after the reclaim.
                mkdirSync(lockPath)
                writeFileSync(join(lockPath, 'holder.json'), JSON.stringify({ pid: 999003, branch: 'rival', worktree: tree, startedAt: 't' }))
            },
        })
        assert.equal(reclaims, 1)
        assert.equal(result.ok, false)
        assert.match(result.message, /pid 999003\b/)
        assert.equal(JSON.parse(readFileSync(join(lockPath, 'holder.json'), 'utf8')).pid, 999003, "the rival's lock was left alone")
    })

    it('takes a lock whose parent directories do not exist yet', () => {
        const tree = join(freshWorktree(), 'nope', 'deeper', 'slot')
        const taken = acquireWorktreeLock(tree, { pid: 5100 })
        assert.equal(taken.ok, true)
        taken.release()
        assert.equal(existsSync(taken.lockPath), false)
    })

    it('never reclaims a lock whose record is missing or unreadable', () => {
        for (const content of [null, '', 'garbage']) {
            const tree = freshWorktree()
            const lockPath = lockPathFor(tree)
            mkdirSync(lockPath, { recursive: true })
            if (content !== null) {
                writeFileSync(join(lockPath, 'holder.json'), content)
            }
            const result = acquireWorktreeLock(tree, { isAlive: () => false, onReclaim: () => assert.fail('reclaimed an unreadable lock') })
            assert.equal(result.ok, false, JSON.stringify(content))
            assert.match(result.message, /cannot be read/)
            assert.equal(existsSync(lockPath), true)
        }
    })

    it('release leaves a lock that now names another process', () => {
        const tree = freshWorktree()
        const mine = acquireWorktreeLock(tree, { pid: 5001 })
        writeFileSync(join(mine.lockPath, 'holder.json'), JSON.stringify({ pid: 5002, branch: null, startedAt: 't' }))
        mine.release()
        assert.equal(existsSync(mine.lockPath), true)
        mine.release()
        assert.equal(existsSync(mine.lockPath), true, 'releasing twice is still a no-op')
    })

    it('release is a no-op once the lock is already gone', () => {
        const tree = freshWorktree()
        const mine = acquireWorktreeLock(tree, { pid: 5003 })
        rmSync(mine.lockPath, { recursive: true, force: true })
        assert.doesNotThrow(() => mine.release())
    })
})

describe('end to end: the single-flight lock', () => {
    const ok = { name: 'ok', run: 'node -e "process.exit(0)"', always: true }
    const bad = { name: 'bad', run: 'node -e "process.exit(1)"', always: true }
    const ran = (out) => /==> JOB/.test(out)

    function holderFile(work) {
        return join(lockPathFor(work), 'holder.json')
    }

    function holdAs(work, pid, branch = null) {
        mkdirSync(lockPathFor(work), { recursive: true })
        writeFileSync(holderFile(work), JSON.stringify({ pid, branch, worktree: work, startedAt: '2026-10-05T09:00:00.000Z' }))
    }

    // The pid of a process that has already exited.
    function deadPid() {
        return spawnSync(process.execPath, ['-e', '0']).pid
    }

    it('a second run against a worktree a live run holds exits 4 before any job, naming the holder', () => {
        const work = scratchRepo({ jobs: [ok] }, { 'a.txt': 'x\n' })
        holdAs(work, process.pid)
        const { code, out } = runCi(work, ['--all'])
        assert.equal(code, LOCK_REFUSED_EXIT, out)
        assert.match(out, new RegExp(`Another local-ci run holds .*pid ${process.pid}\\b`), out)
        assert.equal(ran(out), false, out)
        assert.doesNotMatch(out, /Verdict/, out)
        assert.equal(JSON.parse(readFileSync(holderFile(work), 'utf8')).pid, process.pid, 'the holder was left alone')
    })

    it('a lock left by a dead run is reclaimed, announced, and the run proceeds', () => {
        const work = scratchRepo({ jobs: [ok] }, { 'a.txt': 'x\n' })
        const gone = deadPid()
        holdAs(work, gone)
        const { code, out } = runCi(work, ['--all'])
        assert.equal(code, 0, out)
        assert.match(out, new RegExp(`reclaiming the lock .*pid ${gone}\\b`), out)
        assert.match(out, /ok +PASS/, out)
        assert.equal(existsSync(lockPathFor(work)), false)
    })

    it('a lock whose record cannot be read is refused, not reclaimed', () => {
        const work = scratchRepo({ jobs: [ok] }, { 'a.txt': 'x\n' })
        mkdirSync(lockPathFor(work), { recursive: true })
        const { code, out } = runCi(work, ['--all'])
        assert.equal(code, LOCK_REFUSED_EXIT, out)
        assert.match(out, /cannot be read/, out)
        assert.equal(ran(out), false, out)
        assert.equal(existsSync(lockPathFor(work)), true)
    })

    it('the lock is released after a passing run', () => {
        const work = scratchRepo({ jobs: [ok] }, { 'a.txt': 'x\n' })
        const { code, out } = runCi(work, ['--all'])
        assert.equal(code, 0, out)
        assert.equal(existsSync(lockPathFor(work)), false)
    })

    it('the lock is released after a failing run', () => {
        const work = scratchRepo({ jobs: [bad] }, { 'a.txt': 'x\n' })
        const { code, out } = runCi(work, ['--all'])
        assert.equal(code, 1, out)
        assert.match(out, /bad +FAIL/, out)
        assert.equal(existsSync(lockPathFor(work)), false)
    })

    it('the lock is released when the run dies on an error', () => {
        const work = scratchRepo({ jobs: [ok] }, { 'a.txt': 'x\n' })
        writeFileSync(join(work, 'local-ci.json'), '{ not json')
        const { code, out } = runCi(work, ['--all'])
        assert.equal(code, 1, out)
        assert.match(out, /local-ci:/, out)
        assert.equal(existsSync(lockPathFor(work)), false)
    })

    // The job is a node process that signals itself, so the outcome does not depend
    // on whether the shell that starts it leaves SIGINT ignored (a non-interactive
    // sh started from a background job does, and `kill -INT $$` then does nothing).
    for (const [signal, expected] of [['SIGINT', 130], ['SIGTERM', 143], ['SIGHUP', 129]]) {
        it(`a job that receives ${signal} stops the run, exits ${expected} and releases the lock`, { skip: !POSIX && 'Node reports no signal for a child on Windows' }, () => {
            const after = { name: 'after', run: 'node -e "require(\'fs\').writeFileSync(\'after-ran\', \'x\')"', always: true }
            const interrupted = { name: 'interrupted', run: `node -e "process.kill(process.pid, '${signal}')"`, always: true }
            const work = scratchRepo({ jobs: [interrupted, after] }, { 'a.txt': 'x\n' })
            const { code, out } = runCi(work, ['--all'])
            assert.equal(code, expected, out)
            assert.match(out, new RegExp(`interrupted \\(${signal}\\)`), out)
            assert.equal(existsSync(join(work, 'after-ran')), false, 'the next job did not start')
            assert.equal(existsSync(lockPathFor(work)), false)
        })
    }

    it('--dry-run changes nothing, so it neither takes the lock nor is refused by it', () => {
        const work = scratchRepo({ jobs: [ok] }, { 'a.txt': 'x\n' })
        holdAs(work, process.pid)
        const { code, out } = runCi(work, ['--all', '--dry-run'])
        assert.equal(code, 0, out)
        assert.match(out, /Plan/, out)
        assert.equal(JSON.parse(readFileSync(holderFile(work), 'utf8')).pid, process.pid)
    })

    it('--branch locks the slot, refuses a second run on it naming the branch, and creates nothing', () => {
        const work = scratchRepo({ jobs: [ok] }, { 'a.txt': 'x\n' })
        git(work, 'push', '-q', 'origin', 'feature')
        const slot = `${work}-ci`
        holdAs(slot, process.pid, 'feature')
        const { code, out } = runCi(work, ['--all', '--branch', 'feature'])
        assert.equal(code, LOCK_REFUSED_EXIT, out)
        assert.match(out, /origin\/feature in the slot/, out)
        assert.match(out, /work-ci/, out)
        assert.equal(existsSync(slot), false, 'the held slot was not created or moved')
        assert.equal(ran(out), false, out)
    })

    it('--branch --dry-run takes the slot lock, so a held slot refuses it (#89)', () => {
        const work = scratchRepo({ jobs: [ok] }, { 'a.txt': 'x\n' })
        git(work, 'push', '-q', 'origin', 'feature')
        const slot = `${work}-ci`
        holdAs(slot, process.pid, 'feature')
        const { code, out } = runCi(work, ['--all', '--dry-run', '--branch', 'feature'])
        assert.equal(code, LOCK_REFUSED_EXIT, out)
        assert.match(out, /Another local-ci run holds/, out)
        assert.equal(JSON.parse(readFileSync(holderFile(slot), 'utf8')).pid, process.pid, 'the holder was left alone')
    })

    it('a lock left by a killed holder is reclaimed on every platform (#89)', () => {
        const work = scratchRepo({ jobs: [ok] }, { 'a.txt': 'x\n' })
        const child = spawnSync(process.execPath, ['-e', 'process.kill(process.pid, "SIGKILL")'], { encoding: 'utf8' })
        // A killed process leaves no record of its own; stand one in for it, naming the real, now-gone pid.
        holdAs(work, child.pid)
        const { code, out } = runCi(work, ['--all'])
        assert.equal(code, 0, out)
        assert.match(out, new RegExp(`reclaiming the lock .*pid ${child.pid}\\b`), out)
    })

    it('--branch releases the slot lock when it finishes', () => {
        const work = scratchRepo({ jobs: [ok] }, { 'a.txt': 'x\n' })
        git(work, 'push', '-q', 'origin', 'feature')
        const { code, out } = runCi(work, ['--all', '--branch', 'feature'])
        assert.equal(code, 0, out)
        assert.equal(existsSync(lockPathFor(`${work}-ci`)), false)
    })

    it('a handed-off child runs under its parent lock instead of being refused by it', () => {
        const work = scratchRepo({ jobs: [ok] }, { 'a.txt': 'x\n' })
        const rel = join('.claude', 'skills', 'local-ci', 'local-ci.mjs')
        const text = readFileSync(join(work, rel), 'utf8')
        writeFileSync(join(work, rel), `${text}\n// differs from the executing copy\n`)
        git(work, 'add', '-A')
        git(work, 'commit', '-q', '-m', 'branch runner')
        git(work, 'push', '-q', 'origin', 'feature')
        writeFileSync(join(work, rel), text)
        const { code, out } = runCi(work, ['--all', '--branch', 'feature'])
        assert.equal(code, 0, out)
        assert.match(out, /Handoff:/, out)
        assert.match(out, /ok +PASS/, out)
        assert.equal(existsSync(lockPathFor(`${work}-ci`)), false)
    })
})

describe('parallel: a Pest job split into a parallel run and a serial tail', () => {
    const job = (parallel, extra = {}) => parseConfig({ jobs: [{ name: 'pest', run: 'vendor/bin/pest', parallel, ...extra }] }).jobs[0]

    it('parses the group and the memory limit, defaults the group to serial, and defaults to none', () => {
        assert.equal(parseConfig({ jobs: [{ name: 'pest', run: 'vendor/bin/pest' }] }).jobs[0].parallel, null)
        assert.deepEqual(job({}).parallel, { serialGroup: 'serial', memoryLimit: null })
        assert.deepEqual(job({ serialGroup: 'slow', memoryLimit: '4G' }).parallel, { serialGroup: 'slow', memoryLimit: '4G' })
        assert.equal(job({ memoryLimit: -1 }).parallel.memoryLimit, '-1')
    })

    it('refuses a run that is not Pest, a bad group, a bad limit, and each or mutation beside it', () => {
        const bad = (run, parallel, extra = {}) => () => parseConfig({ jobs: [{ name: 'p', run, parallel, ...extra }] })
        assert.throws(bad('vendor/bin/pint', {}), /needs "run" to start with vendor\/bin\/pest/)
        assert.throws(bad('vendor/bin/pestle', {}), /needs "run" to start with vendor\/bin\/pest/)
        assert.throws(bad('vendor/bin/pest', 'yes'), /must be an object/)
        assert.throws(bad('vendor/bin/pest', { serialGroup: 'a b' }), /serialGroup must be a Pest group name/)
        assert.throws(bad('vendor/bin/pest', { serialGroup: 3 }), /serialGroup must be a Pest group name/)
        assert.throws(bad('vendor/bin/pest', { memoryLimit: '4G; rm -rf x' }), /memoryLimit must be a PHP size/)
        assert.throws(bad('vendor/bin/pest', {}, { each: ['**/*.php'] }), /cannot be combined with "each" or "mutation"/)
        assert.throws(bad('vendor/bin/pest', {}, { mutation: {} }), /cannot be combined with "each" or "mutation"/)
    })

    it('builds the parallel command excluding the group, then the serial tail, and keeps the run\'s own arguments', () => {
        const parts = parallelParts('vendor/bin/pest --no-tia', { serialGroup: 'serial', memoryLimit: null }, 'php', 'linux')
        assert.deepEqual(parts, [
            { part: 'parallel', command: 'php vendor/bin/pest --no-tia --parallel --exclude-group=serial' },
            { part: 'serial', command: 'php vendor/bin/pest --no-tia --group=serial' },
        ])
    })

    it('puts the memory limit on the parent and forwards it to the workers, quoted for the platform', () => {
        const limit = { serialGroup: 'serial', memoryLimit: '4G' }
        const [parallel, serial] = parallelParts('vendor/bin/pest', limit, '/usr/bin/php', 'linux')
        assert.equal(parallel.command, "/usr/bin/php -d memory_limit=4G vendor/bin/pest --parallel --exclude-group=serial '--passthru-php=-d memory_limit=4G'")
        assert.equal(serial.command, '/usr/bin/php -d memory_limit=4G vendor/bin/pest --group=serial')
        const [windows] = parallelParts('vendor/bin/pest', limit, 'C:\\php\\php.exe', 'win32')
        assert.equal(windows.command, 'C:\\php\\php.exe -d memory_limit=4G vendor/bin/pest --parallel --exclude-group=serial "--passthru-php=-d memory_limit=4G"')
    })

    it('names the failed part in the summary and the comment, and leaves a one-part failure unannotated', () => {
        const tree = { sha: 'a'.repeat(40), subject: 's', dirty: null }
        const results = runJobs(
            { selected: [{ name: 'pest' }, { name: 'plain' }], skipped: [] },
            { chain: false, execute: (entry) => (entry.name === 'pest' ? { code: 4, failedParts: ['serial'] } : { code: 1 }) },
        )
        assert.deepEqual(results[0].failedParts, ['serial'])
        assert.equal(results[1].failedParts, undefined)
        assert.match(formatSummary(tree, results), /pest +FAIL +.*\(exit 4; serial part failed\)/)
        assert.match(formatSummary(tree, results), /plain +FAIL +.*\(exit 1\)/)
        assert.match(commentBody(tree, results), /\| `pest` \| FAIL \(exit 4; serial part failed\)/)
        const both = runJobs({ selected: [{ name: 'pest' }], skipped: [] }, { chain: false, execute: () => ({ code: 3, failedParts: ['parallel', 'serial'] }) })
        assert.match(formatSummary(tree, both), /exit 3; parallel and serial part failed/)
    })
})

describe('end to end: a parallel job runs both parts under a stand-in PHP', () => {
    // The stand-in PHP is a Node script behind a launcher, so the suite needs no
    // PHP. It records every invocation (ini settings, script, arguments) and
    // exits with the code the environment names for the part being run.
    function standIn(work) {
        const log = join(work, 'php-calls.jsonl')
        const body = [
            "const fs = require('fs')",
            'const argv = process.argv.slice(2)',
            'const ini = []',
            'if (argv[0] === "-r") { process.stdout.write("8.3.0"); process.exit(0) }',
            "while (argv[0] === '-d') { argv.shift(); ini.push(argv.shift()) }",
            "fs.appendFileSync(process.env.PHP_LOG, JSON.stringify({ ini, script: argv[0], args: argv.slice(1) }) + '\\n')",
            "if (process.env.PHP_DB_LOG) fs.appendFileSync(process.env.PHP_DB_LOG, (process.env.WP_TESTS_DB_NAME ?? '(unset)') + '\\n')",
            "const parallel = argv.includes('--parallel')",
            "const serial = argv.some((a) => a.startsWith('--group='))",
            'process.exit(parallel ? Number(process.env.PARALLEL_EXIT || 0) : serial ? Number(process.env.SERIAL_EXIT || 0) : 0)',
        ].join('\n')
        const script = join(work, 'stand-in-php.cjs')
        writeFileSync(script, body)
        const launcher = join(work, process.platform === 'win32' ? 'stand-in-php.cmd' : 'stand-in-php')
        writeFileSync(launcher, process.platform === 'win32' ? `@"${process.execPath}" "${script}" %*\r\n` : `#!/bin/sh\nexec "${process.execPath}" "${script}" "$@"\n`)
        chmodSync(launcher, 0o755)
        return { launcher, log }
    }

    function parallelRepo(parallel, extra = {}) {
        const work = scratchRepo(
            { jobs: [{ name: 'pest', run: 'vendor/bin/pest', always: true, ...(parallel ? { parallel } : {}), ...extra }] },
            { 'vendor/autoload.php': '<?php\n', 'vendor/bin/pest': '<?php\n' },
        )
        return { work, ...standIn(work) }
    }

    const calls = (log) => readFileSync(log, 'utf8').trim().split('\n').map((line) => JSON.parse(line))
    const runWith = (setup, args = [], env = {}) => runCi(setup.work, ['--all', ...args], { LOCAL_CI_PHP: setup.launcher, PHP_LOG: setup.log, ...env })

    it('runs the parallel part excluding the group, then the group serially, and the memory limit reaches both and the workers', () => {
        const setup = parallelRepo({ serialGroup: 'serial', memoryLimit: '4G' })
        const { code, out } = runWith(setup)
        assert.equal(code, 0, out)
        assert.match(out, /pest +PASS/, out)
        const [parallel, serial] = calls(setup.log)
        assert.deepEqual(parallel, { ini: ['memory_limit=4G'], script: 'vendor/bin/pest', args: ['--parallel', '--exclude-group=serial', '--passthru-php=-d memory_limit=4G'] })
        assert.deepEqual(serial, { ini: ['memory_limit=4G'], script: 'vendor/bin/pest', args: ['--group=serial'] })
    })

    it('runs both parts with the database the job declares', () => {
        const setup = parallelRepo({}, { database: { name: 'app_ci', env: 'WP_TESTS_DB_NAME' } })
        const dbLog = join(setup.work, 'db.log')
        const { code, out } = runWith(setup, [], { PHP_DB_LOG: dbLog, WP_TESTS_DB_NAME: 'hand_db' })
        assert.equal(code, 0, out)
        assert.deepEqual(readFileSync(dbLog, 'utf8').trim().split(/\r?\n/), ['app_ci', 'app_ci'])
    })

    it('fails when the parallel part fails, still runs the serial tail, and names the part', () => {
        const setup = parallelRepo({})
        const { code, out } = runWith(setup, [], { PARALLEL_EXIT: '3' })
        assert.equal(calls(setup.log).length, 2, 'the serial tail must still run')
        assert.match(out, /pest +FAIL +.*\(exit 3; parallel part failed\)/, out)
        assert.equal(code, 1, out)
    })

    it('fails when only the serial tail fails, and names that part', () => {
        const setup = parallelRepo({})
        const { code, out } = runWith(setup, [], { SERIAL_EXIT: '4' })
        assert.match(out, /pest +FAIL +.*\(exit 4; serial part failed\)/, out)
        assert.equal(code, 1, out)
    })

    it('passes the plan for both parts under --dry-run without running either', () => {
        const setup = parallelRepo({ memoryLimit: '2G' })
        const { code, out } = runWith(setup, ['--dry-run'])
        assert.equal(code, 0, out)
        assert.match(out, /\[parallel\] \$ .*--parallel --exclude-group=serial/, out)
        assert.match(out, /\[serial\] \$ .*--group=serial/, out)
        assert.equal(existsSync(setup.log), false)
    })

    it('a pest job declaring no parallel option runs as one command, as before', () => {
        const setup = parallelRepo(null)
        const { code, out } = runWith(setup)
        assert.equal(code, 0, out)
        assert.deepEqual(calls(setup.log), [{ ini: [], script: 'vendor/bin/pest', args: [] }])
    })
})

describe('prepare: parsing', () => {
    const withPrepare = (prepare) => parseConfig({ jobs: [], prepare }).prepare

    it('is null when absent, or when every step is left out, so nothing is prepared', () => {
        assert.equal(parseConfig({ jobs: [] }).prepare, null)
        assert.equal(withPrepare({}), null)
        assert.equal(withPrepare({ submodules: [], residue: [], patches: false }), null)
    })

    it('reads each step, normalizing paths', () => {
        assert.deepEqual(withPrepare({ submodules: ['content'], residue: ['tests/', './includes'], patches: true }), {
            submodules: ['content'],
            residue: ['tests', 'includes'],
            patches: true,
        })
    })

    it('refuses an unknown key, a path outside the repository, and a non-boolean patches', () => {
        assert.throws(() => withPrepare({ leftovers: ['tests'] }), /"prepare" has no "leftovers"/)
        assert.throws(() => withPrepare({ residue: ['../elsewhere'] }), /must be a path inside the repository/)
        assert.throws(() => withPrepare({ submodules: ['/abs'] }), /must be a path inside the repository/)
        assert.throws(() => withPrepare({ residue: ['.'] }), /must be a path inside the repository/)
        assert.throws(() => withPrepare({ patches: 'yes' }), /"prepare.patches" must be true or false/)
        assert.throws(() => withPrepare(['tests']), /"prepare" must be an object/)
    })
})

describe('prepare: pure helpers', () => {
    const lock = (patches) => JSON.stringify({ _hash: 'x', patches })

    it('residuePaths keeps only untracked entries, sorted', () => {
        assert.deepEqual(residuePaths(['?? tests/b.php', ' M tests/tracked.php', '?? tests/a.php']), ['tests/a.php', 'tests/b.php'])
        assert.deepEqual(residuePaths([]), [])
    })

    it('installedPackages reads both Composer layouts and refuses one with no list', () => {
        assert.deepEqual([...installedPackages(JSON.stringify({ packages: [{ name: 'a/b' }, { name: 'c/d' }] }))], ['a/b', 'c/d'])
        assert.deepEqual([...installedPackages(JSON.stringify([{ name: 'a/b' }]))], ['a/b'])
        assert.throws(() => installedPackages('{}'), /lists no packages/)
        assert.throws(() => installedPackages('{"packages":['), /vendor\/composer\/installed\.json is not valid JSON/)
    })

    it('patchReinstallPackages: a commit lock that is not JSON throws, naming the file, even when it equals the record', () => {
        const broken = '{"patches": {"a/pkg": [ <<<<<<< HEAD'
        assert.throws(() => patchReinstallPackages(broken, null, new Set(['a/pkg'])), /patches\.lock\.json is not valid JSON/)
        assert.throws(() => patchReinstallPackages(broken, broken, new Set(['a/pkg'])), /patches\.lock\.json is not valid JSON/)
        assert.throws(() => patchReinstallPackages('{"patches": []}', null, new Set()), /"patches" that is not an object/)
    })

    it('patchReinstallPackages: a record that is not JSON counts as no record', () => {
        assert.deepEqual(patchReinstallPackages(lock({ 'a/pkg': [{}] }), '{"patches": {"a/pkg"', new Set(['a/pkg'])), ['a/pkg'])
    })

    it('patchReinstallPackages: nothing when the lock matches the record', () => {
        const current = lock({ 'a/pkg': [{}] })
        assert.deepEqual(patchReinstallPackages(current, current, new Set(['a/pkg'])), [])
    })

    it('patchReinstallPackages: a lock differing only in line endings is the same lock', () => {
        const current = `${lock({ 'a/pkg': [{}] })}\n`
        assert.deepEqual(patchReinstallPackages(current.replace(/\n/g, '\r\n'), current, new Set(['a/pkg'])), [])
    })

    it('patchReinstallPackages: no record is a difference, and only installed packages are reinstalled', () => {
        assert.deepEqual(patchReinstallPackages(lock({ 'a/pkg': [{}], 'gone/pkg': [{}] }), null, new Set(['a/pkg'])), ['a/pkg'])
    })

    it('patchReinstallPackages: a package whose patch was removed is reinstalled too', () => {
        const before = lock({ 'a/pkg': [{}], 'b/pkg': [{}] })
        const now = lock({ 'a/pkg': [{}, {}] })
        assert.deepEqual(patchReinstallPackages(now, before, new Set(['a/pkg', 'b/pkg', 'c/pkg'])), ['a/pkg', 'b/pkg'])
    })

    it('syncPatchedVendor records the lock only after the reinstall succeeds', () => {
        const calls = []
        const effects = {
            reinstall: (packages) => (calls.push(`reinstall ${packages.join(' ')}`), 0),
            writeRecord: (text) => calls.push(`write ${text}`),
            removeRecord: () => calls.push('remove'),
        }
        syncPatchedVendor({ currentLock: lock({ 'a/pkg': [{}] }), recordedLock: null, installed: new Set(['a/pkg']), ...effects })
        assert.deepEqual(calls, ['reinstall a/pkg', `write ${lock({ 'a/pkg': [{}] })}`])
    })

    it('syncPatchedVendor writes nothing when the reinstall fails', () => {
        const calls = []
        assert.throws(
            () => syncPatchedVendor({
                currentLock: lock({ 'a/pkg': [{}] }),
                recordedLock: null,
                installed: new Set(['a/pkg']),
                reinstall: () => 1,
                writeRecord: () => calls.push('write'),
                removeRecord: () => calls.push('remove'),
            }),
            /composer reinstall a\/pkg failed \(exit 1\)/,
        )
        assert.deepEqual(calls, [])
    })

    it('syncPatchedVendor removes the record when the commit has no lock', () => {
        const calls = []
        syncPatchedVendor({ currentLock: null, recordedLock: null, installed: new Set(), reinstall: () => 0, writeRecord: () => calls.push('write'), removeRecord: () => calls.push('remove') })
        assert.deepEqual(calls, ['remove'])
    })
})

describe('end to end: prepare in a reused slot', () => {
    const marker = { name: 'mark', run: 'node -e "console.log(\'JOB-RAN\')"', always: true }
    // A local file:// submodule needs this since git 2.38; set for the runner's git too.
    const fileProtocol = { GIT_CONFIG_COUNT: '1', GIT_CONFIG_KEY_0: 'protocol.file.allow', GIT_CONFIG_VALUE_0: 'always' }

    function pushedRepo(config, files = {}) {
        const work = scratchRepo(config, files)
        git(work, 'push', '-q', 'origin', 'feature')
        return work
    }

    function commitAndPush(work, files, message) {
        for (const [name, content] of Object.entries(files)) {
            mkdirSync(dirname(join(work, name)), { recursive: true })
            writeFileSync(join(work, name), content)
        }
        git(work, 'add', '-A')
        git(work, 'commit', '-q', '-m', message)
        git(work, 'push', '-q', 'origin', 'feature')
    }

    const inSlot = (work, env = {}) => runCi(work, ['--all', '--branch', 'feature'], env)

    it('with nothing configured, a run prints no Prepare line and behaves as before', () => {
        const work = pushedRepo({ jobs: [marker] })
        const { code, out } = inSlot(work)
        assert.equal(code, 0, out)
        assert.doesNotMatch(out, /Prepare:|Residue:|Submodule:|Patches:/, out)
        assert.match(out, /JOB-RAN/, out)
    })

    it('in a checkout that is not a runner-owned slot, says it did not prepare and runs the jobs', () => {
        const work = scratchRepo({ jobs: [marker], prepare: { residue: ['tests'] } }, { 'tests/a.txt': 'a\n' })
        writeFileSync(join(work, 'tests', 'stray.txt'), 'x\n')
        const { code, out } = runCi(work, ['--all'])
        assert.equal(code, 0, out)
        assert.match(out, /Prepare: +not run \(residue under tests\): this checkout is not a runner-owned slot/, out)
        assert.match(out, /JOB-RAN/, out)
    })

    it('--dry-run in the slot names the steps without running them', () => {
        const work = pushedRepo({ jobs: [marker], prepare: { residue: ['tests'], patches: true } }, { 'tests/a.txt': 'a\n' })
        assert.equal(inSlot(work).code, 0)
        writeFileSync(join(`${work}-ci`, 'tests', 'stray.txt'), 'x\n')
        const { code, out } = runCi(work, ['--all', '--dry-run', '--branch', 'feature'])
        assert.equal(code, 0, out)
        assert.match(out, /Prepare: +would run residue under tests; patches/, out)
        assert.doesNotMatch(out, /untracked files? under tests/, out)
    })

    describe('residue', () => {
        const config = { jobs: [marker], prepare: { residue: ['tests'] } }
        const files = { 'tests/kept.txt': 'kept\n', '.gitignore': 'tests/cache/\n' }

        it('fails before any job, naming each untracked path under a listed path', () => {
            const work = pushedRepo(config, files)
            assert.equal(inSlot(work).code, 0)
            const slot = `${work}-ci`
            mkdirSync(join(slot, 'tests', 'deep'), { recursive: true })
            writeFileSync(join(slot, 'tests', 'deep', 'stale.txt'), 'x\n')
            writeFileSync(join(slot, 'tests', 'other.txt'), 'x\n')
            const { code, out } = inSlot(work)
            assert.equal(code, 1, out)
            assert.match(out, /2 untracked files under tests/, out)
            assert.match(out, / {2}tests\/deep\/stale\.txt\n {2}tests\/other\.txt/, out)
            assert.doesNotMatch(out, /JOB-RAN/, out)
            assert.equal(existsSync(join(slot, 'tests', 'other.txt')), true, 'nothing is deleted')
        })

        it('passes over ignored files and untracked files outside the listed paths (control)', () => {
            const work = pushedRepo(config, files)
            assert.equal(inSlot(work).code, 0)
            const slot = `${work}-ci`
            mkdirSync(join(slot, 'tests', 'cache'), { recursive: true })
            writeFileSync(join(slot, 'tests', 'cache', 'result.json'), '{}\n')
            writeFileSync(join(slot, 'notes.txt'), 'x\n')
            const { code, out } = inSlot(work)
            assert.equal(code, 0, out)
            assert.match(out, /Residue: +none under tests/, out)
            assert.match(out, /JOB-RAN/, out)
        })
    })

    describe('submodules', () => {
        // A bare repository with two commits on main; returns its URL and both SHAs.
        function subRepo(work, name) {
            const root = dirname(work)
            const src = join(root, `${name}-src`)
            newRepo(src)
            // The name is in the content so two of these made in the same second
            // do not share commit ids.
            writeFileSync(join(src, 'v.txt'), `${name} 1\n`)
            git(src, 'add', '-A')
            git(src, 'commit', '-q', '-m', 'one')
            const first = git(src, 'rev-parse', 'HEAD').trim()
            writeFileSync(join(src, 'v.txt'), `${name} 2\n`)
            git(src, 'commit', '-q', '-am', 'two')
            const second = git(src, 'rev-parse', 'HEAD').trim()
            git(root, 'clone', '-q', '--bare', src, `${name}.git`)
            return { url: pathToFileURL(join(root, `${name}.git`)).href, first, second }
        }

        // Adds `name` as a submodule of `work` at `sha`, committed and pushed.
        function addSubmodule(work, name, sub, sha) {
            git(work, '-c', 'protocol.file.allow=always', 'submodule', 'add', '-q', sub.url, name)
            git(join(work, name), 'checkout', '-q', sha)
            git(work, 'add', '-A')
            git(work, 'commit', '-q', '-m', `add ${name}`)
            git(work, 'push', '-q', 'origin', 'feature')
        }

        const headOf = (dir) => git(dir, 'rev-parse', 'HEAD').trim()

        it('checks a configured submodule out at the gitlink, follows a moved gitlink, and leaves an unconfigured one alone', () => {
            const work = pushedRepo({ jobs: [marker], prepare: { submodules: ['content'] } })
            const content = subRepo(work, 'content')
            const other = subRepo(work, 'other')
            addSubmodule(work, 'content', content, content.first)
            addSubmodule(work, 'other', other, other.first)
            const slot = `${work}-ci`

            const first = inSlot(work, fileProtocol)
            assert.equal(first.code, 0, first.out)
            assert.equal(headOf(join(slot, 'content')), content.first)
            assert.match(first.out, new RegExp(`Submodule: +content at ${content.first.slice(0, 9)}`), first.out)
            assert.equal(existsSync(join(slot, 'other', '.git')), false, 'the unconfigured submodule is not initialized')

            git(join(work, 'content'), 'checkout', '-q', content.second)
            git(work, 'commit', '-q', '-am', 'move content')
            git(work, 'push', '-q', 'origin', 'feature')
            const second = inSlot(work, fileProtocol)
            assert.equal(second.code, 0, second.out)
            assert.equal(headOf(join(slot, 'content')), content.second)
            assert.equal(existsSync(join(slot, 'other', '.git')), false)
            assert.match(second.out, /JOB-RAN/, second.out)
        })

        it('refuses a configured path that is not a submodule', () => {
            const work = pushedRepo({ jobs: [marker], prepare: { submodules: ['docs'] } }, { 'docs/a.md': '# a\n' })
            const { code, out } = inSlot(work)
            assert.equal(code, 1, out)
            assert.match(out, /"prepare.submodules" names docs, which is not a submodule/, out)
            assert.doesNotMatch(out, /JOB-RAN/, out)
        })

        it('names git submodule sync when the commit moved a submodule to a new url, and passes once it is synced', () => {
            const work = pushedRepo({ jobs: [marker], prepare: { submodules: ['content'] } })
            const content = subRepo(work, 'content')
            const moved = subRepo(work, 'moved')
            addSubmodule(work, 'content', content, content.first)
            assert.equal(inSlot(work, fileProtocol).code, 0)

            git(work, 'config', '-f', '.gitmodules', 'submodule.content.url', moved.url)
            // Set the gitlink without fetching, so the new commit can reach the
            // slot only from the new url. (cspell:ignore cacheinfo: git's option.)
            git(work, 'update-index', '--cacheinfo', `160000,${moved.second},content`)
            git(work, 'add', '.gitmodules')
            git(work, 'commit', '-q', '-m', 'move content')
            git(work, 'push', '-q', 'origin', 'feature')

            const stale = inSlot(work, fileProtocol)
            assert.equal(stale.code, 1, stale.out)
            assert.match(stale.out, /submodule sync -- content`, which also writes the new url/, stale.out)
            assert.doesNotMatch(stale.out, /JOB-RAN/, stale.out)

            git(`${work}-ci`, 'submodule', 'sync', '-q', '--', 'content')
            const synced = inSlot(work, fileProtocol)
            assert.equal(synced.code, 0, synced.out)
            assert.equal(headOf(join(`${work}-ci`, 'content')), moved.second)
        })

        it('refuses, naming them, files stranded in an uninitialized submodule path', () => {
            const work = pushedRepo({ jobs: [marker] })
            const content = subRepo(work, 'content')
            addSubmodule(work, 'content', content, content.first)
            assert.equal(inSlot(work, fileProtocol).code, 0)
            const slot = `${work}-ci`
            assert.equal(existsSync(join(slot, 'content', '.git')), false, 'not configured yet, so not initialized')
            writeFileSync(join(slot, 'content', 'stranded.txt'), 'x\n')
            commitAndPush(work, { 'local-ci.json': JSON.stringify({ jobs: [marker], prepare: { submodules: ['content'] } }) }, 'configure content')
            const { code, out } = inSlot(work, fileProtocol)
            assert.equal(code, 1, out)
            assert.match(out, /content is not initialized but holds 1 entry/, out)
            assert.match(out, / {2}content\/stranded\.txt/, out)
            assert.equal(existsSync(join(slot, 'content', 'stranded.txt')), true, 'nothing is deleted')
        })
    })

    describe('patches', () => {
        // A fake `composer` on PATH that logs its arguments and exits with
        // FAKE_COMPOSER_EXIT (default 0).
        function fakeComposer(work) {
            const bin = join(dirname(work), 'bin')
            mkdirSync(bin, { recursive: true })
            const log = join(dirname(work), 'composer.log')
            writeFileSync(join(bin, 'fake-composer.cjs'), `require('fs').appendFileSync(${JSON.stringify(log)}, process.argv.slice(2).join(' ') + '\\n'); process.exit(Number(process.env.FAKE_COMPOSER_EXIT || 0))\n`)
            writeFileSync(join(bin, 'composer'), '#!/bin/sh\nexec node "$(dirname "$0")/fake-composer.cjs" "$@"\n')
            chmodSync(join(bin, 'composer'), 0o755)
            writeFileSync(join(bin, 'composer.cmd'), '@node "%~dp0fake-composer.cjs" %*\r\n')
            const pathKey = Object.keys(process.env).find((key) => key.toUpperCase() === 'PATH') ?? 'PATH'
            return {
                env: { [pathKey]: `${bin}${delimiter}${process.env[pathKey]}` },
                calls: () => (existsSync(log) ? readFileSync(log, 'utf8').trim().split(/\r?\n/) : []),
            }
        }

        const lock = (patches) => `${JSON.stringify({ _hash: JSON.stringify(patches), patches }, null, 4)}\n`
        const installed = (names) => JSON.stringify({ packages: names.map((name) => ({ name })) })
        const config = { jobs: [marker], prepare: { patches: true } }

        function slotWithVendor(files) {
            const work = pushedRepo(config, { '.gitignore': 'vendor/\n', ...files })
            const composer = fakeComposer(work)
            const first = inSlot(work, composer.env)
            assert.equal(first.code, 0, first.out)
            assert.match(first.out, /Patches: +vendor\/ is not installed/, first.out)
            const slot = `${work}-ci`
            mkdirSync(join(slot, 'vendor', 'composer'), { recursive: true })
            writeFileSync(join(slot, 'vendor', 'composer', 'installed.json'), installed(['a/pkg', 'b/pkg']))
            return { work, slot, composer }
        }

        it('reinstalls the patched packages when no lock is recorded, then records the lock, and does nothing on the next run', () => {
            const current = lock({ 'a/pkg': [{ description: 'fix' }], 'z/not-installed': [{}] })
            const { work, slot, composer } = slotWithVendor({ 'patches.lock.json': current })

            const second = inSlot(work, composer.env)
            assert.equal(second.code, 0, second.out)
            assert.deepEqual(composer.calls(), ['reinstall a/pkg --no-interaction --no-progress'])
            assert.equal(readFileSync(join(slot, PATCH_LOCK_RECORD), 'utf8'), readFileSync(join(slot, 'patches.lock.json'), 'utf8'))
            assert.match(second.out, /JOB-RAN/, second.out)

            const third = inSlot(work, composer.env)
            assert.equal(third.code, 0, third.out)
            assert.equal(composer.calls().length, 1, 'no reinstall when the lock matches the record')
            assert.match(third.out, /Patches: +vendor\/ matches patches\.lock\.json/, third.out)
        })

        it('reinstalls the packages either lock patches when the commit changes the lock', () => {
            const { work, slot, composer } = slotWithVendor({ 'patches.lock.json': lock({ 'a/pkg': [{}] }) })
            assert.equal(inSlot(work, composer.env).code, 0)
            const next = lock({ 'b/pkg': [{}] })
            commitAndPush(work, { 'patches.lock.json': next }, 'move the patch')
            const { code, out } = inSlot(work, composer.env)
            assert.equal(code, 0, out)
            assert.deepEqual(composer.calls(), ['reinstall a/pkg --no-interaction --no-progress', 'reinstall a/pkg b/pkg --no-interaction --no-progress'])
            assert.match(readFileSync(join(slot, PATCH_LOCK_RECORD), 'utf8'), /"b\/pkg"/)
            assert.equal(readFileSync(join(slot, PATCH_LOCK_RECORD), 'utf8'), readFileSync(join(slot, 'patches.lock.json'), 'utf8'))
        })

        it('fails before any job, and keeps the old record, when the reinstall fails', () => {
            const before = lock({ 'a/pkg': [{}] })
            const { work, slot, composer } = slotWithVendor({ 'patches.lock.json': before })
            assert.equal(inSlot(work, composer.env).code, 0)
            commitAndPush(work, { 'patches.lock.json': lock({ 'a/pkg': [{}, {}] }) }, 'a second patch')
            const { code, out } = inSlot(work, { ...composer.env, FAKE_COMPOSER_EXIT: '3' })
            assert.equal(code, 1, out)
            assert.match(out, /composer reinstall a\/pkg failed \(exit 3\)/, out)
            assert.doesNotMatch(out, /JOB-RAN/, out)
            assert.equal(readFileSync(join(slot, PATCH_LOCK_RECORD), 'utf8').replace(/\r\n/g, '\n'), before, 'the record still describes the last good install')
        })
    })
})

describe('database: parsing', () => {
    const job = (database) => parseConfig({ jobs: [{ name: 'pest', run: 'x', database }] }).jobs[0].database

    it('is null when absent, and reads name, env and lock', () => {
        assert.equal(job(undefined), null)
        assert.deepEqual(job({ name: 'app_ci', env: 'DB_DATABASE' }), { name: 'app_ci', env: 'DB_DATABASE', lock: null })
        assert.deepEqual(job({ name: 'app_ci', env: 'DB_DATABASE', lock: { prefix: 'app-ci', heldEnv: 'HELD' } }), {
            name: 'app_ci',
            env: 'DB_DATABASE',
            lock: { prefix: 'app-ci', heldEnv: 'HELD' },
        })
        assert.deepEqual(job({ name: 'app_ci', env: 'DB_DATABASE', lock: { prefix: 'app-ci' } }).lock, { prefix: 'app-ci', heldEnv: null })
    })

    it('refuses a missing or unsafe name, a bad variable name, an unknown key and a bad lock', () => {
        assert.throws(() => job('app_ci'), /must be an object/)
        assert.throws(() => job({ env: 'DB' }), /\.name must be a database name/)
        assert.throws(() => job({ name: 'app ci; drop', env: 'DB' }), /\.name must be a database name/)
        assert.throws(() => job({ name: 'a'.repeat(65), env: 'DB' }), /\.name must be a database name/)
        assert.throws(() => job({ name: 'app_ci' }), /\.env must name the environment variable/)
        assert.throws(() => job({ name: 'app_ci', env: '1DB' }), /\.env must name the environment variable/)
        assert.throws(() => job({ name: 'app_ci', env: 'DB', host: 'x' }), /has no "host"/)
        assert.throws(() => job({ name: 'app_ci', env: 'DB', lock: {} }), /\.lock\.prefix must be/)
        assert.throws(() => job({ name: 'app_ci', env: 'DB', lock: { prefix: '../x' } }), /\.lock\.prefix must be/)
        assert.throws(() => job({ name: 'app_ci', env: 'DB', lock: { prefix: 'x', heldEnv: 'not a name' } }), /\.lock\.heldEnv must name/)
        assert.throws(() => job({ name: 'app_ci', env: 'DB', lock: { prefix: 'x', dir: '/tmp' } }), /has no "dir"/)
    })

    it('refuses env and heldEnv naming one variable, in any case, and a variable the runner relies on', () => {
        assert.throws(() => job({ name: 'app_ci', env: 'DB_X', lock: { prefix: 'x', heldEnv: 'db_x' } }), /name the same variable, DB_X/)
        assert.throws(() => job({ name: 'app_ci', env: 'Path' }), /\.env may not be Path/)
        assert.throws(() => job({ name: 'app_ci', env: 'DB', lock: { prefix: 'x', heldEnv: 'LOCAL_CI' } }), /\.lock\.heldEnv may not be LOCAL_CI/)
        assert.throws(() => job({ name: 'app_ci', env: 'TMPDIR' }), /may not be TMPDIR/)
    })
})

describe('database: pure helpers', () => {
    const pest = { name: 'pest', database: { name: 'app_ci', env: 'WP_TESTS_DB_NAME', lock: { prefix: 'app-ci', heldEnv: 'HELD' } } }
    const other = { name: 'other', database: { name: 'other_ci', env: 'OTHER_DB', lock: null } }
    const plain = { name: 'lint', database: null }

    it('databaseLockPath is <prefix>.database.<16 hex of sha256(name)>.lock in the directory given', () => {
        // From PHP: substr(hash('sha256', 'uamswp_migration_api_ci'), 0, 16), the
        // expression tests/Support/single-flight.php in uamswp-migration-api uses.
        assert.equal(databaseLockPath('uamswp-migration-api-ci', 'uamswp_migration_api_ci', 'D'), join('D', 'uamswp-migration-api-ci.database.aa67d676e411daee.lock'))
    })

    it('jobEnvironment gives a declaring job its own name only, and leaves a plain job alone', () => {
        const base = { PATH: 'p', WP_TESTS_DB_NAME: 'hand_db', OTHER_DB: 'x', HELD: '0' }
        assert.deepEqual(jobEnvironment(base, pest, [pest, other, plain]), { PATH: 'p', WP_TESTS_DB_NAME: 'app_ci', HELD: '1' })
        assert.deepEqual(jobEnvironment(base, other, [pest, other, plain]), { PATH: 'p', OTHER_DB: 'other_ci' })
        assert.deepEqual(jobEnvironment(base, plain, [pest, other, plain]), base)
    })

    it('jobEnvironment removes an inherited variable spelled in another case on Windows, where names have none', () => {
        const base = { PATH: 'p', wp_tests_db_name: 'hand_db' }
        const expected = process.platform === 'win32' ? { PATH: 'p', WP_TESTS_DB_NAME: 'app_ci', HELD: '1' } : { PATH: 'p', wp_tests_db_name: 'hand_db', WP_TESTS_DB_NAME: 'app_ci', HELD: '1' }
        assert.deepEqual(jobEnvironment(base, pest, [pest]), expected)
    })

    it('databaseLocksFor takes one lock per file, naming every job that shares it, and none for a job without a lock', () => {
        const twin = { name: 'pest-feature', database: pest.database }
        assert.deepEqual(databaseLocksFor([{ name: 'guard', guard: true }, { name: 'pest', job: pest }, { name: 'pest-feature', job: twin }, { name: 'other', job: other }, { name: 'lint', job: plain }]), [
            { prefix: 'app-ci', database: 'app_ci', jobs: ['pest', 'pest-feature'] },
        ])
    })

    describe('acquireDatabaseLock', () => {
        const dir = () => {
            const d = mkdtempSync(join(tmpdir(), 'local-ci-db-lock-'))
            scratch.push(d)
            return d
        }
        const take = (d, extra = {}) => acquireDatabaseLock({ prefix: 'app-ci', database: 'app_ci', suite: 'local-ci pest', dir: d, now: () => new Date('2026-10-05T00:00:00Z'), ...extra })

        it('creates the file with an integer pid, refuses a second holder, and releases only its own', () => {
            const d = dir()
            const first = take(d, { pid: 111, isAlive: () => true })
            assert.equal(first.ok, true)
            assert.deepEqual(JSON.parse(readFileSync(first.lockPath, 'utf8')), { pid: 111, suite: 'local-ci pest', database: 'app_ci', branch: null, startedAt: '2026-10-05T00:00:00.000Z' })

            const second = take(d, { pid: 222, isAlive: () => true })
            assert.equal(second.ok, false)
            assert.match(second.message, /Another run is using the database app_ci: pid 111 \(local-ci pest\), since 2026-10-05T00:00:00\.000Z/)

            writeFileSync(first.lockPath, JSON.stringify({ pid: 333 }))
            first.release()
            assert.equal(existsSync(first.lockPath), true, 'a lock now naming another pid is not removed')
            writeFileSync(first.lockPath, JSON.stringify({ pid: 111 }))
            first.release()
            assert.equal(existsSync(first.lockPath), false)
        })

        it('reclaims a lock whose pid is gone, and announces it', () => {
            const d = dir()
            const path = databaseLockPath('app-ci', 'app_ci', d)
            writeFileSync(path, JSON.stringify({ pid: 999, suite: 'pest --testsuite=Feature' }))
            const notes = []
            const lock = take(d, { pid: 111, isAlive: (pid) => pid !== 999, onReclaim: (line) => notes.push(line) })
            assert.equal(lock.ok, true)
            assert.equal(JSON.parse(readFileSync(path, 'utf8')).pid, 111)
            assert.match(notes.join('\n'), /pid 999, is no longer running/)
        })

        it('refuses, rather than reclaims, a record it cannot read', () => {
            const d = dir()
            const path = databaseLockPath('app-ci', 'app_ci', d)
            writeFileSync(path, '{"pid": ')
            const lock = take(d, { pid: 111, isAlive: () => false })
            assert.equal(lock.ok, false)
            assert.match(lock.message, /record cannot be read/)
            assert.equal(readFileSync(path, 'utf8'), '{"pid": ', 'the record is left as it was')
        })

        it('takes over a record naming its own pid, which a dead run left on a reused number', () => {
            const d = dir()
            const path = databaseLockPath('app-ci', 'app_ci', d)
            writeFileSync(path, JSON.stringify({ pid: 111, suite: 'old' }))
            const lock = take(d, { pid: 111, isAlive: () => true })
            assert.equal(lock.ok, true)
            assert.equal(JSON.parse(readFileSync(path, 'utf8')).suite, 'local-ci pest')
        })

        it('leaves no temporary file behind, whether it takes the lock or is refused', () => {
            const d = dir()
            const first = take(d, { pid: 111, isAlive: () => true })
            const second = take(d, { pid: 222, isAlive: () => true })
            assert.deepEqual([first.ok, second.ok], [true, false])
            assert.deepEqual(readdirSync(d), [basename(first.lockPath)])
        })

        it('names the directory when the lock cannot be written', () => {
            const missing = join(dir(), 'does-not-exist')
            assert.throws(() => take(missing, { pid: 111 }), /Could not write the lock for the database app_ci in .*does-not-exist .*must exist and be writable/)
        })
    })
})

describe('end to end: a job with its own database', () => {
    // Prints what the job sees, and whether the lock file exists while it runs.
    const probe = [
        "const fs = require('fs')",
        "const lock = process.argv[2]",
        "console.log('DB=' + (process.env.WP_TESTS_DB_NAME ?? '(unset)'))",
        "console.log('OTHER=' + (process.env.OTHER_DB ?? '(unset)'))",
        "console.log('HELD=' + (process.env.LOCAL_CI_SUITE_LOCK_HELD ?? '(unset)'))",
        "if (lock) console.log('LOCK=' + (fs.existsSync(lock) ? JSON.parse(fs.readFileSync(lock, 'utf8')).suite : 'absent'))",
        '',
    ].join('\n')
    const lockFor = (dir) => databaseLockPath('app-ci', 'app_ci', dir)

    // A temp directory of the test's own, so the run's os.tmpdir() is it and a
    // lock file is never shared with another test or a real suite.
    function lockDir() {
        const d = mkdtempSync(join(tmpdir(), 'local-ci-db-run-'))
        scratch.push(d)
        return d
    }
    const envFor = (dir, extra = {}) => ({ TMPDIR: dir, TEMP: dir, TMP: dir, WP_TESTS_DB_NAME: 'hand_db', OTHER_DB: 'other_hand_db', ...extra })

    function repoWith(dir, jobs) {
        return scratchRepo({ jobs }, { 'probe.cjs': probe })
    }
    const pest = (dir) => ({ name: 'pest', run: `node probe.cjs "${lockFor(dir)}"`, always: true, database: { name: 'app_ci', env: 'WP_TESTS_DB_NAME', lock: { prefix: 'app-ci', heldEnv: 'LOCAL_CI_SUITE_LOCK_HELD' } } })
    const other = { name: 'other', run: 'node probe.cjs', always: true, database: { name: 'other_ci', env: 'OTHER_DB' } }
    const plain = { name: 'plain', run: 'node probe.cjs', always: true }

    it('runs a declaring job with its name and the held flag, under the lock, and releases it', () => {
        const dir = lockDir()
        const work = repoWith(dir, [pest(dir), other, plain])
        const { code, out } = runCi(work, ['--all'], envFor(dir))
        assert.equal(code, 0, out)
        assert.match(out, new RegExp(`Database: +app_ci locked for pest \\(${lockFor(dir).replace(/[\\.]/g, '\\$&')}\\)`), out)
        const sections = out.split('==> JOB ')
        const section = (name) => sections.find((s) => s.startsWith(name)) ?? ''
        assert.match(section('pest'), /DB=app_ci\s+OTHER=\(unset\)\s+HELD=1\s+LOCK=local-ci pest/, out)
        assert.match(section('other'), /DB=\(unset\)\s+OTHER=other_ci\s+HELD=\(unset\)/, out)
        assert.equal(existsSync(lockFor(dir)), false, 'the lock is released when the run ends')
    })

    it('leaves a job that declares no database exactly as before (control)', () => {
        const dir = lockDir()
        const work = repoWith(dir, [pest(dir), plain])
        const { code, out } = runCi(work, ['--all'], envFor(dir, { LOCAL_CI_SUITE_LOCK_HELD: 'inherited' }))
        assert.equal(code, 0, out)
        const plainSection = out.split('==> JOB ').find((s) => s.startsWith('plain')) ?? ''
        assert.match(plainSection, /DB=hand_db\s+OTHER=other_hand_db\s+HELD=inherited/, out)

        const none = repoWith(dir, [plain])
        const run = runCi(none, ['--all'], envFor(dir))
        assert.equal(run.code, 0, run.out)
        assert.doesNotMatch(run.out, /Database:/, run.out)
        assert.match(run.out, /DB=hand_db\s+OTHER=other_hand_db/, run.out)
    })

    it('refuses a second run while a live process holds the database, naming it, before any job', () => {
        const dir = lockDir()
        const work = repoWith(dir, [plain, pest(dir)])
        // This test process stands in for a suite run by hand: it is alive and
        // it is not the runner.
        writeFileSync(lockFor(dir), JSON.stringify({ pid: process.pid, suite: 'pest --testsuite=Feature', database: 'app_ci', startedAt: '2026-10-05T00:00:00+00:00' }))
        const { code, out } = runCi(work, ['--all'], envFor(dir))
        assert.equal(code, 4, out)
        assert.match(out, new RegExp(`Another run is using the database app_ci: pid ${process.pid} \\(pest --testsuite=Feature\\)`), out)
        assert.doesNotMatch(out, /==> JOB/, out)
        assert.equal(JSON.parse(readFileSync(lockFor(dir), 'utf8')).pid, process.pid, 'the holder keeps its lock')
    })

    it('reclaims a lock a dead process left, and runs', () => {
        const dir = lockDir()
        const work = repoWith(dir, [pest(dir)])
        const gone = spawnSync(process.execPath, ['-e', '']).pid
        writeFileSync(lockFor(dir), JSON.stringify({ pid: gone, suite: 'pest --testsuite=Feature' }))
        const { code, out } = runCi(work, ['--all'], envFor(dir))
        assert.equal(code, 0, out)
        assert.match(out, new RegExp(`pid ${gone}, is no longer running`), out)
        assert.match(out, /LOCK=local-ci pest/, out)
    })

    it('releases a lock already taken when a later one is refused', () => {
        const dir = lockDir()
        const second = { name: 'second', run: 'node probe.cjs', always: true, database: { name: 'second_ci', env: 'OTHER_DB', lock: { prefix: 'app-ci' } } }
        const work = repoWith(dir, [pest(dir), second])
        const secondLock = databaseLockPath('app-ci', 'second_ci', dir)
        writeFileSync(secondLock, JSON.stringify({ pid: process.pid, suite: 'hand run' }))
        const { code, out } = runCi(work, ['--all'], envFor(dir))
        assert.equal(code, 4, out)
        assert.match(out, /Database: +app_ci locked for pest/, out)
        assert.match(out, /Another run is using the database second_ci/, out)
        assert.equal(existsSync(lockFor(dir)), false, 'the first lock is released on the way out')
        assert.equal(JSON.parse(readFileSync(secondLock, 'utf8')).pid, process.pid)
    })

    it('--dry-run names the database and its lock file, and takes no lock', () => {
        const dir = lockDir()
        const work = repoWith(dir, [pest(dir)])
        writeFileSync(lockFor(dir), JSON.stringify({ pid: process.pid }))
        const { code, out } = runCi(work, ['--all', '--dry-run'], envFor(dir))
        assert.equal(code, 0, out)
        assert.match(out, /database app_ci in WP_TESTS_DB_NAME, locked at /, out)
        assert.equal(JSON.parse(readFileSync(lockFor(dir), 'utf8')).pid, process.pid)
    })
})

describe('commit status: the verdict posted on the validated commit', () => {
    const SHA = 'c'.repeat(40)
    const tree = { sha: SHA, subject: 's', dirty: null }
    const pass = (name) => ({ name, status: 'PASS', ms: 1, code: 0 })
    const fail = (name) => ({ name, status: 'FAIL', ms: 1, code: 1 })
    const skipped = (name, extra = {}) => ({ name, status: 'SKIPPED', reason: 'not selected: x', ms: 0, ...extra })

    it('takes the context from the config, defaulting to local-ci, and refuses a bad one', () => {
        assert.equal(parseConfig({ jobs: [] }).statusContext, 'local-ci')
        assert.equal(parseConfig({ jobs: [], statusContext: ' ci/local ' }).statusContext, 'ci/local')
        assert.throws(() => parseConfig({ jobs: [], statusContext: '' }), /"statusContext" must be a non-empty string/)
        assert.throws(() => parseConfig({ jobs: [], statusContext: 3 }), /"statusContext" must be a non-empty string/)
        assert.throws(() => parseConfig({ jobs: [], statusContext: 'a\nb' }), /"statusContext" must be a non-empty string/)
        assert.throws(() => parseConfig({ jobs: [], statusContext: 'x'.repeat(256) }), /"statusContext" must be a non-empty string/)
    })

    it('is opt-in: --commit-status sets the option and nothing else does', () => {
        assert.equal(parseArgs([]).commitStatus, false)
        assert.equal(parseArgs(['--all', '--comment']).commitStatus, false)
        assert.equal(parseArgs(['--commit-status']).commitStatus, true)
    })

    it('is success when the run exits 0 and failure when it exits 1, on the validated commit and the configured context', () => {
        assert.deepEqual(commitStatusFor(tree, [pass('a'), pass('b')], 'ci/local'), { sha: SHA, state: 'success', context: 'ci/local', description: 'PASS: 2 of 2 jobs run passed' })
        const red = commitStatusFor(tree, [pass('a'), fail('pest'), fail('pint')], 'local-ci')
        assert.equal(red.state, 'failure')
        assert.equal(red.sha, SHA)
        assert.equal(red.description, 'pest, pint failed')
    })

    it('keeps the failing names first and the description inside the 140 characters GitHub allows', () => {
        const many = Array.from({ length: 40 }, (_, i) => fail(`job-number-${i}`))
        const { description } = commitStatusFor(tree, many, 'local-ci')
        assert.equal(description.length, 140)
        assert.ok(description.startsWith('job-number-0, job-number-1, '))
    })

    it('asserts nothing when no job ran, and an incomplete pass says so', () => {
        assert.equal(commitStatusFor(tree, [skipped('a'), skipped('b', { absent: true })], 'local-ci'), null)
        assert.equal(commitStatusFor(tree, [], 'local-ci'), null)
        const incomplete = commitStatusFor(tree, [pass('a'), skipped('b', { absent: true })], 'local-ci')
        assert.equal(incomplete.state, 'success')
        assert.match(incomplete.description, /^PASS \(incomplete\)/)
    })

    it('builds the REST call for the statuses endpoint on that commit', () => {
        const args = commitStatusArgs(commitStatusFor(tree, [fail('pest')], 'ci/local'))
        assert.deepEqual(args, ['api', '-X', 'POST', `repos/{owner}/{repo}/statuses/${SHA}`, '-f', 'state=failure', '-f', 'context=ci/local', '-f', 'description=pest failed'])
    })

    it('posts through gh with the call stubbed, and reports a refusal as a warning rather than a throw', () => {
        const calls = []
        const ok = (gh, args, options) => (calls.push({ gh, args, cwd: options.cwd }), { status: 0 })
        assert.equal(postCommitStatus('/repo', tree, [pass('a')], 'local-ci', { gh: '/bin/gh', spawn: ok }), true)
        assert.equal(calls.length, 1)
        assert.equal(calls[0].gh, '/bin/gh')
        assert.equal(calls[0].cwd, '/repo')
        assert.equal(calls[0].args[3], `repos/{owner}/{repo}/statuses/${SHA}`)
        assert.ok(calls[0].args.includes('state=success'))

        const refused = () => ({ status: 1, stderr: 'HTTP 403: Resource not accessible\nmore' })
        assert.equal(postCommitStatus('/repo', tree, [pass('a')], 'local-ci', { gh: '/bin/gh', spawn: refused }), false)
        const spawnFails = () => ({ status: null, error: new Error('spawn ENOENT') })
        assert.equal(postCommitStatus('/repo', tree, [pass('a')], 'local-ci', { gh: '/bin/gh', spawn: spawnFails }), false)
    })

    it('posts nothing without gh, and nothing when no job ran', () => {
        const calls = []
        const spy = (...call) => (calls.push(call), { status: 0 })
        assert.equal(postCommitStatus('/repo', tree, [pass('a')], 'local-ci', { gh: null, spawn: spy }), false)
        assert.equal(postCommitStatus('/repo', tree, [skipped('a')], 'local-ci', { gh: '/bin/gh', spawn: spy }), false)
        assert.deepEqual(calls, [])
    })
})

describe('end to end: --commit-status', { skip: !POSIX && 'the fake gh is a POSIX shell script' }, () => {
    // A fake gh on PATH that logs every call and exits $FAKE_EXIT.
    function run(work, args, { exit = 0 } = {}) {
        const bin = join(dirname(work), 'bin')
        mkdirSync(bin, { recursive: true })
        writeFileSync(join(bin, 'gh'), '#!/bin/sh\necho "gh $*" >> "$FAKE_LOG"\nexit "$FAKE_EXIT"\n')
        chmodSync(join(bin, 'gh'), 0o755)
        const log = join(dirname(work), 'gh.log')
        writeFileSync(log, '')
        const res = runCi(work, args, { PATH: `${bin}:${process.env.PATH}`, FAKE_LOG: log, FAKE_EXIT: String(exit) })
        return { ...res, calls: readFileSync(log, 'utf8') }
    }
    const ok = { name: 'ok', run: 'node -e "0"', always: true }
    const bad = { name: 'bad', run: 'node -e "process.exit(1)"', always: true }

    it('posts success on the exact commit it validated, under the configured context, beside no other call', () => {
        const work = scratchRepo({ statusContext: 'ci/local', jobs: [ok] }, { 'a.txt': 'x\n' })
        const head = git(work, 'rev-parse', 'HEAD').trim()
        const { code, out, calls } = run(work, ['--commit-status'])
        assert.equal(calls.trim().split('\n').length, 1, calls)
        assert.match(calls, new RegExp(`^gh api -X POST repos/\\{owner\\}/\\{repo\\}/statuses/${head} -f state=success -f context=ci/local -f description=PASS: 1 of 1 job run passed\n?$`), calls)
        assert.match(out, new RegExp(`Commit status: success on ${head.slice(0, 9)} \\(context ci/local\\)`), out)
        assert.equal(code, 0, out)
    })

    it('posts failure for a red run and still exits 1', () => {
        const work = scratchRepo({ jobs: [ok, bad] }, { 'a.txt': 'x\n' })
        const { code, calls } = run(work, ['--commit-status'])
        assert.match(calls, /-f state=failure -f context=local-ci -f description=bad failed/, calls)
        assert.equal(code, 1)
    })

    it('without the option it calls gh not at all', () => {
        const work = scratchRepo({ jobs: [ok] }, { 'a.txt': 'x\n' })
        const { code, calls } = run(work, [])
        assert.equal(calls, '')
        assert.equal(code, 0)
    })

    it('a refused post warns and leaves the exit status alone', () => {
        const work = scratchRepo({ jobs: [ok] }, { 'a.txt': 'x\n' })
        const { code, out } = run(work, ['--commit-status'], { exit: 1 })
        assert.match(out, /commit status not posted \(gh exit 1\)/, out)
        assert.equal(code, 0, out)
    })

    it('is refused on a dirty tree, before any job runs', () => {
        const work = scratchRepo({ jobs: [ok] }, { 'a.txt': 'x\n' })
        writeFileSync(join(work, 'a.txt'), 'changed\n')
        const { code, out, calls } = run(work, ['--commit-status'])
        assert.match(out, /--commit-status is refused on a dirty tree/, out)
        assert.equal(calls, '')
        assert.equal(code, 1)
    })
})

describe('parseConfig: unknown keys', () => {
    const withJob = (extra) => parseConfig({ jobs: [{ name: 'pint', run: 'x' }, { name: 'pest', run: 'y', ...extra }] })

    it('refuses a misspelled job key, naming the key, the job and the accepted keys', () => {
        assert.throws(
            () => withJob({ databse: { name: 'a', env: 'B' } }),
            /^Error: jobs\[1\] \("pest"\) has no "databse"; it takes "name", "run", "always", "each", "paths", "requires", "mutation", "parallel", "database"\.$/,
        )
    })

    it('refuses a misspelled top-level key, naming it and the accepted keys', () => {
        assert.throws(
            () => parseConfig({ statusContxt: 'x', jobs: [] }),
            /^Error: the top level has no "statusContxt"; it takes "base", "php", "chain", "guard", "jobs", "prepare", "statusContext", "watch"\.$/,
        )
    })

    it('lists every unknown key at once', () => {
        assert.throws(() => parseConfig({ chian: true, phpp: '8.2', jobs: [] }), /has no "chian", "phpp"; it takes/)
    })

    it('refuses a misspelled key inside mutation and parallel', () => {
        assert.throws(() => withJob({ mutation: { minScor: 90 } }), /jobs\[1\]\.mutation has no "minScor"; it takes "minScore"\./)
        assert.throws(
            () => withJob({ run: 'vendor/bin/pest', parallel: { serialGrup: 'serial' } }),
            /jobs\[1\]\.parallel has no "serialGrup"; it takes "serialGroup", "memoryLimit"\./,
        )
    })

    it('parses a config that uses every documented key', () => {
        const config = parseConfig({
            base: 'develop',
            php: '8.3',
            chain: true,
            statusContext: 'ci/local',
            guard: { deny: ['\.sql$'] },
            prepare: { submodules: ['content'], residue: ['tests/tmp'], patches: true },
            watch: { repos: ['UAMS-Web/example'], issueRepos: ['UAMS-Web/example'], glyphs: true },
            jobs: [
                { name: 'lint', run: 'php -l', each: ['**/*.php'], always: true, requires: ['bin:php'] },
                { name: 'docs', run: 'node check.mjs', paths: ['docs/**'] },
                { name: 'mutate', run: 'vendor/bin/pest --mutate', mutation: { minScore: 90 } },
                {
                    name: 'pest',
                    run: 'vendor/bin/pest',
                    parallel: { serialGroup: 'serial', memoryLimit: '4G' },
                    database: { name: 'app_ci', env: 'DB_DATABASE', lock: { prefix: 'app-ci', heldEnv: 'HELD' } },
                },
            ],
        })
        assert.equal(config.base, 'develop')
        assert.equal(config.php, '8.3')
        assert.equal(config.chain, true)
        assert.equal(config.statusContext, 'ci/local')
        assert.equal(config.deny.length, 1)
        assert.deepEqual(config.prepare, { submodules: ['content'], residue: ['tests/tmp'], patches: true })
        assert.deepEqual(config.jobs.map((j) => j.name), ['lint', 'docs', 'mutate', 'pest'])
        assert.equal(config.jobs[0].always, true)
        assert.deepEqual(config.jobs[0].requires.length, 1)
        assert.equal(config.jobs[2].mutation.minScore, 90)
        assert.equal(config.jobs[3].parallel.memoryLimit, '4G')
        assert.equal(config.jobs[3].database.lock.heldEnv, 'HELD')
    })

    it('allows a key beginning with an underscore as an annotation, at the top level and on a job', () => {
        const config = parseConfig({ _note: 'why', jobs: [{ name: 'pint', run: 'x', _why: 'cheap' }] })
        assert.equal(config.jobs[0].name, 'pint')
    })

    it('refuses an underscore key inside a nested object, where only the documented keys are read', () => {
        assert.throws(() => withJob({ mutation: { _note: 'x' } }), /jobs\[1\]\.mutation has no "_note"/)
        assert.throws(() => withJob({ database: { name: 'a', env: 'B', _note: 'x' } }), /jobs\[1\]\.database has no "_note"/)
        assert.throws(() => parseConfig({ prepare: { _note: 'x' }, jobs: [] }), /"prepare" has no "_note"/)
    })

    it('does not let an annotation hide a typo beside it, and refuses $schema and comment', () => {
        assert.throws(() => parseConfig({ _note: 'x', chian: true, jobs: [] }), /has no "chian"/)
        assert.throws(() => withJob({ _note: 'x', databse: {} }), /has no "databse"/)
        assert.throws(() => parseConfig({ $schema: 'x', jobs: [] }), /has no "\$schema"/)
        assert.throws(() => withJob({ comment: 'x' }), /has no "comment"/)
    })
})
