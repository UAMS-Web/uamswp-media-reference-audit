#!/usr/bin/env node
// watch-github.mjs: print one line per pull request (and, opt in, issue)
// lifecycle event, for a reviewing session to act on.
//
// A session arms this under the harness `Monitor` tool (`persistent: true`) and
// every stdout line becomes a notification. The script only reads: two kinds of
// `gh api` GET, no comment, no status, no merge, no draft flip. What to do with
// an event is the reading session's decision (see the pre-merge-check rule).
//
// EVENTS (one per line, two spaces between fields, newest state last)
//
//   READY        <repo>#<n>  opened ready     <title>  <url>
//   DRAFT        <repo>#<n>  opened draft     <title>  <url>
//   READY        <repo>#<n>  draft → ready    <title>  <url>
//   DRAFT        <repo>#<n>  ready → draft    <title>  <url>
//   PUSH         <repo>#<n>  pushed <sha>     <title>  <url>
//   GONE         <repo>#<n>  merged|closed    <title>  <url>
//   WATCH-ERROR  <repo>      <n> consecutive poll failures  <message>
//   WATCHING     <feeds>     every <n>s       <what was seeded>
//
// With `--issues` or `--issue-repos`, issue lifecycle is reported too, on kinds
// of their own so a consumer can route them without parsing the subject:
//
//   ISSUE        <repo>#<n>  opened                  <title>  [labels]  <url>
//   ISSUE-GONE   <repo>#<n>  completed|not planned   <title>  [labels]  <url>
//
// With `--glyphs` (or `watch.glyphs: true`), the pull request feed also enforces
// the no-emoji-in-durable-records rule on GitHub. Each body reported as READY or
// PUSH, and each comment posted or edited since the last poll, is scanned, and a
// banned glyph is reported by code point, never echoed:
//
//   GLYPH        <repo>#<n>  body carries a banned glyph     line <l>: U+XXXX ...
//   GLYPH        <repo>#<n>  comment carries a banned glyph  line <l>: U+XXXX ...  <url>
//
// The banned class is read from the synced rule (`.claude/rules/` beside this
// skill), and both control arms must hold before any GLYPH line is printed. When
// they cannot, a WATCH-ERROR names the failure and the rest of the watcher runs on.
//
// Issue watching is opt in, and pull request watching is opt out (`--no-prs`),
// so two consumers can each take one half of the stream. Running both halves in
// one watcher per repository would announce the same READY to two agents.
//
// REPOSITORIES come from, in order: `--repos`; the `watch.repos` list in
// `local-ci.json` at the repository root; the `origin` remote of the checkout
// the script runs in. No repository is named in this file. `--issue-repos` or
// `watch.issueRepos` scopes the issue feed separately.
//
// POLLING is REST only (`gh api`, never `gh pr list`, which is GraphQL and
// draws on the quota board work needs). The first poll seeds state silently, so
// arming the watcher mid-stream does not replay history; `--emit-existing`
// announces the open pull requests instead. Pull requests are tracked by
// diffing the full open set. Issues ride an `updated_at` watermark (`since=`)
// because a repository carries hundreds of them. Collections are walked with
// `?page=N`, never `--paginate`: `--paginate` follows a numeric-ID `Link` URL
// that a cloud session's proxy refuses, which truncates the read with an exit
// status a pipe discards.
//
// USAGE
//     node .claude/skills/local-ci/watch-github.mjs
//     node .claude/skills/local-ci/watch-github.mjs --interval 30 --emit-existing
//     node .claude/skills/local-ci/watch-github.mjs --repos OWNER/REPO,OWNER/OTHER
//     node .claude/skills/local-ci/watch-github.mjs --issues
//     node .claude/skills/local-ci/watch-github.mjs --no-prs --issue-repos OWNER/REPO
//     node .claude/skills/local-ci/watch-github.mjs --glyphs
//     node .claude/skills/local-ci/watch-github.mjs --once

import { execFile, spawnSync } from 'node:child_process'
import { existsSync, readFileSync } from 'node:fs'
import { join } from 'node:path'
import { pathToFileURL } from 'node:url'
import { promisify } from 'node:util'

import { createGlyphScanner, DEFAULT_RULE_PATH, glyphDetail } from '../writing-comments/scripts/glyphs.mjs'

const execFileAsync = promisify(execFile)

