import assert from 'node:assert/strict'
import { mkdtempSync, readFileSync, rmSync, writeFileSync } from 'node:fs'
import { tmpdir } from 'node:os'
import { dirname, join } from 'node:path'
import { describe, it } from 'node:test'
import { fileURLToPath } from 'node:url'

import { classifyIssue, commentNumber, isPush, main, parseArgs, pollAll, repoFromRemote, watchConfig, watchingEvent } from './watch-github.mjs'

const here = dirname(fileURLToPath(import.meta.url))
const NONE = { repos: [], issueRepos: [], origin: null }

const pull = (number, { draft = false, sha = 'a1b2c3d4e5'.repeat(4), title = `Title ${number}`, body = 'Closes nothing.' } = {}) => ({
    number,
    title,
    draft,
    html_url: `https://github.com/acme/widgets/pull/${number}`,
    head: { sha },
    body,
})

const issue = (number, extra = {}) => ({
    number,
    title: `Issue ${number}`,
    state: 'open',
    created_at: '2000-01-01T00:00:00Z',
    closed_at: null,
    state_reason: null,
    labels: [],
    html_url: `https://github.com/acme/widgets/issues/${number}`,
    ...extra,
})

// A fake `gh` that serves recorded responses by REST path, and records every
// call so a test can assert nothing but GET reads happened.
function fakeGh(routes) {
    const calls = []
    const exec = async (_gh, args) => {
        calls.push(args)
        const path = args[1]
        const key = Object.keys(routes).find((candidate) => path.startsWith(candidate))
        if (key === undefined) {
            throw Object.assign(new Error(`no route for ${path}`), { stderr: `no route for ${path}` })
        }
        const value = typeof routes[key] === 'function' ? routes[key](path, args) : routes[key]
        return { stdout: typeof value === 'string' ? value : JSON.stringify(value) }
    }
    return { exec, calls }
}

async function capture(run) {
    const lines = []
    const original = console.log
    console.log = (line) => lines.push(line)
    try {
        await run()
    } finally {
        console.log = original
    }
    return lines
}

describe('parseArgs', () => {
    it('falls back to the configured repositories, then the origin remote', () => {
        assert.deepEqual(parseArgs([], { ...NONE, repos: ['acme/a', 'acme/b'], origin: 'acme/o' }).prRepos, ['acme/a', 'acme/b'])
        assert.deepEqual(parseArgs([], { ...NONE, origin: 'acme/o' }).prRepos, ['acme/o'])
    })

    it('lets --repos beat both defaults', () => {
        assert.deepEqual(parseArgs(['--repos', 'x/y, x/z'], { ...NONE, repos: ['acme/a'], origin: 'acme/o' }).prRepos, ['x/y', 'x/z'])
        assert.deepEqual(parseArgs(['--repos=x/y'], NONE).prRepos, ['x/y'])
    })

    it('refuses to start with nothing to watch', () => {
        assert.throws(() => parseArgs([], NONE), /Nothing to watch/)
        assert.throws(() => parseArgs(['--repos', ''], { ...NONE, origin: 'acme/o' }), /Nothing to watch/)
    })

    it('treats --issue-repos as implying --issues and scopes the issue feed separately', () => {
        const options = parseArgs(['--repos', 'a/b', '--issue-repos', 'c/d'], NONE)
        assert.deepEqual(options.prRepos, ['a/b'])
        assert.deepEqual(options.issueRepos, ['c/d'])
    })

    it('defaults the issue feed to the pull request list, and keeps it off without --issues', () => {
        assert.deepEqual(parseArgs(['--repos', 'a/b', '--issues'], NONE).issueRepos, ['a/b'])
        assert.deepEqual(parseArgs(['--repos', 'a/b'], NONE).issueRepos, [])
    })

    it('uses watch.issueRepos from config when --issues is set', () => {
        assert.deepEqual(parseArgs(['--issues'], { ...NONE, repos: ['a/b'], issueRepos: ['c/d'] }).issueRepos, ['c/d'])
    })

    it('--no-prs needs an issue feed', () => {
        assert.throws(() => parseArgs(['--no-prs', '--repos', 'a/b'], NONE), /--no-prs leaves nothing to watch/)
        const options = parseArgs(['--no-prs', '--issues', '--repos', 'a/b'], NONE)
        assert.deepEqual(options.prRepos, [])
        assert.deepEqual(options.issueRepos, ['a/b'])
    })

    it('floors the interval and rejects a non-number', () => {
        assert.equal(parseArgs(['--repos', 'a/b', '--interval', '1'], NONE).interval, 10)
        assert.throws(() => parseArgs(['--repos', 'a/b', '--interval', 'soon'], NONE), /--interval/)
    })

    it('rejects an unknown flag, and rejects --assignee specifically', () => {
        assert.throws(() => parseArgs(['--repos', 'a/b', '--assignee', 'me'], NONE), /Unknown flag: --assignee/)
    })
})

