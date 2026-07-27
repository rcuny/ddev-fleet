"""Per-instance flock-based locking (spec §5.3)."""

import contextlib
import errno
import fcntl
from pathlib import Path
from typing import Iterator

from fleet.core.errors import LockHeldError

# Shared fleet-wide advisory lock id serialising ALLOCATION of a new
# instance id — the "pick a free label" step in both a single deploy()
# (core/instances.py) and a bulk multi_deploy() (core/bulk.py) — so the two
# paths can never allocate the same id concurrently. This used to be a
# private constant in core/bulk.py (`_MULTIDEPLOY_LOCK_ID`); it moved here
# once core/instances.py's own deploy() started needing the same lock for
# its own (single-instance) allocation.
#
# The STRING VALUE must stay exactly "_multideploy" — it names an on-disk
# lock file (`<locks_dir>/_multideploy.lock`), not just a Python symbol. A
# process still running the old code (e.g. mid-rollout, before every host
# picks up this change) locks that same path under the old private
# constant; renaming the string here would let it and a new process
# allocate concurrently, defeating the whole point of the lock.
ALLOCATION_LOCK_ID = "_multideploy"


@contextlib.contextmanager
def instance_lock(locks_dir: Path, instance_id: str) -> Iterator[None]:
    locks_dir.mkdir(parents=True, exist_ok=True)
    lock_path = locks_dir / f"{instance_id}.lock"
    lock_path.touch(exist_ok=True)

    fh = open(lock_path, "r+")
    try:
        try:
            fcntl.flock(fh.fileno(), fcntl.LOCK_EX | fcntl.LOCK_NB)
        except OSError as exc:
            if exc.errno in (errno.EAGAIN, errno.EWOULDBLOCK):
                raise LockHeldError(
                    f"lock already held for instance {instance_id!r} ({lock_path})"
                ) from exc
            raise
        try:
            yield
        finally:
            fcntl.flock(fh.fileno(), fcntl.LOCK_UN)
    finally:
        fh.close()