const CONFIG_FILE = 'local-ci.json'

// Consecutive per-repository poll failures tolerated before one is surfaced.
const FAILURE_STREAK_BEFORE_ALERT = 3

// GitHub's maximum REST page size. A page shorter than this is the last one.
const PAGE_SIZE = 100

// Sanity ceiling on the page walk. The stop condition trusts the server to
// advance on `page=N`; a proxy that ignores the parameter and re-serves page 1
// would otherwise loop forever without throwing, so no WATCH-ERROR would ever
// be emitted.
const MAX_PAGES = 100

const HELP = `watch-github.mjs: print one line per pull request (and, with --issues, issue) lifecycle event.

  --interval <seconds>   Poll interval (default 60, floor 10).
  --repos <a,b,c>        Comma-separated owner/repo list. Default: watch.repos in local-ci.json,
                         else the origin remote of this checkout.
  --emit-existing        Announce already-open pull requests on the first poll instead of seeding
                         silently. Pull requests only: the open-issue set is too large to replay.
  --issues               Also emit ISSUE and ISSUE-GONE events (opened, closed) per repository.
  --issue-repos <a,b,c>  Watch issues on this list instead of the pull request list (implies
                         --issues). Default: watch.issueRepos in local-ci.json.
  --no-prs               Stop watching pull requests, for an issues-only watcher that never touches
                         the pulls endpoint. Requires --issues or --issue-repos.
  --glyphs               Also print GLYPH lines for pull request bodies and new comments that carry a
                         glyph the no-emoji-in-durable-records rule bans. Default: watch.glyphs in
                         local-ci.json, else off.
  --once                 Poll a single time and exit (queue seeding, smoke testing).
  --gh <path>            Path to the gh binary (default: gh, or $LOCAL_CI_GH).
  -h, --help             Show this help.
`

// The invoker every fetch goes through. main() swaps in whatever it is handed,
// so a test can serve recorded API responses without spawning `gh`.
let execGh = execFileAsync

const splitList = (value) => value.split(',').map((repo) => repo.trim()).filter(Boolean)

const REPO_SLUG = /^[A-Za-z0-9_.-]+\/[A-Za-z0-9_.-]+$/

// `owner/repo` from a git remote URL (https, ssh, or scp-like), or null.
export function repoFromRemote(url) {
    // Anchored on the left: a host that merely ends in github.com, or a local path
    // containing `github.com/`, is not GitHub.
    const match = /^(?:(?:https?|ssh|git):\/\/(?:[^@/\s]+@)?|[^@/:\s]+@)github\.com[:/]([A-Za-z0-9_.-]+)\/([A-Za-z0-9_.-]+?)(?:\.git)?\/?$/.exec(String(url).trim())
    return match ? `${match[1]}/${match[2]}` : null
}

const WATCH_KEYS = ['repos', 'issueRepos', 'glyphs']

// The `watch` section of a parsed local-ci.json: `{ repos, issueRepos, glyphs }`,
// two lists (possibly empty) and a boolean. Throws on a shape it could only guess about,
// and on a key it does not define: a misspelled `issueRepo` or `glyph` would otherwise
// parse as the default and the watcher would run with less than the config asked for.
// An underscore key is refused here too, as in every nested object of the config.
export function watchConfig(raw) {
    const section = raw && typeof raw === 'object' ? raw.watch : undefined
    if (section === undefined) {
        return { repos: [], issueRepos: [], glyphs: false }
    }
    if (!section || typeof section !== 'object' || Array.isArray(section)) {
        throw new Error(`"watch" in ${CONFIG_FILE} must be an object such as {"repos": ["owner/repo"]}.`)
    }
    const unknown = Object.keys(section).filter((key) => !WATCH_KEYS.includes(key))
    if (unknown.length > 0) {
        throw new Error(`"watch" has no ${unknown.map((key) => JSON.stringify(key)).join(', ')}; it takes ${WATCH_KEYS.map((key) => `"${key}"`).join(', ')}.`)
    }
    const list = (value, what) => {
        if (value === undefined) {
            return []
        }
        if (!Array.isArray(value) || value.some((entry) => typeof entry !== 'string' || !REPO_SLUG.test(entry))) {
            throw new Error(`"watch.${what}" must be an array of "owner/repo" strings.`)
        }
        return value
    }
    if (section.glyphs !== undefined && typeof section.glyphs !== 'boolean') {
        throw new Error('"watch.glyphs" must be true or false.')
    }
    return { repos: list(section.repos, 'repos'), issueRepos: list(section.issueRepos, 'issueRepos'), glyphs: section.glyphs ?? false }
}

