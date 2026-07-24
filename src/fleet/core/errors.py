"""The fleet error hierarchy. Every fleet-specific failure is a FleetError
subclass carrying an actionable, human-readable message."""


class FleetError(Exception):
    """Base class for all fleet errors."""

    def __init__(self, message: str) -> None:
        super().__init__(message)
        self.message = message


class ValidationError(FleetError):
    """A project key, instance name, or composed instance id is invalid."""


class TokenError(FleetError):
    """A [[token]] failed to resolve during substitution."""


class RegistryError(FleetError):
    """fleet.yml failed to load, validate, or save."""


class DirtyWorktreeError(FleetError):
    """An instance's git worktree is dirty or has unpushed commits."""


class LockHeldError(FleetError):
    """The per-instance flock is already held by another operation."""


class DeployError(FleetError):
    """The deploy pipeline failed at some step."""


class TypesenseError(FleetError):
    """A Typesense admin-API call (key generation/registration) failed."""


class CaddyAuthError(FleetError):
    """Hashing/writing/validating/reloading the Caddy dashboard admin-auth
    snippet failed (fleet.core.caddyauth)."""


class CaddyPortsError(FleetError):
    """Composing/writing/validating/reloading a fleet-owned Caddy named-port
    exposure snippet failed (fleet.core.caddyports)."""


class DiskSpaceError(FleetError):
    """Insufficient free disk space to proceed with a bulk deploy."""
