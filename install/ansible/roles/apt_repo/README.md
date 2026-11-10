# apt_repo

Helper role (not listed in `site.yml`): one third-party apt repository with a pinned signing-key fingerprint.
Used by `caddy` and `authelia` through `include_role`. Same behaviour as `apt_repo` in `install/lib/common.sh`.
`docker` keeps its own copy of these tasks (spike code, left untouched).