// Where the defaults come from: the checkout the script runs in. Pure inputs
// are injected by tests; the real lookup is `defaultsFromCheckout`.
export function defaultsFromCheckout(cwd = process.cwd()) {
    const top = spawnSync('git', ['rev-parse', '--show-toplevel'], { cwd, encoding: 'utf8' })
    const root = top.status === 0 && top.stdout.trim() !== '' ? top.stdout.trim() : cwd

    let config = { repos: [], issueRepos: [], glyphs: false }
    const file = join(root, CONFIG_FILE)
    if (existsSync(file)) {
        try {
            config = watchConfig(JSON.parse(readFileSync(file, 'utf8')))
        } catch (error) {
            throw new Error(`${CONFIG_FILE}: ${error.message}`)
        }
    }

    const remote = spawnSync('git', ['remote', 'get-url', 'origin'], { cwd: root, encoding: 'utf8' })
    const origin = remote.status === 0 ? repoFromRemote(remote.stdout) : null

    return { ...config, origin }
}

// Parse `--flag value` / `--flag=value` / bare-boolean arguments, and resolve
// the two feeds into the repository lists each will poll. `defaults` is
// `{ repos, issueRepos, origin, glyphs }` from the checkout; tests pass their own.
export function parseArgs(argv, defaults = { repos: [], issueRepos: [], origin: null }) {
    const options = {
        interval: 60,
        repos: null,
        emitExisting: false,
        prs: true,
        issues: false,
        issueRepos: null,
        once: false,
        glyphs: defaults.glyphs === true,
        gh: process.env.LOCAL_CI_GH ?? 'gh',
    }

    for (let index = 0; index < argv.length; index += 1) {
        const [flag, inlineValue] = argv[index].split(/=(.*)/s)
        const nextValue = () => inlineValue ?? argv[++index] ?? ''

        switch (flag) {
            case '--interval':
                options.interval = Math.max(10, Number(nextValue()))
                break
            case '--repos':
                options.repos = splitList(nextValue())
                break
            case '--emit-existing':
                options.emitExisting = true
                break
            case '--no-prs':
                options.prs = false
                break
            case '--issues':
                options.issues = true
                break
            case '--issue-repos':
                options.issueRepos = splitList(nextValue())
                options.issues = true
                break
            case '--once':
                options.once = true
                break
            case '--glyphs':
                options.glyphs = true
                options.glyphsFlag = true
                break
            case '--gh':
                options.gh = nextValue()
                break
            case '--help':
            case '-h':
                options.help = true
                break
            default:
                throw new Error(`Unknown flag: ${flag}`)
        }
    }

    if (!Number.isFinite(options.interval)) {
        throw new Error('--interval must be a number of seconds.')
    }

    // An explicit flag wins, even an empty one (which then fails the guard below
    // rather than silently falling back to a different repository).
    const configured = defaults.repos.length > 0 ? defaults.repos : (defaults.origin ? [defaults.origin] : [])
    options.repos = options.repos ?? configured
    const issueDefault = defaults.issueRepos.length > 0 ? defaults.issueRepos : options.repos
    options.prRepos = options.prs ? options.repos : []
    options.issueRepos = options.issues ? (options.issueRepos ?? issueDefault) : []

    // The scan rides the pull request feed. Asked for on the command line without
    // it, that is a mistake to report; set in local-ci.json, an issues-only
    // watcher simply has nothing to scan.
    if (options.glyphsFlag && !options.prs) {
        throw new Error('--glyphs scans pull request bodies and comments, so it needs the pull request feed: drop --no-prs.')
    }
    delete options.glyphsFlag
    options.glyphs = options.glyphs && options.prRepos.length > 0

    // Both feeds empty is not a quiet watcher, it is a silent one.
    if (options.prRepos.length === 0 && options.issueRepos.length === 0) {
        throw new Error(options.prs
            ? 'Nothing to watch: pass --repos, set watch.repos in local-ci.json, or run inside a checkout with a GitHub origin remote.'
            : '--no-prs leaves nothing to watch: add --issues or --issue-repos.')
    }

    return options
}

