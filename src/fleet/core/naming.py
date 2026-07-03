"""Composition and validation of instance ids (spec §4.3, §5.1)."""

import re

from fleet.core.errors import ValidationError

_PART_RE = re.compile(r"^[a-z0-9-]+$")
_MAX_INSTANCE_ID_LENGTH = 63


def validate_part(value: str) -> None:
    """Validate a single project key or instance name.

    Must match ``^[a-z0-9-]+$`` and must not contain ``--`` (reserved as
    the id separator between project and instance).
    """
    if not _PART_RE.match(value):
        raise ValidationError(
            f"invalid name {value!r}: must match ^[a-z0-9-]+$ (lowercase "
            "letters, digits, and single dashes only)"
        )
    if "--" in value:
        raise ValidationError(
            f"invalid name {value!r}: must not contain '--' (reserved as "
            "the project/instance id separator)"
        )


def instance_id(project: str, instance: str) -> str:
    """Validate both parts and compose the instance id ``<project>--<instance>``."""
    validate_part(project)
    validate_part(instance)
    composed = f"{project}--{instance}"
    if len(composed) > _MAX_INSTANCE_ID_LENGTH:
        raise ValidationError(
            f"instance id {composed!r} is {len(composed)} characters, exceeds "
            f"the {_MAX_INSTANCE_ID_LENGTH}-character DNS label limit"
        )
    return composed
