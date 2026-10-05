// Unit tests for local-ci.mjs's pure functions.
// Run: node --test .claude/skills/local-ci/local-ci.test.mjs
//
// Importing local-ci.mjs runs nothing: its main block is guarded by
// `import.meta.url === pathToFileURL(process.argv[1]).href`, which is false
// for every importer other than a direct `node local-ci.mjs` invocation.
import { after, describe, it } from 'node:test'
import assert from 'node:assert/strict'
import { spawnSync } from 'node:child_process'
import { chmodSync, copyFileSync, mkdirSync, mkdtempSync, readFileSync, rmSync, unlinkSync, writeFileSync } from 'node:fs'
import { tmpdir } from 'node:os'
import { dirname, join } from 'node:path'
import { fileURLToPath } from 'node:url'

import {
    commandFor,
    commentBody,
    eachTargets,
    exitStatus,
    fileArgument,
    formatDuration,
    formatSummary,
    globToRegExp,
    guardViolations,
    matchesGlob,
    needsPhp,
    parseArgs,
    parseConfig,
    parseStatusZ,
    phpCandidates,
    requireMergeBase,
    runJobs,
    selectJobs,
    shellQuote,
    verdictOf,
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
        assert.deepEqual(parseArgs([]), { all: false, jobs: null, base: null, dryRun: false, comment: false, help: false })
    })

    it('reads --jobs as a list and --base as a ref', () => {
        const opts = parseArgs(['--jobs', 'pint, pest', '--base', 'dev', '--dry-run', '--comment'])
        assert.deepEqual(opts.jobs, ['pint', 'pest'])
        assert.equal(opts.base, 'dev')
        assert.equal(opts.dryRun, true)
        assert.equal(opts.comment, true)
    })

    it('refuses an unknown option, a missing value, and --all with --jobs', () => {
        assert.throws(() => parseArgs(['--branch', 'x']), /Unknown option: --branch/)
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

describe('the template', () => {
    it('selects pint when only composer.lock changed, as it selects the other vendor tools', () => {
        const template = parseConfig(readFileSync(join(HERE, 'templates', 'local-ci.json'), 'utf8'))
        const { selected } = selectJobs(template, ['composer.lock'], {})
        assert.deepEqual(selected.map((e) => e.name), ['guard', 'lint', 'pint', 'rector', 'phpstan', 'pest'])
    })
})