// Fetch every page of a REST collection, walking `?page=N` explicitly. Pages
// are parsed unfiltered: a filter that drops items (the issues feed discards
// pull requests) makes a full page look short and would end the walk early.
async function fetchAllPages(gh, path) {
    const separator = path.includes('?') ? '&' : '?'
    const items = []

    for (let page = 1; page <= MAX_PAGES; page += 1) {
        const { stdout } = await execGh(gh, ['api', `${path}${separator}per_page=${PAGE_SIZE}&page=${page}`], { maxBuffer: 16 * 1024 * 1024 })

        const body = stdout.trim()
        const parsed = body === '' ? [] : JSON.parse(body)

        // An object here is an error payload that still exited 0, not a page.
        if (!Array.isArray(parsed)) {
            throw new Error(`Expected an array from ${path} page ${page}, got ${typeof parsed}.`)
        }

        items.push(...parsed)

        if (parsed.length < PAGE_SIZE) {
            return items
        }
    }

    // Throwing, rather than returning what was read, keeps a truncated result
    // from passing for a complete one.
    throw new Error(`Pagination exceeded ${MAX_PAGES} pages for ${path}; refusing to loop.`)
}

// Whether an open pull request's head moved between two polls. False when
// either side has no SHA (a first poll after an upgrade would otherwise announce
// every open pull request), and false while either side is a draft: a draft is
// not in the queue, and a push that coincides with a flip is reported as the
// flip, which is the larger fact.
export function isPush(before, after) {
    if (!before?.sha || !after?.sha) {
        return false
    }
    if (before.draft || after.draft) {
        return false
    }
    return before.sha !== after.sha
}

async function fetchOpenPulls(gh, repo) {
    const pulls = await fetchAllPages(gh, `repos/${repo}/pulls?state=open`)

    return pulls.map((pull) => ({
        number: pull.number,
        title: pull.title,
        draft: pull.draft,
        url: pull.html_url,
        // The head SHA is what makes a push visible: without it the projection is
        // identical before and after an author pushes a fix.
        sha: pull.head?.sha ?? null,
        // The list endpoint carries the full body, so the glyph scan costs no
        // extra request.
        body: pull.body ?? '',
    }))
}

// Issues for one repository updated at or after `since`. The REST issues
// endpoint returns pull requests as issues, so anything carrying a
// `pull_request` key is discarded, or every pull request event would double-fire.
async function fetchRecentIssues(gh, repo, since) {
    const issues = await fetchAllPages(gh, `repos/${repo}/issues?state=all&sort=updated&direction=desc&since=${since}`)

    return issues
        .filter((issue) => !Object.hasOwn(issue, 'pull_request'))
        .map((issue) => ({
            number: issue.number,
            title: issue.title,
            state: issue.state,
            createdAt: issue.created_at,
            closedAt: issue.closed_at,
            stateReason: issue.state_reason,
            labels: issue.labels.map((label) => label.name),
            url: issue.html_url,
        }))
}

// What happened to a pull request that left the open set: merged, closed, or
// (a gap in the listing rather than a state change) still open.
async function resolveClosedState(gh, repo, number) {
    try {
        const { stdout } = await execGh(gh, [
            'api',
            `repos/${repo}/pulls/${number}`,
            '--jq',
            'if .merged then "merged" elif .state == "open" then "still open" else "closed" end',
        ])

        return stdout.trim() || 'closed'
    } catch {
        // A pull request that vanished did something; the weaker "closed" beats
        // dropping the event because one follow-up request failed.
        return 'closed'
    }
}

// Every stdout line becomes a notification, so each event stays on one line.
function emit(kind, subject, transition, detail) {
    console.log(`${kind}  ${subject}  ${transition}  ${detail}`)
}

const shortRepo = (repo) => repo.split('/').pop()

// Classify one issue into the lifecycle event it represents relative to a
// watermark, or null when it merely changed (a label edit, a comment). An issue
// opened and closed inside one interval reports as closed only.
export function classifyIssue(issue, since) {
    if (issue.state === 'closed' && issue.closedAt && issue.closedAt >= since) {
        return { kind: 'ISSUE-GONE', transition: issue.stateReason === 'not_planned' ? 'not planned' : 'completed' }
    }
    if (issue.createdAt >= since) {
        return { kind: 'ISSUE', transition: 'opened' }
    }
    return null
}

