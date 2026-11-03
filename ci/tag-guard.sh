#!/usr/bin/env bash
# Decides what a release tag may publish to the GitHub mirror.
#
#   ci/tag-guard.sh <tag> <commit>
#
# Run it in a clone that has the refs origin/main, origin/develop and origin/legacy (the tag
# pipeline fetches them). On success it prints the space-separated refspecs for
# ONE non-forced `git push` to the mirror and exits 0; otherwise it prints
# "ERROR: ..." on stderr, prints nothing on stdout and exits 1.
#
#   v0.*  (the Python 0.x line, frozen on the legacy branch) the commit must be the
#         tip of origin/legacy. Prints "<commit>:refs/heads/legacy refs/tags/<tag>".
#   v1.*  (the Symfony 1.x line, vMAJOR.MINOR.PATCH or with a -prerelease; later majors
#         v2+ follow the same rule) the commit must be on origin/main.
#         - the tip of origin/main: prints "<commit>:refs/heads/main refs/tags/<tag>",
#           GitHub's main fast-forwards to the release;
#         - an ancestor of origin/main but not its tip (the v1.0.x tags cut on develop
#           before the cut-over): prints "refs/tags/<tag>" only.
#         A commit only on develop is refused: a release is merged into main first.
#   anything else is rejected.
# In every case the local tag (refs/tags/<tag>, fetched by the pipeline) must
# point at <commit>: the push sends the tag ref, so a tag moved after the
# pipeline was triggered is refused.
#
# Note: a tag pipeline runs the bitbucket-pipelines.yml of the TAGGED commit, and
# legacy still carries the Python pipeline, so the v0.* branch is a documented
# safety net rather than a path the current pipeline reaches. The CI host's publish
# script calls this guard too.
set -euo pipefail

die() {
  echo "ERROR: $*" >&2
  exit 1
}

[ "$#" -eq 2 ] || die "usage: ci/tag-guard.sh <tag> <commit>"
tag=$1
commit_arg=$2

[ -n "$tag" ] || die "empty tag name"

# Refs that git itself would refuse are rejected here with a clear message.
case "$tag" in
  *..* | *.lock | *. | *-) die "tag '$tag' is not a valid release tag name" ;;
esac

v0_re='^v0\.[0-9]+\.[0-9]+(-[0-9A-Za-z.]+)?$'
v1_re='^v[1-9][0-9]*\.[0-9]+\.[0-9]+(-[0-9A-Za-z.]+)?$'

if [[ $tag =~ $v0_re ]]; then
  line=v0
elif [[ $tag =~ $v1_re ]]; then
  line=v1
else
  die "tag '$tag' is neither v0.X.Y nor vN.X.Y[-prerelease]; refusing to publish"
fi

commit=$(git rev-parse --verify --quiet "${commit_arg}^{commit}") ||
  die "commit '$commit_arg' does not exist in this clone"

# The push sends the tag ref, not the commit: the local tag (fetched by the pipeline) must still point at the
# commit this pipeline was triggered for, or a tag moved after the trigger would publish unchecked code.
tag_commit=$(git rev-parse --verify --quiet "refs/tags/${tag}^{commit}") ||
  die "tag $tag does not exist in this clone (fetch it before calling the guard)"
[ "$tag_commit" = "$commit" ] ||
  die "tag $tag points at $tag_commit, not at the triggering commit $commit (moved after the trigger?); refusing to publish"

remote_tip() {
  git rev-parse --verify --quiet "refs/remotes/origin/$1^{commit}" ||
    die "origin/$1 is missing in this clone (fetch it before calling the guard)"
}

if [ "$line" = v0 ]; then
  legacy_tip=$(remote_tip legacy)
  [ "$legacy_tip" = "$commit" ] ||
    die "tag $tag ($commit) is not the tip of origin/legacy ($legacy_tip); refusing to publish"
  echo "$commit:refs/heads/legacy refs/tags/$tag"
else
  main_tip=$(remote_tip main)
  git merge-base --is-ancestor "$commit" "$main_tip" ||
    die "tag $tag ($commit) is not on origin/main (merge the release into main first); refusing to publish"
  if [ "$main_tip" = "$commit" ]; then
    echo "$commit:refs/heads/main refs/tags/$tag"
  else
    echo "refs/tags/$tag"
  fi
fi