describe('repoFromRemote and watchConfig', () => {
    it('parses https, ssh and scp-like remotes, and nothing else', () => {
        assert.equal(repoFromRemote('https://github.com/acme/widgets.git\n'), 'acme/widgets')
        assert.equal(repoFromRemote('https://github.com/acme/widgets'), 'acme/widgets')
        assert.equal(repoFromRemote('git@github.com:acme/widgets.git'), 'acme/widgets')
        assert.equal(repoFromRemote('ssh://git@github.com/acme/widgets.git'), 'acme/widgets')
        assert.equal(repoFromRemote('https://example.com/acme/widgets.git'), null)
        assert.equal(repoFromRemote(''), null)
    })

    it('reads watch.repos and watch.issueRepos, and ignores a config with no watch section', () => {
        assert.deepEqual(watchConfig({ watch: { repos: ['a/b'], issueRepos: ['c/d'] } }), { repos: ['a/b'], issueRepos: ['c/d'], glyphs: false })
        assert.deepEqual(watchConfig({ base: 'main' }), { repos: [], issueRepos: [], glyphs: false })
        assert.equal(watchConfig({ watch: { glyphs: true } }).glyphs, true)
    })

    it('rejects a malformed watch section rather than guessing', () => {
        assert.throws(() => watchConfig({ watch: [] }), /must be an object/)
        assert.throws(() => watchConfig({ watch: { repos: 'a/b' } }), /watch\.repos/)
        assert.throws(() => watchConfig({ watch: { repos: ['not a slug'] } }), /watch\.repos/)
        assert.throws(() => watchConfig({ watch: { issueRepos: [1] } }), /watch\.issueRepos/)
        assert.throws(() => watchConfig({ watch: { glyphs: 'yes' } }), /watch\.glyphs/)
    })
})

describe('isPush and classifyIssue', () => {
    it('isPush needs two SHAs, two non-drafts, and a difference', () => {
        assert.equal(isPush({ sha: 'a' }, { sha: 'b' }), true)
        assert.equal(isPush({ sha: 'a' }, { sha: 'a' }), false)
        assert.equal(isPush({ sha: null }, { sha: 'b' }), false)
        assert.equal(isPush({ sha: 'a' }, { sha: null }), false)
        assert.equal(isPush({ sha: 'a', draft: true }, { sha: 'b' }), false)
        assert.equal(isPush({ sha: 'a' }, { sha: 'b', draft: true }), false)
    })

    it('classifies opened, closed, not planned, and merely edited issues', () => {
        const since = '2026-01-01T00:00:00Z'
        assert.deepEqual(classifyIssue({ state: 'open', createdAt: '2026-02-01T00:00:00Z' }, since), { kind: 'ISSUE', transition: 'opened' })
        assert.deepEqual(classifyIssue({ state: 'closed', createdAt: '2025-01-01T00:00:00Z', closedAt: '2026-02-01T00:00:00Z', stateReason: 'completed' }, since), { kind: 'ISSUE-GONE', transition: 'completed' })
        assert.deepEqual(classifyIssue({ state: 'closed', createdAt: '2025-01-01T00:00:00Z', closedAt: '2026-02-01T00:00:00Z', stateReason: 'not_planned' }, since), { kind: 'ISSUE-GONE', transition: 'not planned' })
        assert.equal(classifyIssue({ state: 'open', createdAt: '2025-01-01T00:00:00Z' }, since), null)
        assert.deepEqual(classifyIssue({ state: 'closed', createdAt: '2026-02-01T00:00:00Z', closedAt: '2026-02-02T00:00:00Z', stateReason: 'completed' }, since).kind, 'ISSUE-GONE')
    })
})