// Module state, populated by main(), so importing this file (the tests do) never
// parses argv or starts polling.
let options
let state
let failureStreaks
let prRepos = []
let issueRepos = []
let issueWatermarks
let issueFailureStreaks
let issueAnnounced
let glyphScanner = null
let glyphStatus = 'off'
let commentWatermarks
let commentFailureStreaks
let commentsAnnounced
let stopping = false

const errorMessage = (error) => (error.stderr || error.message || '').split('\n')[0].slice(0, 200)

// A GLYPH line for a pull request body, when the scan is on and the body carries
// one. Called beside each READY and PUSH: the body is already in hand from the
// listing, so it costs no request. A draft is not scanned until it turns ready.
function emitBodyGlyphs(subject, pull) {
    if (glyphScanner === null) {
        return
    }
    const detail = glyphDetail(glyphScanner, pull.body)
    if (detail !== null) {
        emit('GLYPH', subject, 'body carries a banned glyph', detail)
    }
}

// The issue or pull request number a comment belongs to, from its `issue_url`
// (`.../issues/<n>`, for comments on either), else its `html_url`.
export function commentNumber(comment) {
    const match = /\/issues\/(\d+)$/.exec(comment.issue_url ?? '') ?? /\/(?:issues|pull)\/(\d+)/.exec(comment.html_url ?? '')
    return match ? match[1] : '?'
}

// Scan the comments on one repository's issues and pull requests that were
// posted or edited since the last poll. One collection covers both, keyed on
// `since=`, so the cost does not grow with the repository. The first poll only
// sets the watermark: a comment from before the watcher started is not reported.
async function sweepComments(repo) {
    const previousWatermark = commentWatermarks.get(repo)
    const nextWatermark = new Date().toISOString()

    if (previousWatermark === null) {
        commentWatermarks.set(repo, nextWatermark)
        return
    }

    let comments
    try {
        comments = await fetchAllPages(options.gh, `repos/${repo}/issues/comments?since=${encodeURIComponent(previousWatermark)}`)
    } catch (error) {
        const streak = commentFailureStreaks.get(repo) + 1
        commentFailureStreaks.set(repo, streak)

        // Hold the watermark on failure: advancing it would skip the failed window.
        if (streak === FAILURE_STREAK_BEFORE_ALERT || streak % 30 === 0) {
            emit('WATCH-ERROR', shortRepo(repo), `${streak} consecutive comment-poll failures`, errorMessage(error))
        }
        return
    }

    commentFailureStreaks.set(repo, 0)

    // `since` is inclusive, so a comment updated exactly on the mark comes back on
    // the next poll. Remembering one poll's keys is enough to keep it from firing
    // twice, and a later edit (a new `updated_at`) is scanned again.
    const before = commentsAnnounced.get(repo)
    const seen = new Set()

    for (const comment of comments) {
        const key = `${comment.id}@${comment.updated_at}`
        seen.add(key)
        if (before.has(key)) {
            continue
        }

        const detail = glyphDetail(glyphScanner, comment.body)
        if (detail !== null) {
            emit('GLYPH', `${shortRepo(repo)}#${commentNumber(comment)}`, 'comment carries a banned glyph', `${detail}  ${comment.html_url ?? ''}`)
        }
    }

    commentsAnnounced.set(repo, seen)
    commentWatermarks.set(repo, nextWatermark)
}

