"""Per-instance flock-based locking (spec §5.3)."""

import contextlib
import errno
import fcntl
from pathlib import Path
from typing import Iterator

from fleet.core.errors import LockHeldError


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
