#!/bin/bash
# End-to-end rehearsal of `fleet upgrade` on a TEST HOST, with no public release.
#
#   sudo ci/upgrade-rehearsal.sh --yes-this-is-a-test-host [--source=DIR] [--first-tag=v1.9.1] [--second-tag=v1.9.2]
#   ci/upgrade-rehearsal.sh --build-only [--base-tag=vX.Y.Z] [--workdir=DIR]    (no root, changes nothing on a host)
#   ci/upgrade-rehearsal.sh --dry-run                                           (prints the plan, runs nothing)
#
# What it does (docs/upgrading.md, "Rehearse on a test host"):
#   1. builds a local bare git repository from the host's own checkout: the installed release's commit, then two
#      throwaway annotated tags on top of it (default v1.9.1 and v1.9.2, newer than any real release) on `main`, the
#      second with a CHANGELOG section that carries a rollout note and an ACTION: line;
#   2. points the release source at that repository (FLEET_UPGRADE_REPO_URL in fleet.env and the checkout's origin, both
#      put back at the end);
#   3. runs, as root: check, a refused non-interactive upgrade, upgrade to the first tag, upgrade to the latest tag,
#      rollback to the first tag (database restored), and a rollback to the release the host started on,
#      asserting after each step the exit code, /etc/ddev-fleet/installed-version and the check's verdict;
#   4. puts the host back: removes the override, points the checkout's origin at the public mirror again, deletes the
#      throwaway tags from the checkout and refreshes the release cache from the real source.
#
# It changes a real host: the installer runs several times, the workers and php-fpm restart, Caddy and Authelia restart
# (every signed-in user is signed out) and the database is restored once. Run it only on a host built for testing, when
# no job is running. Nothing here is meant for CI; CI covers the pieces with fakes (UpgradeHelperTest).
# Left behind: backups in the backup directory and the rehearsal lines in /var/log/ddev-fleet/upgrade-history.jsonl.
#
# Exit codes: 0 every assertion held, 1 an assertion failed or a step errored, 2 usage, 3 refused (not a test host
# confirmation, not root, not a release install).

