import pytest

from fleet.core.errors import (
    CaddyPortsError,
    DeployError,
    DirtyWorktreeError,
    FleetError,
    LockHeldError,
    RegistryError,
    RouterMirrorError,
    TokenError,
    ValidationError,
)


@pytest.mark.parametrize(
    "cls",
    [
        ValidationError,
        TokenError,
        RegistryError,
        DirtyWorktreeError,
        LockHeldError,
        DeployError,
        CaddyPortsError,
        RouterMirrorError,
    ],
)
def test_subclasses_carry_message(cls):
    exc = cls("something went wrong")
    assert isinstance(exc, FleetError)
    assert exc.message == "something went wrong"
    assert str(exc) == "something went wrong"
