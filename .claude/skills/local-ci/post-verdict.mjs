#!/usr/bin/env node
/**
 * Post the gate's verdict comment on a pull request and, on a hand-back, apply the
 * `handed-back` label in the same run (UAMS-Web/uams-statamic-archive#2845,
 * UAMS-Web/uams-claude-skills#120).
 *
 * The verdict comment and the label used to be two separate `gh api` calls, and the
 * second was the one that got missed: six of eight hand-backs read from their label
 * events carried no `handed-back` at all, which leaves queue depth counting work that
 * is waiting on its author. Folding the label into the command that posts the comment
 * removes the call that could be skipped, rather than asking for it to be remembered.
 *
 * WHAT A HAND-BACK IS. A red chain, or a finding a review caught on a green one. Both
 * pass `--hand-back`. A green verdict with no finding passes nothing and never touches
 * the labels.
 *
 * REMOVAL IS NOT HERE. The `handed-back-label` skill's workflow removes the label when
 * the pull request is pushed to. This script only ever adds it.
 *
 * READ BACK, NOT TRUSTED. After the writes, the pull request's labels are read again and
 * the run fails unless they agree with what was asked: `handed-back` present after a
 * hand-back. A comment that posted while the label did not is reported as exactly that,
 * so the gate knows the comment is on the record and only the label needs retrying.
 *
 * REST only, so a spent GraphQL quota does not stop a verdict being recorded.
 *
 * REPOSITORY. `--repo <owner/name>` names it; without the flag it is read from the `origin`
 * remote of the current directory, and the run fails naming `--repo` when that is not a
 * GitHub remote.
 *
 * Usage:  node .claude/skills/local-ci/post-verdict.mjs <pr-number> <body-file> [--hand-back] [--repo <owner/name>]
 * Output: one JSON object: `{"comment", "handedBack", "labels", "warning"}`. `warning` is null
 *         unless a green verdict found `handed-back` still on the pull request, which it
 *         also prints to stderr.
 * Exit:   0 = everything asked for is on the pull request, read back.
 *         1 = the arguments are wrong, a call failed, or the read-back disagrees.
 */
import { execFileSync } from 'node:child_process'
import { existsSync, readFileSync } from 'node:fs'
import { pathToFileURL } from 'node:url'

import { repoFromRemote } from './watch-github.mjs'

export const HANDED_BACK = 'handed-back'

/**
 * Read the command line.
 *
 * @param {Array<string>} argv The arguments after the script name.
 * @returns {{pr: number, bodyFile: string, handBack: boolean, repo: string|null}} `repo` is null when `--repo` was not given.
 */
export function parseArgs(argv) {
    const positional = []
    let handBack = false
    let repo = null

    for (let i = 0; i < argv.length; i++) {
        const arg = argv[i]

        if (arg === '--hand-back') {
            handBack = true
        } else if (arg === '--repo') {
            repo = argv[++i] ?? ''
        } else if (arg.startsWith('--repo=')) {
            repo = arg.slice('--repo='.length)
        } else if (arg.startsWith('--')) {
            throw new Error(`Unknown option: ${arg}`)
        } else {
            positional.push(arg)
        }
    }

    if (positional.length !== 2) {
        throw new Error('Usage: node .claude/skills/local-ci/post-verdict.mjs <pr-number> <body-file> [--hand-back] [--repo <owner/name>]')
    }
    if (!/^[1-9]\d*$/.test(positional[0])) {
        throw new Error(`Not a pull request number: ${positional[0]}`)
    }
    if (repo !== null && (!/^[A-Za-z0-9_.-]+\/[A-Za-z0-9_.-]+$/.test(repo) || repo.split('/').some((part) => /^\.+$/.test(part)))) {
        throw new Error(`Not an owner/name repository: ${repo}`)
    }
    // `gh` reads `@-` as standard input, so a body file named `-` would post whatever is piped in.
    if (positional[1] === '-') {
        throw new Error('The verdict body must be a file, not standard input')
    }

    return { pr: Number(positional[0]), bodyFile: positional[1], handBack, repo }
}