// Poll one repository's pull requests and emit the events since the last poll.
// A repository whose poll fails keeps its previous state, so a transient error
// never manufactures phantom events on the next successful poll.
async function pollRepo(repo) {
    let pulls

    try {
        pulls = await fetchOpenPulls(options.gh, repo)
    } catch (error) {
        const streak = failureStreaks.get(repo) + 1
        failureStreaks.set(repo, streak)

        // A repository that fails its very first poll never seeded at all: that
        // is a setup error (auth, typo, access), so alert at once.
        const neverSeeded = state.get(repo) === null
        if (neverSeeded || streak === FAILURE_STREAK_BEFORE_ALERT || streak % 30 === 0) {
            emit('WATCH-ERROR', shortRepo(repo), `${streak} consecutive poll failures`, errorMessage(error))
        }
        return
    }

    failureStreaks.set(repo, 0)

    const previous = state.get(repo)
    // Every event below reads this map, not the fetch projection, so the body has
    // to be carried here: dropped, the glyph scan would read undefined and report
    // every pull request clean.
    const current = new Map(pulls.map((pull) => [pull.number, { draft: pull.draft, title: pull.title, url: pull.url, sha: pull.sha, body: pull.body }]))

    // First successful poll: seed without replaying history unless asked to.
    if (previous === null) {
        state.set(repo, current)

        if (options.emitExisting) {
            for (const [number, pull] of current) {
                emit(pull.draft ? 'DRAFT' : 'READY', `${shortRepo(repo)}#${number}`, pull.draft ? 'already open, draft' : 'already open, ready', `${pull.title}  ${pull.url}`)
                if (!pull.draft) {
                    emitBodyGlyphs(`${shortRepo(repo)}#${number}`, pull)
                }
            }
        }
        return
    }

    for (const [number, pull] of current) {
        const before = previous.get(number)
        const subject = `${shortRepo(repo)}#${number}`
        const detail = `${pull.title}  ${pull.url}`

        if (before === undefined) {
            emit(pull.draft ? 'DRAFT' : 'READY', subject, pull.draft ? 'opened draft' : 'opened ready', detail)
            if (!pull.draft) {
                emitBodyGlyphs(subject, pull)
            }
        } else if (before.draft && !pull.draft) {
            emit('READY', subject, 'draft → ready', detail)
            emitBodyGlyphs(subject, pull)
        } else if (!before.draft && pull.draft) {
            emit('DRAFT', subject, 'ready → draft', detail)
        } else if (isPush(before, pull)) {
            emit('PUSH', subject, `pushed ${pull.sha.slice(0, 9)}`, detail)
            // The first poll seeds silently, so for a pull request already open
            // when the watcher started, a push is the only scan its body gets.
            emitBodyGlyphs(subject, pull)
        }
    }

    for (const [number, pull] of previous) {
        if (current.has(number)) {
            continue
        }

        const disposition = await resolveClosedState(options.gh, repo, number)

        // A still-open pull request that left the listing is a gap in it (a page
        // served short, a poll that raced a reopen), not a state change. GONE
        // would drop live work; re-announcing it when the listing recovers would
        // enqueue it twice. Carry it forward and say nothing.
        if (disposition === 'still open') {
            current.set(number, pull)
            continue
        }

        emit('GONE', `${shortRepo(repo)}#${number}`, disposition, `${pull.title}  ${pull.url}`)
    }

    state.set(repo, current)
}

// Poll one repository's issues and emit opened/closed events since the last
// poll. The first poll only sets the watermark.
async function pollIssues(repo) {
    const previousWatermark = issueWatermarks.get(repo)

    // Take the mark before the request, so anything updated during it is caught
    // next round instead of falling into the gap.
    const nextWatermark = new Date().toISOString()

    if (previousWatermark === null) {
        issueWatermarks.set(repo, nextWatermark)
        return
    }

    let issues

    try {
        issues = await fetchRecentIssues(options.gh, repo, previousWatermark)
    } catch (error) {
        const streak = issueFailureStreaks.get(repo) + 1
        issueFailureStreaks.set(repo, streak)

        // Hold the watermark on failure: advancing it would skip the failed window.
        if (streak === FAILURE_STREAK_BEFORE_ALERT || streak % 30 === 0) {
            emit('WATCH-ERROR', shortRepo(repo), `${streak} consecutive issue-poll failures`, errorMessage(error))
        }
        return
    }

    issueFailureStreaks.set(repo, 0)

    // GitHub's `since` is inclusive, so an issue updated exactly on the mark comes
    // back on the next poll; this keeps it from firing twice.
    const announced = issueAnnounced.get(repo)

    for (const issue of issues) {
        const event = classifyIssue(issue, previousWatermark)
        if (event === null) {
            continue
        }

        const seenKey = `${issue.number}:${event.kind}`
        if (announced.has(seenKey)) {
            continue
        }
        announced.add(seenKey)

        const labels = issue.labels.length ? `  [${issue.labels.join(',')}]` : ''
        emit(event.kind, `${shortRepo(repo)}#${issue.number}`, event.transition, `${issue.title}${labels}  ${issue.url}`)
    }

    issueWatermarks.set(repo, nextWatermark)
}

