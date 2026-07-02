import pytest

from fleet.core.errors import LockHeldError
from fleet.core.locks import instance_lock


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