describe('pull request events from recorded responses', () => {
    async function seeded(first, { argv = ['--once'], routes = {} } = {}) {
        let pulls = first
        const gh = fakeGh({
            'repos/acme/widgets/pulls?': () => pulls,
            ...routes,
        })
        const seedLines = await capture(() => main(['--repos', 'acme/widgets', ...argv], { exec: gh.exec, defaults: NONE }))
        return {
            gh,
            seedLines,
            next: async (after) => {
                pulls = after
                return capture(() => pollAll())
            },
        }
    }

    it('seeds silently, then reports opened, flipped, pushed events with the documented lines', async () => {
        const { seedLines, next } = await seeded([pull(1, { draft: true }), pull(2), pull(3)])
        assert.deepEqual(seedLines, [])

        const lines = await next([
            pull(1),
            pull(2, { sha: 'b1c2d3e4f5'.repeat(4) }),
            pull(3, { draft: true }),
            pull(4),
            pull(5, { draft: true }),
        ])

        assert.deepEqual(lines, [
            'READY  widgets#1  draft → ready  Title 1  https://github.com/acme/widgets/pull/1',
            'PUSH  widgets#2  pushed b1c2d3e4f  Title 2  https://github.com/acme/widgets/pull/2',
            'DRAFT  widgets#3  ready → draft  Title 3  https://github.com/acme/widgets/pull/3',
            'READY  widgets#4  opened ready  Title 4  https://github.com/acme/widgets/pull/4',
            'DRAFT  widgets#5  opened draft  Title 5  https://github.com/acme/widgets/pull/5',
        ])
    })

    it('does not report a push on a draft, and reports a flip with a push as the flip only', async () => {
        const { next } = await seeded([pull(1, { draft: true }), pull(2, { draft: true })])
        const lines = await next([pull(1, { draft: true, sha: 'c1d2e3f4a5'.repeat(4) }), pull(2, { sha: 'c1d2e3f4a5'.repeat(4) })])
        assert.deepEqual(lines, ['READY  widgets#2  draft → ready  Title 2  https://github.com/acme/widgets/pull/2'])
    })

    it('reports GONE with merged or closed from the follow-up read', async () => {
        const { next } = await seeded([pull(1), pull(2)], {
            routes: {
                'repos/acme/widgets/pulls/1': 'merged',
                'repos/acme/widgets/pulls/2': 'closed',
            },
        })
        const lines = await next([])
        assert.deepEqual(lines, [
            'GONE  widgets#1  merged  Title 1  https://github.com/acme/widgets/pull/1',
            'GONE  widgets#2  closed  Title 2  https://github.com/acme/widgets/pull/2',
        ])
    })

    it('says nothing about a pull request that left the listing but is still open, and does not re-announce it', async () => {
        const { next } = await seeded([pull(1)], { routes: { 'repos/acme/widgets/pulls/1': 'still open' } })
        assert.deepEqual(await next([]), [])
        assert.deepEqual(await next([pull(1)]), [])
    })

    it('falls back to closed when the follow-up read fails', async () => {
        const { next } = await seeded([pull(1)])
        const lines = await next([])
        assert.deepEqual(lines, ['GONE  widgets#1  closed  Title 1  https://github.com/acme/widgets/pull/1'])
    })

    it('--emit-existing announces the open pull requests on the seed poll', async () => {
        const { seedLines } = await seeded([pull(1, { draft: true }), pull(2)], { argv: ['--once', '--emit-existing'] })
        assert.deepEqual(seedLines, [
            'DRAFT  widgets#1  already open, draft  Title 1  https://github.com/acme/widgets/pull/1',
            'READY  widgets#2  already open, ready  Title 2  https://github.com/acme/widgets/pull/2',
        ])
    })

    it('walks every page, 100 at a time, and reads only', async () => {
        const full = Array.from({ length: 100 }, (_, index) => pull(index + 1))
        const tail = [pull(101)]
        const gh = fakeGh({
            'repos/acme/widgets/pulls?': (path) => (path.endsWith('&page=1') ? full : path.endsWith('&page=2') ? tail : []),
        })
        await capture(() => main(['--repos', 'acme/widgets', '--once'], { exec: gh.exec, defaults: NONE }))

        const pages = gh.calls.map((args) => args[1])
        assert.equal(pages.length, 2)
        assert.match(pages[0], /per_page=100&page=1$/)
        assert.match(pages[1], /per_page=100&page=2$/)
        assert.ok(gh.calls.every((args) => args[0] === 'api' && !args.includes('--paginate') && !args.includes('-X')))
    })

    it('a pull request on page 2 is part of the open set, not a GONE', async () => {
        const full = Array.from({ length: 100 }, (_, index) => pull(index + 1))
        let extra = [pull(101)]
        const gh = fakeGh({ 'repos/acme/widgets/pulls?': (path) => (path.endsWith('&page=1') ? full : path.endsWith('&page=2') ? extra : []) })
        await capture(() => main(['--repos', 'acme/widgets', '--once'], { exec: gh.exec, defaults: NONE }))
        extra = [pull(101)]
        assert.deepEqual(await capture(() => pollAll()), [])
    })

    it('alerts at once when the first poll fails, then at the third consecutive failure', async () => {
        let failing = true
        const gh = fakeGh({
            'repos/acme/widgets/pulls?': () => {
                if (failing) {
                    throw Object.assign(new Error('boom'), { stderr: 'HTTP 404: Not Found\nmore' })
                }
                return []
            },
        })
        const seed = await capture(() => main(['--repos', 'acme/widgets', '--once'], { exec: gh.exec, defaults: NONE }))
        assert.deepEqual(seed, ['WATCH-ERROR  widgets  1 consecutive poll failures  HTTP 404: Not Found'])

        // Seeded as null still, so every failure keeps alerting until one succeeds.
        failing = false
        assert.deepEqual(await capture(() => pollAll()), [])
    })

    it('stays quiet through two failures after seeding and alerts on the third, without phantom events', async () => {
        let failing = false
        let pulls = [pull(1)]
        const gh = fakeGh({
            'repos/acme/widgets/pulls?': () => {
                if (failing) {
                    throw Object.assign(new Error('boom'), { stderr: 'HTTP 502' })
                }
                return pulls
            },
        })
        await capture(() => main(['--repos', 'acme/widgets', '--once'], { exec: gh.exec, defaults: NONE }))

        failing = true
        assert.deepEqual(await capture(() => pollAll()), [])
        assert.deepEqual(await capture(() => pollAll()), [])
        assert.deepEqual(await capture(() => pollAll()), ['WATCH-ERROR  widgets  3 consecutive poll failures  HTTP 502'])

        failing = false
        assert.deepEqual(await capture(() => pollAll()), [])
    })

    it('refuses a non-array payload that exited 0', async () => {
        const gh = fakeGh({ 'repos/acme/widgets/pulls?': { message: 'Bad credentials' } })
        const lines = await capture(() => main(['--repos', 'acme/widgets', '--once'], { exec: gh.exec, defaults: NONE }))
        assert.match(lines[0], /^WATCH-ERROR  widgets  1 consecutive poll failures  Expected an array/)
    })

    it('refuses to loop past the page ceiling', async () => {
        const full = Array.from({ length: 100 }, (_, index) => pull(index + 1))
        const gh = fakeGh({ 'repos/acme/widgets/pulls?': full })
        const lines = await capture(() => main(['--repos', 'acme/widgets', '--once'], { exec: gh.exec, defaults: NONE }))
        assert.match(lines[0], /Pagination exceeded 100 pages/)
        assert.equal(gh.calls.length, 100)
    })
})

