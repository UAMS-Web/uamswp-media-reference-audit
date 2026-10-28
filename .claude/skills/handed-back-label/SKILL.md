---
name: handed-back-label
description: >-
  The `handed-back` label a local CI gate puts on a pull request it sends back, and the
  GitHub Actions workflow that removes it when the pull request is pushed to or its
  description is edited by a person. Ships the
  workflow as a template to copy to `.github/workflows/clear-handed-back.yml`, and a drift
  check that fails when a repository's copy differs from the template or is missing.
  Activate when adding the workflow to a repository, changing it, wiring the drift check
  into a pre-merge check, or working out why a pull request still carries `handed-back`
  after a push.
---
<!--
  Synced from UAMS-Web/uams-claude-skills: shared/.claude/skills/handed-back-label/SKILL.md
  Edit it there, not here; the next sync overwrites this copy.
  Delivered to this repository through the profile(s): local-ci.
-->
# The `handed-back` label

A gate that finds a defect comments its verdict on the pull request and applies the `handed-back` label. The label says a finding is outstanding, so the queue counts `open AND not labelled`. A push to the pull request is it re-entering the queue, and a person editing its description is the other moment the label comes off: a finding in the description is addressed by editing it. A workflow does the removal, not the gate and not the author: a removal somebody has to remember gets missed, and a pull request still carrying the label drops out of the queue count for good.

What the gate does on a red verdict, and why it applies a label rather than flipping the pull request to draft, belongs to the `pre-merge-check` rule. This skill carries only the workflow and the check that keeps every copy of it the same.

## The workflow

`templates/clear-handed-back.yml` is the one shared copy:

- It runs on `pull_request` `synchronize` (the push) and `edited` (a description change). Opening a pull request, applying the label and commenting cannot remove it.
- An `edited` event removes the label only when both hold: the body changed (`github.event.changes.body` is present, which a title-only or base-branch edit does not carry), and the actor is not a bot (`github.event.sender.type != 'Bot'`). A bot rewrites descriptions too, a footer check for one, and that must not clear a finding nobody addressed. A push clears the label whoever made it.
- **A description edit on a pull request handed back for its code clears the label too.** The workflow cannot tell which part the finding was about. The gate's re-check finds the head unchanged since the hand-back and hands it back again without re-running the jobs (`pre-merge-check`, *A finding in the description alone*), so the cost is one comment and a label that comes back.
- A job-level `if:` skips it unless the pull request carries `handed-back`, so a push to any other pull request starts no runner and spends no minutes.
- It removes that one label with a single `DELETE /repos/{o}/{r}/issues/{n}/labels/handed-back` under the job's own `GITHUB_TOKEN`, leaving every other label in place. A `200` or a `404` (the label is already gone) is success with no further call. Any other status is read against the label set instead of being taken as the verdict: the step lists the pull request's labels and passes when `handed-back` is absent (logging the status it saw), fails when the label is still present, and fails when the list cannot be read, since GitHub has answered `500` for a call whose write had landed.
- It is not gated on `RUN_REMOTE_CI`, where a repository gates its other jobs on it. That switch is off for months at a time, and gating the removal on it brings the missed removal back for exactly that long.

The job runs under its own token, so a `.claude/settings.json` that denies `gh api -X DELETE` to the gate's sessions does not reach it.

## Copying it into a repository

The sync delivers this skill under `.claude/` and `.agents/` and writes no workflow under `.github/`, so a repository copies the workflow once, in its own reviewed pull request:

```bash
mkdir -p .github/workflows
cp .claude/skills/handed-back-label/templates/clear-handed-back.yml .github/workflows/clear-handed-back.yml
```

Use exactly that path. A repository that already has the workflow under another name replaces that file rather than adding a second one, or two jobs race to remove the same label.

## The drift check

Nothing keeps the copy and the template together after that: a hand edit to the copy, or a template change the copy never received, goes unnoticed. The check notices:

```bash
node .claude/skills/handed-back-label/scripts/check-handed-back-workflow.mjs
```

- **Exit 0**: `.github/workflows/clear-handed-back.yml` is identical to the template.
- **Exit 1**: the copy differs (the first differing line is named) or is missing. Both paths are printed. A differing copy that runs on `edited` without the body condition or the actor condition also gets an `Unsafe:` line per missing condition, because that edit does harm rather than merely drifting. A copy that stops at `synchronize` is only different, and the first differing line says so.
- **Exit 2**: the check could not run: the template is unreadable, or the arguments are wrong.

Line endings are compared as LF, so a Windows checkout with `core.autocrlf` passes an otherwise identical copy. Every other byte counts. The repository root defaults to the top of the git checkout the command runs in; `--root <dir>` names another.

**Run it in the repository's pre-merge check**, so drift fails before merge rather than being found later. With the shared runner, a job in `local-ci.json`:

```json
{
    "name": "handed-back-workflow",
    "run": "node .claude/skills/handed-back-label/scripts/check-handed-back-workflow.mjs",
    "always": true
}
```

Give it no `requires`: the runner reports a job whose `requires` path is absent as skipped, with exit 0, and a missing check has to fail instead.

**To change the workflow**, change the template in `UAMS-Web/uams-claude-skills`, under `shared/`, and copy it into `.github/workflows/clear-handed-back.yml` in that same pull request (this repository carries the workflow too). Then copy it in each target repository on that repository's sync pull request branch, as a one-file commit of the template the sync pull request delivers:

```bash
cp .claude/skills/handed-back-label/templates/clear-handed-back.yml .github/workflows/clear-handed-back.yml
```

The copy belongs on the sync branch, not in a pull request of the repository's own. The sync pull request carries the new template and never the workflow, since the sync does not write `.github/`, so the check fails on it until the copy is added. A repository's own pull request fails the same check from the other side: it copies the new template into `.github/` while `.claude/skills/handed-back-label/templates/` still holds the old one, and neither pull request can merge first.

The sync rebuilds its branch and pushes it with `--force-with-lease`, so a later sync before the merge drops that commit. Redo the copy on the rebuilt branch, and run the check again before merging.