main() {
  set -Eeuo pipefail
  IFS=$' \t\n'
  export LC_ALL=C

  local etc_dir=${FLEET_REHEARSAL_ETC_DIR:-/etc/ddev-fleet}
  local source_dir=${FLEET_REHEARSAL_SOURCE:-/opt/ddev-fleet}
  local fleet_user=${FLEET_REHEARSAL_USER:-fleet}
  local public_url=https://github.com/rcuny/ddev-fleet.git
  local first=v1.9.1 second=v1.9.2 base_tag='' workdir=''
  local confirmed=0 build_only=0 dry_run=0 keep=0 arg

  usage() {
    printf 'usage: %s --yes-this-is-a-test-host [--source=DIR] [--first-tag=vX.Y.Z] [--second-tag=vX.Y.Z] [--keep]\n' "$0"
    printf '       %s --build-only [--source=DIR] [--base-tag=vX.Y.Z] [--workdir=DIR]\n' "$0"
    printf '       %s --dry-run\n' "$0"
  }

  for arg in "$@"; do
    case $arg in
      --yes-this-is-a-test-host) confirmed=1 ;;
      --build-only) build_only=1 ;;
      --dry-run) dry_run=1 ;;
      --keep) keep=1 ;;
      --source=*) source_dir=${arg#--source=} ;;
      --first-tag=*) first=${arg#--first-tag=} ;;
      --second-tag=*) second=${arg#--second-tag=} ;;
      --base-tag=*) base_tag=${arg#--base-tag=} ;;
      --workdir=*) workdir=${arg#--workdir=} ;;
      -h | --help) usage; return 0 ;;
      *) printf 'unknown option: %s\n' "$arg" >&2; usage >&2; return 2 ;;
    esac
  done

  local tag_re='^v1\.[0-9]+\.[0-9]+$'
  [[ $first =~ $tag_re && $second =~ $tag_re ]] || { printf 'the throwaway tags must look like v1.X.Y\n' >&2; return 2; }
  [ "$first" != "$second" ] || { printf 'the two throwaway tags must differ\n' >&2; return 2; }

  if [ "$dry_run" -eq 1 ]; then
    printf 'Plan (nothing is run):\n'
    printf '  1. read %s/installed-version (the release the host starts on)\n' "$etc_dir"
    printf '  2. build a bare repository from %s: that release, then %s and %s on main\n' "$source_dir" "$first" "$second"
    printf '  3. set FLEET_UPGRADE_REPO_URL in %s/fleet.env and point origin of %s at the repository\n' "$etc_dir" "$source_dir"
    printf '  4. fleet upgrade --check --no-cache (--json, and the text form: update available, rollout.action shown)\n'
    printf '  5. fleet upgrade --to %s with no terminal and no --yes: refused (exit 3), nothing changed\n' "$first"
    printf '  6. fleet upgrade --to %s --yes: installed-version %s\n' "$first" "$first"
    printf '  7. fleet upgrade --yes: installed-version %s, check says up to date\n' "$second"
    printf '  8. fleet upgrade --to %s --yes: rollback with the database restored, installed-version %s\n' "$first" "$first"
    printf '  9. fleet upgrade --to <the starting release> --yes: back where the host began\n'
    printf ' 10. remove the override, reset origin, delete the throwaway tags, refresh the release cache\n'
    return 0
  fi

  if [ "$build_only" -eq 0 ]; then
    [ "$confirmed" -eq 1 ] || { printf 'refused: pass --yes-this-is-a-test-host (this changes the host it runs on)\n' >&2; return 3; }
    [ "$(id -u)" -eq 0 ] || { printf 'refused: run it as root (sudo)\n' >&2; return 3; }
    [ -r "$etc_dir/installed-version" ] || { printf 'refused: %s/installed-version is missing (not a 1.x install)\n' "$etc_dir" >&2; return 3; }
    base_tag=$(tr -d '[:space:]' <"$etc_dir/installed-version")
  fi
  [[ $base_tag =~ $tag_re ]] || { printf 'refused: the starting version %q is not a release tag (the host must run a vX.Y.Z release)\n' "$base_tag" >&2; return 3; }

  # --- the throwaway repository ---------------------------------------------------------------------------------------
  local created_workdir=0
  if [ -z "$workdir" ]; then
    workdir=$(mktemp -d /var/tmp/fleet-upgrade-rehearsal.XXXXXX)
    created_workdir=1
  else
    mkdir -p -- "$workdir"
  fi
  local repo="$workdir/releases.git" work="$workdir/work"
  # Root builds and root runs the helper, `fleet` owns the checkout and runs the checks: ownership differs, so git is told
  # these paths are safe for this process tree only (command-line scope; nothing is written to any git config). That
  # scope is NOT passed on to the helper process a local clone or push starts (git clears GIT_CONFIG_COUNT for it), so
  # the one command that reads the fleet-owned checkout through such a helper, the clone, runs as the checkout's owner.
  export GIT_CONFIG_COUNT=3
  export GIT_CONFIG_KEY_0=safe.directory GIT_CONFIG_VALUE_0=$source_dir
  export GIT_CONFIG_KEY_1=safe.directory GIT_CONFIG_VALUE_1=$repo
  export GIT_CONFIG_KEY_2=safe.directory GIT_CONFIG_VALUE_2=$work
  export GIT_TERMINAL_PROMPT=0
  export GIT_AUTHOR_NAME='Upgrade Rehearsal' GIT_AUTHOR_EMAIL=rehearsal@example.invalid
  export GIT_COMMITTER_NAME='Upgrade Rehearsal' GIT_COMMITTER_EMAIL=rehearsal@example.invalid

  say() { printf '%s\n' "$*"; }
  step() { printf '\n==> %s\n' "$*"; }

  add_section() { # add_section <version-without-v> <date> <with-rollout 0|1>
    local version=$1 date=$2 rollout=$3 section
    section=$(mktemp)
    {
      printf '\n## [%s] - %s\n\n### Changed\n- **Rehearsal %s.** A throwaway release; it carries no code change.\n' "$version" "$date" "$version"
      if [ "$rollout" -eq 1 ]; then
        printf '\n#### Rollout\n- The rehearsal release restarts the workers once.\n- ACTION: none really needed; this line exists so the check shows an operator action.\n'
      fi
    } >"$section"
    sed -i "/^## \\[Unreleased\\]\$/r $section" CHANGELOG.md
    rm -f -- "$section"
  }

  step "Build the release repository from $source_dir (starting release $base_tag)"
  rm -rf -- "$work" "$repo"
  if [ "$(id -u)" -eq 0 ]; then
    # Root cloning a fleet-owned checkout fails with "dubious ownership" (FLE-173): clone as the owner, into a directory it
    # can write; the rest of the build (root) reaches the clone through the safe.directory entry above.
    chown -- "$fleet_user:$fleet_user" "$workdir"
    runuser -u "$fleet_user" -- git clone --quiet -- "$source_dir" "$work"
  else
    git clone --quiet -- "$source_dir" "$work"
  fi
  (
    cd "$work"
    git rev-parse --verify --quiet "refs/tags/$base_tag^{commit}" >/dev/null \
      || { printf 'the checkout has no tag %s, so the host cannot be put back to it\n' "$base_tag" >&2; exit 3; }
    git checkout --quiet -B main "refs/tags/$base_tag^{commit}"
    [ -f CHANGELOG.md ] || { printf 'the checkout has no CHANGELOG.md\n' >&2; exit 3; }
    add_section "${first#v}" "$(date -u +%F)" 0
    git add CHANGELOG.md
    git commit --quiet -m "rehearsal: $first"
    git tag -a "$first" -m "rehearsal $first"
    add_section "${second#v}" "$(date -u +%F)" 1
    git add CHANGELOG.md
    git commit --quiet -m "rehearsal: $second"
    git tag -a "$second" -m "rehearsal $second"
    git init --quiet --bare -- "$repo"
    git -C "$repo" config receive.shallowUpdate true
    git push --quiet --force "$repo" refs/heads/main:refs/heads/main "refs/tags/$base_tag" "refs/tags/$first" "refs/tags/$second"
    git -C "$repo" symbolic-ref HEAD refs/heads/main
  )
  say "Repository: $repo  (main, $base_tag, $first, $second)"

  if [ "$build_only" -eq 1 ]; then
    say 'Build only: the host was not touched. Remove the work directory when done:'
    say "  rm -rf -- $workdir"
    return 0
  fi

  chown -R "$fleet_user:$fleet_user" -- "$repo"
  chmod 755 -- "$workdir"

  # --- the host: override, steps, assertions --------------------------------------------------------------------------
  local env_file="$etc_dir/fleet.env" override_added=0 failed=0 rc=0 log="$workdir/steps.log"

  restore_host() {
    local code=$?
    set +e
    step 'Put the host back'
    if [ "$override_added" -eq 1 ]; then
      sed -i '/^FLEET_UPGRADE_REPO_URL=/d' "$env_file" && say "removed FLEET_UPGRADE_REPO_URL from $env_file"
    fi
    if [ -d "$source_dir/.git" ]; then
      sudo -u "$fleet_user" git -C "$source_dir" remote set-url origin "$public_url" && say "origin of $source_dir is $public_url again"
      sudo -u "$fleet_user" git -C "$source_dir" tag -d "$first" "$second" >/dev/null 2>&1 && say "deleted $first and $second from the checkout"
    fi
    fleet upgrade:refresh >/dev/null 2>&1 && say 'release cache refreshed from the real source' \
      || say 'could not refresh the release cache now (offline?); the daily timer will'
    [ "$keep" -eq 1 ] || { [ "$created_workdir" -eq 0 ] || rm -rf -- "$workdir"; }
    return "$code"
  }
  trap restore_host EXIT

  grep -q '^FLEET_UPGRADE_REPO_URL=.' "$env_file" && { printf 'refused: %s already sets FLEET_UPGRADE_REPO_URL\n' "$env_file" >&2; trap - EXIT; return 3; }
  printf 'FLEET_UPGRADE_REPO_URL=%s\n' "$repo" >>"$env_file"
  override_added=1
  # The pre-flight check (checkout.origin) wants origin to be the release source, and the source is the throwaway
  # repository for this run; restore_host points origin at the public mirror again.
  sudo -u "$fleet_user" git -C "$source_dir" remote set-url origin "$repo"
  say "origin of $source_dir is $repo for this run"

  version() { tr -d '[:space:]' <"$etc_dir/installed-version"; }
  ok() { say "ok:   $*"; }
  bad() { failed=$((failed + 1)); say "FAIL: $*"; }

  # run <expected-exit> <description> <command...>: runs, shows the output, asserts the exit code.
  run() {
    local expect=$1 what=$2
    shift 2
    say "\$ $*"
    rc=0
    "$@" >"$log" 2>&1 </dev/null || rc=$?
    cat -- "$log"
    if [ "$rc" -eq "$expect" ]; then ok "$what (exit $rc)"; else bad "$what: exit $rc, expected $expect"; fi
  }
  expect_version() {
    local got
    got=$(version)
    if [ "$got" = "$1" ]; then ok "installed-version is $1"; else bad "installed-version is $got, expected $1"; fi
  }
  log_has() { # log_has <fixed string> <description>, against the last run
    if grep -qF -- "$1" "$log"; then ok "$2"; else bad "$2 (looked for: $1)"; fi
  }
  json_has() { # json_has <compact fragment> <description>
    if tr -d ' \n' <"$log" | grep -qF -- "$1"; then ok "$2"; else bad "$2 (looked for: $1)"; fi
  }

  step "Check from $base_tag: an update is available"
  run 0 'check' fleet upgrade --check --no-cache
  log_has "$second" "the check names $second"
  log_has 'rollout.action' 'the ACTION: rollout note is shown as a warning'
  run 0 'check --json' fleet upgrade --check --no-cache --json
  json_has '"status":"update_available"' 'status update_available'
  json_has "\"latest\":{\"tag\":\"$second\"" "latest is $second"
  run 2 'an unpublished --to is a usage error' fleet upgrade --check --to v1.99.99

  step "A non-interactive upgrade without --yes is refused"
  run 3 'refused without --yes' fleet upgrade --to "$first" --repo-url="$repo"
  expect_version "$base_tag"

  step "Upgrade to $first"
  run 0 "upgrade to $first" fleet upgrade --to "$first" --yes --repo-url="$repo"
  expect_version "$first"

  step "Upgrade to the latest ($second)"
  run 0 'upgrade to the latest' fleet upgrade --yes --repo-url="$repo"
  expect_version "$second"
  run 0 'check after the upgrade' fleet upgrade --check --no-cache
  log_has 'Already up to date' 'the check says up to date'

  step "Roll back to $first (database restored from the backup taken before $second)"
  run 0 "rollback to $first" fleet upgrade --to "$first" --yes --repo-url="$repo"
  expect_version "$first"
  log_has 'IS LOST' 'the rollback named the data that is lost'
  run 0 'check after the rollback' fleet upgrade --check --no-cache --json
  json_has '"status":"update_available"' 'update available again'

  step "Back to the release the host started on ($base_tag)"
  run 0 "rollback to $base_tag" fleet upgrade --to "$base_tag" --yes --repo-url="$repo"
  expect_version "$base_tag"

  step 'History'
  fleet upgrade history --limit=20 || true

  step 'Result'
  if [ "$failed" -eq 0 ]; then
    say 'REHEARSAL PASSED'
    rc=0
  else
    say "REHEARSAL FAILED: $failed assertion(s)"
    rc=1
  fi
  say 'Also check by hand: sign in and open the dashboard; the Upgrade page shows no "available" badge for the throwaway tags.'
  return "$rc"
}

main "$@"