describe('issue events from recorded responses', () => {
    it('sets the watermark silently, then reports opened and closed issues, never pull requests', async () => {
        let issues = []
        const gh = fakeGh({ 'repos/acme/widgets/issues?': () => issues })
        const argv = ['--repos', 'acme/widgets', '--no-prs', '--issues', '--once']

        assert.deepEqual(await capture(() => main(argv, { exec: gh.exec, defaults: NONE })), [])
        assert.equal(gh.calls.length, 0, 'the seed poll sets a watermark and reads nothing')

        const future = '2999-01-01T00:00:00Z'
        issues = [
            issue(10, { created_at: future, labels: [{ name: 'afk' }, { name: 'bug' }] }),
            issue(11, { state: 'closed', closed_at: future, state_reason: 'completed' }),
            issue(12, { state: 'closed', closed_at: future, state_reason: 'not_planned' }),
            issue(13),
            { ...issue(14, { created_at: future }), pull_request: { url: 'x' } },
        ]
        const lines = await capture(() => pollAll())

        assert.deepEqual(lines, [
            'ISSUE  widgets#10  opened  Issue 10  [afk,bug]  https://github.com/acme/widgets/issues/10',
            'ISSUE-GONE  widgets#11  completed  Issue 11  https://github.com/acme/widgets/issues/11',
            'ISSUE-GONE  widgets#12  not planned  Issue 12  https://github.com/acme/widgets/issues/12',
        ])
        assert.match(gh.calls[0][1], /^repos\/acme\/widgets\/issues\?state=all&sort=updated&direction=desc&since=.*&per_page=100&page=1$/)

        // An inclusive `since` returns the same issue again: it must not fire twice.
        assert.deepEqual(await capture(() => pollAll()), [])
    })

    it('does not touch the pulls endpoint under --no-prs, and not the issues endpoint without --issues', async () => {
        const issuesOnly = fakeGh({ 'repos/acme/widgets/issues?': [] })
        await capture(() => main(['--repos', 'acme/widgets', '--no-prs', '--issues', '--once'], { exec: issuesOnly.exec, defaults: NONE }))
        await capture(() => pollAll())
        assert.ok(issuesOnly.calls.every((args) => !args[1].includes('/pulls')))

        const pullsOnly = fakeGh({ 'repos/acme/widgets/pulls?': [] })
        await capture(() => main(['--repos', 'acme/widgets', '--once'], { exec: pullsOnly.exec, defaults: NONE }))
        await capture(() => pollAll())
        assert.ok(pullsOnly.calls.every((args) => !args[1].includes('/issues')))
    })

    it('holds the watermark on a failed poll and alerts on the third failure', async () => {
        let failing = false
        const gh = fakeGh({
            'repos/acme/widgets/issues?': () => {
                if (failing) {
                    throw Object.assign(new Error('boom'), { stderr: 'HTTP 500' })
                }
                return []
            },
        })
        await capture(() => main(['--repos', 'acme/widgets', '--no-prs', '--issues', '--once'], { exec: gh.exec, defaults: NONE }))

        failing = true
        assert.deepEqual(await capture(() => pollAll()), [])
        assert.deepEqual(await capture(() => pollAll()), [])
        assert.deepEqual(await capture(() => pollAll()), ['WATCH-ERROR  widgets  3 consecutive issue-poll failures  HTTP 500'])
    })
})