// Poll every live feed once. The seed pass and each loop iteration both run
// exactly this, so a feed switched off at startup cannot leak back in.
export async function pollAll() {
    for (const repo of prRepos) {
        await pollRepo(repo)
        if (glyphScanner !== null) {
            await sweepComments(repo)
        }
    }
    for (const repo of issueRepos) {
        await pollIssues(repo)
    }
}

const repoCount = (count) => `${count} repo${count === 1 ? '' : 's'}`

// The startup line. Both feeds are named, the off one included: a watcher whose
// feed is off is otherwise indistinguishable from one whose repositories are quiet.
export function watchingEvent(interval) {
    const feeds = [
        prRepos.length ? `PRs on ${repoCount(prRepos.length)}` : 'PRs off',
        issueRepos.length ? `issues on ${repoCount(issueRepos.length)}` : 'issues off',
    ]
    // Named only when asked for, so a watcher without --glyphs prints what it
    // always has. A scan that could not start says so here as well.
    if (glyphStatus !== 'off') {
        feeds.push(`glyphs ${glyphStatus}`)
    }

    const detail = []
    if (prRepos.length) {
        detail.push(`open PRs at start: ${prRepos.map((repo) => `${shortRepo(repo)}:${state.get(repo)?.size ?? '?'}`).join(', ')}`)
    }
    if (issueRepos.length) {
        detail.push(`issues on ${issueRepos.map(shortRepo).join(', ')}`)
    }

    return { subject: feeds.join(', '), transition: `every ${interval}s`, detail: detail.join('; ') }
}

// Parse argv, seed every watched feed, then poll until stopped. `dependencies`
// overrides the `gh` invoker and the checkout defaults, for tests.
export async function main(argv, dependencies = {}) {
    try {
        options = parseArgs(argv, dependencies.defaults ?? defaultsFromCheckout())
        if (options.help) {
            process.stdout.write(HELP)
            return
        }
    } catch (error) {
        process.stderr.write(`${error.message}\n\n${HELP}`)
        process.exit(2)
    }

    execGh = dependencies.exec ?? execFileAsync

    prRepos = options.prRepos
    state = new Map(prRepos.map((repo) => [repo, null]))
    failureStreaks = new Map(prRepos.map((repo) => [repo, 0]))

    issueRepos = options.issueRepos
    issueWatermarks = new Map(issueRepos.map((repo) => [repo, null]))
    issueFailureStreaks = new Map(issueRepos.map((repo) => [repo, 0]))
    issueAnnounced = new Map(issueRepos.map((repo) => [repo, new Set()]))

    commentWatermarks = new Map(prRepos.map((repo) => [repo, null]))
    commentFailureStreaks = new Map(prRepos.map((repo) => [repo, 0]))
    commentsAnnounced = new Map(prRepos.map((repo) => [repo, new Set()]))

    // The scanner is built, and its control arms asserted, before the first poll.
    // A scanner that cannot be built prints no verdicts at all: a WATCH-ERROR names
    // why, and the other events carry on.
    glyphScanner = null
    glyphStatus = 'off'
    if (options.glyphs && prRepos.length > 0) {
        try {
            glyphScanner = createGlyphScanner(dependencies.glyphRule ?? DEFAULT_RULE_PATH)
            glyphStatus = 'on'
        } catch (error) {
            glyphStatus = 'FAILED'
            emit('WATCH-ERROR', 'glyphs', 'scan disabled, no GLYPH lines will be printed', errorMessage(error))
        }
    }

    await pollAll()

    if (options.once) {
        return
    }

    const watching = watchingEvent(options.interval)
    emit('WATCHING', watching.subject, watching.transition, watching.detail)

    while (!stopping) {
        await new Promise((resolve) => setTimeout(resolve, options.interval * 1000))
        await pollAll()
    }
}

export { HELP }

// Run only as the entry script, so importing this file for the exports above
// does not parse argv or start polling.
if (process.argv[1] && import.meta.url === pathToFileURL(process.argv[1]).href) {
    for (const signal of ['SIGINT', 'SIGTERM']) {
        process.on(signal, () => {
            stopping = true
            process.exit(0)
        })
    }

    await main(process.argv.slice(2))
}