/**
 * The repository of the current directory's `origin` remote.
 *
 * @param {(command: string, args: Array<string>) => string} run Runs a command and returns its stdout; throws on failure.
 * @returns {string} `owner/name`.
 */
export function originRepo(run) {
    let url = ''
    try {
        url = run('git', ['remote', 'get-url', 'origin'])
    } catch {
        // Handled below with the rest of the cases that leave no repository.
    }
    const repo = repoFromRemote(url)

    if (repo === null) {
        throw new Error('Cannot tell the repository from the origin remote; pass --repo <owner/name>')
    }

    return repo
}

/**
 * Post the verdict, apply the label on a hand-back, and read the labels back.
 *
 * @param {{pr: number, bodyFile: string, handBack: boolean, repo: string}} options `repo` is resolved by the caller.
 * @param {(command: string, args: Array<string>) => string} run Runs a command and returns its stdout; throws on failure.
 * @returns {{comment: string, handedBack: boolean, labels: Array<string>, warning: string|null}}
 */
export function postVerdict({ pr, bodyFile, handBack, repo }, run) {
    if (!existsSync(bodyFile) || readFileSync(bodyFile, 'utf8').trim() === '') {
        throw new Error(`The verdict body is missing or empty: ${bodyFile}`)
    }

    const issue = `repos/${repo}/issues/${pr}`
    const comment = run('gh', ['api', '-X', 'POST', `${issue}/comments`, '-F', `body=@${bodyFile}`, '--jq', '.html_url']).trim()

    if (handBack) {
        try {
            run('gh', ['api', '-X', 'POST', `${issue}/labels`, '-f', `labels[]=${HANDED_BACK}`])
        } catch (error) {
            throw new Error(
                `The verdict comment was posted (${comment}), but applying ${HANDED_BACK} failed: ${error.message}\n`
                + `Retry the label alone: gh api -X POST ${issue}/labels -f 'labels[]=${HANDED_BACK}'`,
            )
        }
    }

    let labels
    try {
        labels = JSON.parse(run('gh', ['api', issue, '--jq', '[.labels[].name]']))
    } catch (error) {
        // The comment is on the record, so a blind retry of the whole command would post it twice.
        throw new Error(
            `The verdict comment was posted (${comment})${handBack ? ` and ${HANDED_BACK} was requested` : ''}, `
            + `but reading #${pr}'s labels back failed: ${error.message}\n`
            + `Check the labels by hand rather than re-running this command: gh api ${issue} --jq '[.labels[].name]'`,
        )
    }
    const handedBack = labels.includes(HANDED_BACK)

    if (handBack && !handedBack) {
        throw new Error(`The verdict comment was posted (${comment}), but ${HANDED_BACK} is not on #${pr} when read back.`)
    }

    // A green that finds the label still on means a push did not clear it (clear-handed-back.yml
    // did not run, or the push left the pull request conflicted). It is not this command's to
    // remove, but left unsaid the pull request stays out of queue depth.
    const warning = !handBack && handedBack
        ? `#${pr} still carries ${HANDED_BACK} after a green verdict: a push did not clear it. See the local-ci skill's recovery for a missing run of the clear-handed-back workflow.`
        : null

    return { comment, handedBack, labels, warning }
}

if (process.argv[1] && import.meta.url === pathToFileURL(process.argv[1]).href) {
    try {
        const run = (command, args) => execFileSync(command, args, { encoding: 'utf8', stdio: ['ignore', 'pipe', 'pipe'] })
        const options = parseArgs(process.argv.slice(2))
        const result = postVerdict({ ...options, repo: options.repo ?? originRepo(run) }, run)
        if (result.warning) {
            console.error(`post-verdict: warning: ${result.warning}`)
        }
        console.log(JSON.stringify(result))
    } catch (error) {
        console.error(`post-verdict: ${error.message}`)
        process.exit(1)
    }
}
