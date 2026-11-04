<!--
  Synced from UAMS-Web/uams-claude-skills: shared/.claude/skills/local-ci/templates/gate-workflow.md
  Edit it there, not here; the next sync overwrites this copy.
  Delivered to this repository through the profile(s): local-ci.
-->
# Gating an Actions workflow behind `RUN_REMOTE_CI`

A private repository's checks run through the local CI runner, not in GitHub Actions. The existing workflow is not deleted: every job in it is gated on a repository or organization variable, so the workflow stays in the repository, keeps evaluating on every push and pull request, and dispatches nothing to a runner while the variable says so. Turning it back on is a variable change, not a revert.

This is the shape `uams-statamic`, `aether-wordpress-importer` and `aether-wordpress-migration-api` already use on their `CI` workflows.

## The gate

Add one line to every job:

```yaml
jobs:
  php-lint:
    runs-on: ubuntu-latest
    # Budget kill-switch: see the RUN_REMOTE_CI note in the header.
    if: vars.RUN_REMOTE_CI != 'false'
    steps:
      - uses: actions/checkout@v4
      # ...
```

- `vars.RUN_REMOTE_CI != 'false'` is true when the variable is unset or holds anything but the string `false`, so a repository that never sets it runs as before. The default is always-on.
- `vars` cannot gate the `on:` triggers, only `if:`. The workflow still evaluates on each event, but a skipped job is never dispatched to a runner and bills no minutes. It appears on the pull request as `skipped`, and GitHub reports its status as `Success` (see Branch protection).
- Gate every job, including ones that `needs:` another. A job whose dependency was skipped is skipped too, but an explicit gate on each job keeps the workflow readable and survives a job being reordered.

## The header comment

Put a comment at the top of the workflow so the next reader does not take `skipped` for a failure or delete the workflow as dead:

```yaml
# RUN_REMOTE_CI: budget kill-switch.
# GitHub Actions minutes bill at the organization level, one pool shared by
# every UAMS-Web repository, and only private repositories consume them. This
# repository is private. Every job below is gated on
# `if: vars.RUN_REMOTE_CI != 'false'`: with the variable set to `false` the jobs
# report `skipped` and consume no runner minutes; unset, or any other value,
# runs them as before.
#
# While the switch is off, the local CI runner (.claude/skills/local-ci/) is
# the whole gate, not a fallback. Its verdict is posted on the pull request.
```

## Where the variable lives

`Settings -> Secrets and variables -> Actions -> Variables`, in the organization or the repository.

- An organization variable scoped to private repositories flips every gated repository at once and covers new private repositories automatically. That is where the control belongs; a repository inherits it by carrying the gate, and nothing has to be set in the repository.
- A repository variable overrides the organization value for that repository alone. Use one to test the switch, and delete it afterwards.

## Branch protection

A job skipped by an `if:` condition reports its status as `Success`, and it does not prevent a pull request from merging even when it is a required check ([Using conditions to control job execution](https://docs.github.com/en/actions/using-jobs/using-conditions-to-control-job-execution)). So a required status check from this workflow turns green while the switch is off and nothing has run: the requirement is satisfied by a job that did not check anything. While the switch is off, the local runner's verdict comment on the pull request is the only record of what was validated, and a green required check says nothing about it.

Check the branch's protection before gating. If it requires status checks from this workflow, remove those requirements in the same change, so that nobody reads a green check as a passing run; or keep them deliberately, knowing they enforce nothing until the switch is on again. A check stays `Pending` and blocks merging only when the whole workflow is skipped by a path, branch or commit-message filter ([Troubleshooting required status checks](https://docs.github.com/en/pull-requests/collaborating-with-pull-requests/collaborating-on-repositories-with-code-quality-features/troubleshooting-required-status-checks)), which this gate does not use. Protection that only refuses force-pushes and deletions is unaffected.

## Steps

1. Adopt the local runner first: `local-ci.json` with the jobs the workflow ran, `.githooks/pre-commit` where the workflow carried a secret or personal-data guard, and a green run. The repository is never without a gate.
2. Add `if: vars.RUN_REMOTE_CI != 'false'` to every job and the header comment above. Keep the jobs' steps as they are.
3. Check the branch protection rules for required status checks from this workflow (see above): while the switch is off they report `Success` without running. List them before changing anything:

   ```bash
   # Classic branch protection on main (404 if protection is off or you lack admin)
   gh api "repos/{owner}/{repo}/branches/main/protection/required_status_checks" \
     --jq '{strict, contexts, checks}'

   # Repository rulesets (name and id); then open each id that may require checks
   gh api "repos/{owner}/{repo}/rulesets" --jq '.[] | {id, name, enforcement, target}'
   gh api "repos/{owner}/{repo}/rulesets/<id>" \
     --jq '.rules[] | select(.type == "required_status_checks")'
   ```

   Replace `{owner}/{repo}` with the repository (or `gh repo view --json nameWithOwner -q .nameWithOwner`) and `main` with the protected branch. For organization rulesets, also check `gh api orgs/{org}/rulesets`.
4. Confirm the organization variable `RUN_REMOTE_CI` is `false` for private repositories, or set it on the repository.
5. Push the change. The workflow run for that push shows every job `skipped` and the run's billable time is zero. A pull request from then on shows the Actions jobs as `skipped` and the local runner's verdict as a comment.
6. Update `CONTRIBUTING.md` or the equivalent: "let CI pass" becomes "run the local CI runner and let its verdict pass", and the hook setup step is documented.

## Turning it back on

Delete the variable, or set it to anything but `false`. Nothing in the workflow changes.
