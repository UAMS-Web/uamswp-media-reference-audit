#!/usr/bin/env node
//
// check-handed-back-workflow.mjs: fail when a repository's copy of the handed-back
// workflow differs from the template this skill ships (UAMS-Web/uams-claude-skills#57).
//
// The sync delivers the template under .claude/ and .agents/ and does not create a copy
// under .github/, so each repository copies it once, through its own reviewed pull
// request, to .github/workflows/clear-handed-back.yml. The sync then rewrites an existing
// copy when the template changes (UAMS-Web/uams-claude-skills#431), but a hand edit to
// the copy, or a copy the sync could not push, still goes unnoticed. This check is what
// notices, and a repository's pre-merge check runs it.
//
// A copy that differs is also read for the one edit that does harm rather than merely
// drift: running on `edited` without the two conditions that keep a title-only edit and a
// bot's edit from clearing a finding nobody addressed. The message then names the missing
// condition, so the fix is not left to a line number.
//
// Line endings are compared as LF, so a Windows checkout with core.autocrlf does not fail
// a copy that is otherwise identical. Every other byte counts.
//
// EXIT CODES
//     0  the copy is identical to the template
//     1  the copy differs, or is missing; both paths are named
//     2  the check could not run: the template is unreadable, or a usage error
//
// USAGE
//     node .claude/skills/handed-back-label/scripts/check-handed-back-workflow.mjs [--root <dir>]
//
// The root defaults to the top of the git checkout the command runs in.

import { spawnSync } from 'node:child_process'
import { existsSync, readFileSync, realpathSync } from 'node:fs'
import { dirname, join, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'

export const WORKFLOW_PATH = '.github/workflows/clear-handed-back.yml'

// The template beside this script, in whichever copy of the skill runs it.
export const TEMPLATE_PATH = join(dirname(fileURLToPath(import.meta.url)), '..', 'templates', 'clear-handed-back.yml')

const asLf = (text) => text.replace(/\r\n/g, '\n')

/**
 * Name what a workflow that runs on `edited` is missing: the body-changed condition
 * (`github.event.changes.body`) and the actor condition (`github.event.sender.type`).
 * Returns an empty list for a workflow that does not run on `edited`, and for one that
 * carries both.
 */
export function editedProblems(source) {
    // Comments do not count: the template's own header names both conditions.
    const text = source.replace(/^\s*#.*$/gm, '')
    const trigger = text.match(/^\s*types:\s*\[([^\]]*)\]/m)
    const types = trigger ? trigger[1].split(',').map((type) => type.trim().replace(/^['"]|['"]$/g, '')) : []
    if (!types.includes('edited')) return []
    const problems = []
    if (!/github\.event\.changes\.body/.test(text)) {
        problems.push('it runs on `edited` without `github.event.changes.body`, so a title-only or base-branch edit would clear the label.')
    }
    if (!/github\.event\.sender\.type/.test(text)) {
        problems.push('it runs on `edited` without `github.event.sender.type`, so a bot that rewrites a description (a footer check, for one) would clear the label.')
    }
    return problems
}

/**
 * Compare the copy with the template. Returns `{ status, lines }`: the exit status and
 * the lines to print. Pure apart from the two reads, so a test can point it anywhere.
 */
export function checkWorkflow({ root, template = TEMPLATE_PATH }) {
    let expected
    try {
        expected = asLf(readFileSync(template, 'utf8'))
    } catch (failure) {
        return { status: 2, lines: [`handed-back workflow check cannot run: the template ${template} could not be read: ${failure.message}`] }
    }

    const copy = join(root, WORKFLOW_PATH)
    if (!existsSync(copy)) {
        return {
            status: 1,
            lines: [
                `${copy} is missing.`,
                `Copy the template to it: ${template}`,
            ],
        }
    }

    let actual
    try {
        actual = asLf(readFileSync(copy, 'utf8'))
    } catch (failure) {
        return { status: 1, lines: [`${copy} could not be read: ${failure.message}`] }
    }
    if (actual === expected) {
        return { status: 0, lines: [`${WORKFLOW_PATH} matches the template.`] }
    }

    const want = expected.split('\n')
    const have = actual.split('\n')
    let line = 0
    while (line < want.length && line < have.length && want[line] === have[line]) line += 1
    return {
        status: 1,
        lines: [
            `${copy} differs from the template ${template}, first at line ${line + 1}.`,
            ...editedProblems(actual).map((problem) => `Unsafe: ${problem}`),
            'Change the template in UAMS-Web/uams-claude-skills, under shared/, and copy it here; do not edit the copy alone.',
        ],
    }
}

function repoRoot(cwd) {
    const top = spawnSync('git', ['rev-parse', '--show-toplevel'], { cwd, encoding: 'utf8' })
    return top.status === 0 && top.stdout.trim() !== '' ? top.stdout.trim() : cwd
}

export function main(argv = process.argv.slice(2), { cwd = process.cwd(), log = console.log, error = console.error } = {}) {
    let root
    if (argv.length === 0) {
        root = repoRoot(cwd)
    } else if (argv.length === 2 && argv[0] === '--root' && argv[1] !== '') {
        root = resolve(cwd, argv[1])
    } else {
        error('usage: node check-handed-back-workflow.mjs [--root <dir>]')
        return 2
    }

    const { status, lines } = checkWorkflow({ root })
    lines.forEach((line) => (status === 0 ? log : error)(line))
    return status
}

function invokedDirectly() {
    if (!process.argv[1]) return false
    try {
        return realpathSync(resolve(process.argv[1])) === realpathSync(fileURLToPath(import.meta.url))
    } catch {
        return false
    }
}

if (invokedDirectly()) process.exitCode = main()
