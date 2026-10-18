#!/usr/bin/env bash
# Decides what a release tag may publish to the GitHub mirror.
#
#   ci/tag-guard.sh <tag> <commit>
#
# Run it in a clone that has the refs origin/main and origin/develop (the tag
# pipeline fetches both). On success it prints the space-separated refspecs for
# ONE non-forced `git push` to the mirror and exits 0; otherwise it prints
# "ERROR: ..." on stderr, prints nothing on stdout and exits 1.
#
#   v0.*  (the Python 0.x line) the commit must be the tip of origin/main.
#         Prints "<commit>:refs/heads/main refs/tags/<tag>": GitHub's main
#         fast-forwards to the release, as it always did.
#   v1.*  (the Symfony 1.x line, vMAJOR.MINOR.PATCH or with a -prerelease) the
#         commit must be on origin/develop (the tip or an ancestor). Prints
#         "refs/tags/<tag>" only: a 1.x tag never moves GitHub's main.
#   anything else is rejected.
# In every case the local tag (refs/tags/<tag>, fetched by the pipeline) must
# point at <commit>: the push sends the tag ref, so a tag moved after the
# pipeline was triggered is refused.
#
# Note: a tag pipeline runs the bitbucket-pipelines.yml of the TAGGED commit, and
# main still carries the Python pipeline, so the v0.* branch is a documented
# safety net rather than a path the current pipeline reaches.
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
v1_re='^v1\.[0-9]+\.[0-9]+(-[0-9A-Za-z.]+)?$'

if [[ $tag =~ $v0_re ]]; then
  line=v0
elif [[ $tag =~ $v1_re ]]; then
  line=v1
else
  die "tag '$tag' is neither v0.X.Y nor v1.X.Y[-prerelease]; refusing to publish"
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
  main_tip=$(remote_tip main)
  [ "$main_tip" = "$commit" ] ||
    die "tag $tag ($commit) is not the tip of origin/main ($main_tip); refusing to publish"
  echo "$commit:refs/heads/main refs/tags/$tag"
else
  develop_tip=$(remote_tip develop)
  git merge-base --is-ancestor "$commit" "$develop_tip" ||
    die "tag $tag ($commit) is not on origin/develop; refusing to publish"
  echo "refs/tags/$tag"
fi
