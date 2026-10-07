#!/usr/bin/env bash
# FLE-8: live systemd security check, run by the `systemd-security-live` custom
# pipeline (bitbucket-pipelines.yml) on a weekly Bitbucket schedule.
#
# SECURITY_PROBE_TARGETS: space-separated `name=user@host` entries, e.g.
#   ddev3=fleet-probe@ddev3.fleet.pm
# Each host's `fleet-probe` user has a forced command (security_probe Ansible
# role): whatever we ask over SSH, it prints the JSON report and nothing else.
# Every target is checked before the script fails, so one broken host cannot
# hide a regression on another. Unset: skip, and do nothing else.
#
# Writes reports/<name>.json (raw report), reports/systemd-security-<name>.md
# and .json (comparison with ci/systemd-security-baseline.json). The pipeline
# publishes reports/** as artifacts.
#
# FLE-16, after the hosts:
#   1. Offline scores of the shipped unit files (reports/systemd-security-offline.*)
#      when systemd-analyze is installed. Informational here: they never change
#      the exit status, because the gates already enforce the offline baseline on
#      every pull request and develop push.
#   2. reports/SYSTEMD-SECURITY-REPORT.md, the page built from all of the above
#      (always written, so it is an artifact even when nothing is committed).
#   3. On develop, or when PUBLISH_REPORT=1 is set (testing on another branch),
#      the page is committed as SYSTEMD-SECURITY-REPORT.md at the repo root and
#      pushed to BITBUCKET_BRANCH, even when a host regressed. The push is
#      retried once after a fetch + rebase and is never forced. A publish failure
#      fails the step but does not stop step 4.
#   4. When a host regressed or could not be fetched: one Jira comment with an
#      @mention (ci/systemd_security.py jira-alert, JIRA_ALERT_* variables; a
#      failed post only warns).
#
# SYSTEMD_SECURITY_BASELINE: optional baseline file to compare against instead of
# ci/systemd-security-baseline.json (the tests use it).
set -euo pipefail

targets="${SECURITY_PROBE_TARGETS:-}"
if [ -z "${targets// /}" ]; then
  echo "SECURITY_PROBE_TARGETS not set, skipping the live systemd security check"
  exit 0
fi

key=/opt/atlassian/pipelines/agent/ssh/id_rsa
known_hosts=/opt/atlassian/pipelines/agent/ssh/known_hosts

ssh_opts=(-T -o BatchMode=yes -o ConnectTimeout=20 -o StrictHostKeyChecking=yes)
if [ -f "$key" ]; then
  ssh_opts+=(-i "$key")
fi
if [ -f "$known_hosts" ]; then
  ssh_opts+=(-o "UserKnownHostsFile=$known_hosts")
fi

compare_opts=()
if [ -n "${SYSTEMD_SECURITY_BASELINE:-}" ]; then
  compare_opts+=(--baseline "$SYSTEMD_SECURITY_BASELINE")
fi

report=SYSTEMD-SECURITY-REPORT.md
bot=(git -c user.name="ddev-fleet security check" -c user.email="security-check@noreply.fleet.pm")
report_url=""

# Commit $report and push it to the pipeline's branch. Explicit checks, no `set -e`:
# the caller runs this in an `||` context.
publish() {
  local branch="${BITBUCKET_BRANCH:-}"
  if [ -z "$branch" ]; then
    echo "ERROR: refusing to publish the report without BITBUCKET_BRANCH (not in a pipeline)" >&2
    return 1
  fi
  cp "reports/$report" "$report" || return 1
  git add "$report" || return 1
  if git diff --cached --quiet -- "$report"; then
    echo "report unchanged, nothing to commit"
  else
    "${bot[@]}" commit -m "chore(security): systemd security report $(date -u +%F)" || return 1
    if ! git push origin "HEAD:refs/heads/$branch"; then
      echo "push rejected, rebasing onto origin/$branch and retrying once" >&2
      git fetch origin "+refs/heads/$branch:refs/remotes/origin/$branch" || return 1
      if ! "${bot[@]}" rebase "origin/$branch"; then
        git rebase --abort || true
        echo "ERROR: could not rebase the report commit onto origin/$branch" >&2
        return 1
      fi
      git push origin "HEAD:refs/heads/$branch" || return 1
    fi
  fi
  if [ -n "${BITBUCKET_GIT_HTTP_ORIGIN:-}" ]; then
    report_url="${BITBUCKET_GIT_HTTP_ORIGIN/#http:/https:}/src/$branch/$report"
  fi
}

mkdir -p reports
failed=0
names=()
for entry in $targets; do
  name="${entry%%=*}"
  dest="${entry#*=}"
  if [ -z "$name" ] || [ -z "$dest" ] || [ "$name" = "$entry" ]; then
    echo "ERROR: bad SECURITY_PROBE_TARGETS entry '$entry' (expected name=user@host)" >&2
    failed=1
    continue
  fi
  names+=("$name")
  echo "== $name ($dest)"
  rm -f "reports/systemd-security-$name.md" "reports/systemd-security-$name.json"
  # The command word is ignored by the forced command; it only has to be non-empty.
  if ! ssh "${ssh_opts[@]}" "$dest" fleet-security-report > "reports/$name.json"; then
    echo "ERROR: could not fetch the security report from $name ($dest)" >&2
    failed=1
    continue
  fi
  if ! python ci/systemd_security.py compare --env "$name" --current "reports/$name.json" \
    "${compare_opts[@]}" \
    --report-md "reports/systemd-security-$name.md" \
    --report-json "reports/systemd-security-$name.json"; then
    failed=1
  fi
done

if [ "${#names[@]}" -eq 0 ]; then
  exit "$failed"
fi

rm -f reports/systemd-security-offline.md reports/systemd-security-offline.json
if command -v "${SYSTEMD_ANALYZE:-systemd-analyze}" > /dev/null 2>&1; then
  if ! { python ci/systemd_security.py offline --out reports/offline.json \
    && python ci/systemd_security.py compare --env offline --current reports/offline.json \
      "${compare_opts[@]}" \
      --report-md reports/systemd-security-offline.md \
      --report-json reports/systemd-security-offline.json; }; then
    echo "WARNING: the offline scores are missing or regressed (informational here, the gates enforce them)" >&2
  fi
else
  echo "systemd-analyze not found, leaving the offline scores out of the report"
fi

publish_failed=0
if python ci/systemd_security.py publish-report --hosts "${names[*]}" --out "reports/$report"; then
  if python ci/systemd_security.py should-publish; then
    publish || publish_failed=1
    if [ "$publish_failed" -ne 0 ]; then
      echo "ERROR: the report was not published" >&2
    fi
  fi
else
  echo "WARNING: could not build the report, nothing is published" >&2
fi

if [ "$failed" -ne 0 ]; then
  python ci/systemd_security.py jira-alert --hosts "${names[*]}" ${report_url:+--report-url "$report_url"} \
    || echo "WARNING: Jira alert step failed" >&2
fi

if [ "$failed" -ne 0 ] || [ "$publish_failed" -ne 0 ]; then
  exit 1
fi