describe('watchingEvent', () => {
    it('names both feeds, the off one included, and what was seeded', async () => {
        const gh = fakeGh({ 'repos/acme/widgets/pulls?': [pull(1), pull(2)] })
        await capture(() => main(['--repos', 'acme/widgets', '--once'], { exec: gh.exec, defaults: NONE }))

        assert.deepEqual(watchingEvent(60), {
            subject: 'PRs on 1 repo, issues off',
            transition: 'every 60s',
            detail: 'open PRs at start: widgets:2',
        })
    })
})

describe('the glyph scan (--glyphs)', () => {
    // Built from code points, so this file carries no glyph of its own for the
    // repository's glyph check to find.
    const WARNING = String.fromCodePoint(0x26A0, 0xFE0F)
    const CHECK = String.fromCodePoint(0x2705)
    const dirtyBody = `Closes #1.\n\nAll good ${CHECK}\n\n${WARNING} careful`
    const cleanBody = 'Closes #2.\n\nPlain prose, with **bold** and `code`.'

    // The field set of a real `GET repos/{o}/{r}/issues/comments` item, read from
    // the API; only the values are invented.
    const comment = (id, number, body, updated = '2030-01-01T00:00:00Z') => ({
        url: `https://api.github.com/repos/acme/widgets/issues/comments/${id}`,
        html_url: `https://github.com/acme/widgets/pull/${number}#issuecomment-${id}`,
        issue_url: `https://api.github.com/repos/acme/widgets/issues/${number}`,
        id,
        node_id: `IC_${id}`,
        user: { login: 'someone' },
        created_at: '2030-01-01T00:00:00Z',
        updated_at: updated,
        author_association: 'MEMBER',
        body,
        reactions: { total_count: 0 },
        performed_via_github_app: null,
        minimized: null,
    })

    // A watcher seeded with `first`, whose next poll serves `after` and `comments`.
    async function seeded(first, { argv = ['--once', '--glyphs'], glyphRule, comments = () => [] } = {}) {
        let pulls = first
        let served = []
        const gh = fakeGh({
            'repos/acme/widgets/pulls?': () => pulls,
            'repos/acme/widgets/issues/comments?': () => served,
        })
        const seedLines = await capture(() => main(['--repos', 'acme/widgets', ...argv], { exec: gh.exec, defaults: NONE, ...(glyphRule ? { glyphRule } : {}) }))
        return {
            gh,
            seedLines,
            next: async (after, nextComments = comments()) => {
                pulls = after
                served = nextComments
                return capture(() => pollAll())
            },
        }
    }

    function badRule(text) {
        const dir = mkdtempSync(join(tmpdir(), 'watch-glyphs-'))
        const path = join(dir, 'no-emoji-in-durable-records.md')
        writeFileSync(path, text)
        return { path, cleanup: () => rmSync(dir, { recursive: true, force: true }) }
    }

    it('prints one GLYPH line naming the code points for a dirty body, and none for a clean one', async () => {
        const { next } = await seeded([pull(1, { draft: true, body: dirtyBody })])
        const lines = await next([pull(1, { body: dirtyBody }), pull(2, { body: cleanBody })])

        assert.deepEqual(lines, [
            'READY  widgets#1  draft → ready  Title 1  https://github.com/acme/widgets/pull/1',
            'GLYPH  widgets#1  body carries a banned glyph  line 3: U+2705; line 5: U+26A0 U+FE0F',
            'READY  widgets#2  opened ready  Title 2  https://github.com/acme/widgets/pull/2',
        ])
    })

    it('scans a body on PUSH, and never a draft', async () => {
        const { next } = await seeded([pull(1, { body: dirtyBody }), pull(2, { draft: true, body: dirtyBody })])
        const lines = await next([pull(1, { body: dirtyBody, sha: 'f0e1d2c3b4'.repeat(4) }), pull(2, { draft: true, body: dirtyBody }), pull(3, { draft: true, body: dirtyBody })])

        assert.deepEqual(lines, [
            'PUSH  widgets#1  pushed f0e1d2c3b  Title 1  https://github.com/acme/widgets/pull/1',
            'GLYPH  widgets#1  body carries a banned glyph  line 3: U+2705; line 5: U+26A0 U+FE0F',
            'DRAFT  widgets#3  opened draft  Title 3  https://github.com/acme/widgets/pull/3',
        ])
    })

    it('scans the ready pull requests --emit-existing announces', async () => {
        const { seedLines } = await seeded([pull(1, { body: dirtyBody }), pull(2, { draft: true, body: dirtyBody })], { argv: ['--once', '--glyphs', '--emit-existing'] })
        assert.deepEqual(seedLines, [
            'READY  widgets#1  already open, ready  Title 1  https://github.com/acme/widgets/pull/1',
            'GLYPH  widgets#1  body carries a banned glyph  line 3: U+2705; line 5: U+26A0 U+FE0F',
            'DRAFT  widgets#2  already open, draft  Title 2  https://github.com/acme/widgets/pull/2',
        ])
    })

    it('prints one GLYPH line per dirty comment since the last poll, with its url, and does not repeat it', async () => {
        const { gh, next } = await seeded([pull(1)])
        const comments = [comment(11, 7, cleanBody), comment(12, 7, `ok\n${WARNING}`), comment(13, 9, `${CHECK} done`)]

        assert.deepEqual(await next([pull(1)], comments), [
            'GLYPH  widgets#7  comment carries a banned glyph  line 2: U+26A0 U+FE0F  https://github.com/acme/widgets/pull/7#issuecomment-12',
            'GLYPH  widgets#9  comment carries a banned glyph  line 1: U+2705  https://github.com/acme/widgets/pull/9#issuecomment-13',
        ])

        // The same comments served again across the inclusive `since` boundary
        // are not reported twice; an edit (a new updated_at) is scanned again.
        assert.deepEqual(await next([pull(1)], comments), [])
        assert.deepEqual(await next([pull(1)], [comment(13, 9, `${CHECK} still`, '2030-01-02T00:00:00Z')]), [
            'GLYPH  widgets#9  comment carries a banned glyph  line 1: U+2705  https://github.com/acme/widgets/pull/9#issuecomment-13',
        ])

        // Only GET reads of the comment collection, keyed on since=, walked by page.
        const commentCalls = gh.calls.map((args) => args[1]).filter((path) => path.includes('/issues/comments'))
        assert.equal(commentCalls.length, 3)
        assert.ok(commentCalls.every((path) => /\?since=[^&]+&per_page=100&page=1$/.test(path)), commentCalls.join('\n'))
    })

    it('seeds the comment watermark silently and does not read comments on the seed poll', async () => {
        const { gh, seedLines } = await seeded([pull(1)])
        assert.deepEqual(seedLines, [])
        assert.equal(gh.calls.some((args) => args[1].includes('/issues/comments')), false)
    })

    it('reads the comment number from issue_url, else html_url', () => {
        assert.equal(commentNumber({ issue_url: 'https://api.github.com/repos/a/b/issues/42' }), '42')
        assert.equal(commentNumber({ html_url: 'https://github.com/a/b/pull/8#issuecomment-1' }), '8')
        assert.equal(commentNumber({}), '?')
    })

    it('reports a failed control arm and prints no GLYPH verdicts, while the other events carry on', async () => {
        // An ASCII range in the class: the negative arm must fail.
        const rule = badRule("GLYPH='[\\x{20}-\\x{7E}]'\n")
        try {
            const { seedLines, next } = await seeded([pull(1, { draft: true, body: dirtyBody })], { glyphRule: rule.path })
            assert.equal(seedLines.length, 1)
            assert.match(seedLines[0], /^WATCH-ERROR  glyphs  scan disabled, no GLYPH lines will be printed  control arms FAILED, refusing to report: negative arm/)
            assert.equal(watchingEvent(60).subject, 'PRs on 1 repo, issues off, glyphs FAILED')

            const lines = await next([pull(1, { body: dirtyBody })], [comment(12, 7, WARNING)])
            assert.deepEqual(lines, ['READY  widgets#1  draft → ready  Title 1  https://github.com/acme/widgets/pull/1'])
        } finally {
            rule.cleanup()
        }
    })

    it('reports a rule it cannot read or parse the same way', async () => {
        for (const text of ['no assignment here\n', "GLYPH='[a-z]'\n", "GLYPH='[\\x{2600}]'\nGLYPH='[\\x{2700}]'\n"]) {
            const rule = badRule(text)
            try {
                const { seedLines } = await seeded([pull(1)], { glyphRule: rule.path })
                assert.equal(seedLines.length, 1, text)
                assert.match(seedLines[0], /^WATCH-ERROR  glyphs  scan disabled/, text)
            } finally {
                rule.cleanup()
            }
        }
        const { seedLines } = await seeded([pull(1)], { glyphRule: join(tmpdir(), 'no-such-dir-71', 'rule.md') })
        assert.match(seedLines[0], /^WATCH-ERROR  glyphs  scan disabled/)
    })

    it('with the scan off, prints exactly what it printed before and never reads comments', async () => {
        const { gh, seedLines, next } = await seeded([pull(1, { draft: true, body: dirtyBody }), pull(2, { body: dirtyBody })], { argv: ['--once'] })
        assert.deepEqual(seedLines, [])
        const lines = await next([pull(1, { body: dirtyBody }), pull(2, { body: dirtyBody, sha: 'f0e1d2c3b4'.repeat(4) }), pull(3, { body: dirtyBody })], [comment(12, 7, WARNING)])

        assert.deepEqual(lines, [
            'READY  widgets#1  draft → ready  Title 1  https://github.com/acme/widgets/pull/1',
            'PUSH  widgets#2  pushed f0e1d2c3b  Title 2  https://github.com/acme/widgets/pull/2',
            'READY  widgets#3  opened ready  Title 3  https://github.com/acme/widgets/pull/3',
        ])
        assert.equal(gh.calls.some((args) => args[1].includes('/issues/comments')), false)
        assert.equal(watchingEvent(60).subject, 'PRs on 1 repo, issues off')
    })

    it('names the scan in the WATCHING line only when it is on', async () => {
        await seeded([pull(1)])
        assert.equal(watchingEvent(60).subject, 'PRs on 1 repo, issues off, glyphs on')
    })

    it('takes --glyphs or watch.glyphs, and refuses --glyphs without the pull request feed', () => {
        assert.equal(parseArgs(['--repos', 'a/b'], NONE).glyphs, false)
        assert.equal(parseArgs(['--repos', 'a/b', '--glyphs'], NONE).glyphs, true)
        assert.equal(parseArgs(['--repos', 'a/b'], { ...NONE, glyphs: true }).glyphs, true)
        assert.throws(() => parseArgs(['--no-prs', '--issue-repos', 'a/b', '--glyphs'], NONE), /needs the pull request feed/)
        // Set in local-ci.json, an issues-only watcher has nothing to scan, which is not an error.
        assert.equal(parseArgs(['--no-prs', '--issue-repos', 'a/b'], { ...NONE, glyphs: true }).glyphs, false)
    })
})

