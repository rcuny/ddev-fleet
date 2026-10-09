import errno

import pytest

from fleet.core.errors import LockHeldError
from fleet.core.locks import ALLOCATION_LOCK_ID, instance_lock


def test_allocation_lock_id_is_the_original_multideploy_lock_file_name():
    """MUST stay exactly "_multideploy" — it names an on-disk lock file
    (see core/locks.py's docstring on ALLOCATION_LOCK_ID). This constant
    used to be private to core/bulk.py; moving it here must not change the
    on-disk lock file a running/rolling-out process would use."""
    assert ALLOCATION_LOCK_ID == "_multideploy"


def test_lock_can_be_acquired_and_released(tmp_path):
    locks_dir = tmp_path / "locks"
    with instance_lock(locks_dir, "oak--develop"):
        pass
    # Released — acquiring again must succeed.
    with instance_lock(locks_dir, "oak--develop"):
        pass


def test_lock_creates_locks_dir_if_missing(tmp_path):
    locks_dir = tmp_path / "does-not-exist-yet" / "locks"
    with instance_lock(locks_dir, "oak--develop"):
        pass
    assert locks_dir.exists()


def test_nested_lock_on_same_instance_raises_lock_held(tmp_path):
    locks_dir = tmp_path / "locks"
    with instance_lock(locks_dir, "oak--develop"):
        with pytest.raises(LockHeldError):
            with instance_lock(locks_dir, "oak--develop"):
                pass


def test_lock_on_different_instances_does_not_conflict(tmp_path):
    locks_dir = tmp_path / "locks"
    with instance_lock(locks_dir, "oak--develop"):
        with instance_lock(locks_dir, "oak--piano"):
            pass


def test_non_contention_oserror_is_not_swallowed_as_lock_held(tmp_path, monkeypatch):
    """Only EAGAIN/EWOULDBLOCK (lock actually held) should become
    LockHeldError. Any other OSError (e.g. ENOLCK, EIO) is a real fault
    and must propagate unchanged, not be masked as lock contention."""
    import fcntl

    locks_dir = tmp_path / "locks"

    def _raise_enolck(*args, **kwargs):
        raise OSError(errno.ENOLCK, "no locks available")

    monkeypatch.setattr(fcntl, "flock", _raise_enolck)

    with pytest.raises(OSError) as exc_info:
        with instance_lock(locks_dir, "oak--develop"):
            pass
    assert not isinstance(exc_info.value, LockHeldError)
    assert exc_info.value.errno == errno.ENOLCK
