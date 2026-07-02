import pytest

from fleet.core.errors import (
    DeployError,
    DirtyWorktreeError,
    FleetError,
    LockHeldError,
    RegistryError,
    TokenError,
    ValidationError,
)


@pytest.mark.parametrize(
    "cls",
    [ValidationError, TokenError, RegistryError, DirtyWorktreeError, LockHeldError, DeployError],
)
def test_subclasses_carry_message(cls):
    exc = cls("something went wrong")
    assert isinstance(exc, FleetError)
    assert exc.message == "something went wrong"
    assert str(exc) == "something went wrong"
