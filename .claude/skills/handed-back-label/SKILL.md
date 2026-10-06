---
name: handed-back-label
description: >-
  The `handed-back` label a local CI gate puts on a pull request it sends back, and the
  GitHub Actions workflow that removes it when the pull request is pushed to. Ships the
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

A gate that finds a defect comments its verdict on the pull request and applies the `handed-back` label. The label says a finding is outstanding, so the queue counts `open AND not labelled`. A push to the pull request is it re-entering the queue, and that is the one moment the label comes off. A workflow does the removal, not the gate and not the author: a removal somebody has to remember gets missed, and a pull request still carrying the label drops out of the queue count for good.

What the gate does on a red verdict, and why it applies a label rather than flipping the pull request to draft, belongs to the `pre-merge-check` rule. This skill carries only the workflow and the check that keeps every copy of it the same.

## The workflow

`templates/clear-handed-back.yml` is the one shared copy:

- It runs on `pull_request` `synchronize`: the push, and only the push. Opening a pull request, applying the label and commenting cannot remove it.
- A job-level `if:` skips it unless the pull request carries `handed-back`, so a push to any other pull request starts no runner and spends no minutes.
- It removes that one label with a single `DELETE /repos/{o}/{r}/issues/{n}/labels/handed-back` under the job's own `GITHUB_TOKEN`, leaving every other label in place. A `404` means the label is already gone, which is success; any other status fails the job.
- It is not gated on `RUN_REMOTE_CI`, where a repository gates its other jobs on it. That switch is off for months at a time, and gating the removal on it brings the missed removal back for exactly that long.

The job runs under its own token, so a `.claude/settings.json` that denies `gh api -X DELETE` to the gate's sessions does not reach it.

## Copying it into a repository

The sync delivers this skill under `.claude/` and `.agents/` and never writes `.github/`, so a repository copies the workflow once, in its own reviewed pull request:

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
- **Exit 1**: the copy differs (the first differing line is named) or is missing. Both paths are printed.
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

**To change the workflow**, change the template in `UAMS-Web/uams-claude-skills`, under `shared/`, then copy it into each repository in that repository's own pull request. The check fails in each one until it has.