describe('the source', () => {
    const source = readFileSync(join(here, 'watch-github.mjs'), 'utf8')

    it('names no repository: every owner/repo-looking literal is a placeholder', () => {
        const literals = [...source.matchAll(/\b([A-Za-z0-9_.-]+)\/([A-Za-z0-9_.-]+)\b/g)].map((match) => match[0])
        const allowed = /^(OWNER\/REPO|OWNER\/OTHER|owner\/repo|a\/b|node\/child_process)$/
        const offenders = literals.filter((literal) => /^(UAMS-Web|uams|wordpress|joshdaugherty)/i.test(literal) || /uams-|wordpress-importer|migration-api/i.test(literal))
        assert.deepEqual(offenders, [])
        // Control: the matcher sees a real slug when one is present.
        assert.ok(/uams-statamic/.test('uams-statamic') && /^(UAMS-Web|uams)/i.test('UAMS-Web/x'))
        assert.ok(literals.length > 0 && literals.some((literal) => allowed.test(literal)))
    })

    it('carries no U+2014 and uses the arrow U+2192 for flips', () => {
        assert.equal(source.includes(String.fromCharCode(0x2014)), false)
        assert.ok(source.includes('draft → ready'))
    })

    it('never mutates: no write verb appears in a gh call', () => {
        assert.equal(/-X\s+(POST|PUT|PATCH|DELETE)/.test(source), false)
        assert.equal(/'api',\s*`[^`]*`,\s*'-[fF]'/.test(source), false)
    })
})
