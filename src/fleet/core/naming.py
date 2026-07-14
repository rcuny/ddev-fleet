"""Composition and validation of instance ids (spec §4.3, §5.1)."""

import re

from fleet.core.errors import ValidationError

_PART_RE = re.compile(r"^[a-z0-9]([a-z0-9-]*[a-z0-9])?$")
_SLUGIFY_RE = re.compile(r"[^a-z0-9]+")
_MAX_INSTANCE_ID_LENGTH = 63


def validate_part(value: str) -> None:
    """Validate a single project key or instance name.

    Must match ``^[a-z0-9]([a-z0-9-]*[a-z0-9])?$`` — lowercase letters and
    digits, with single dashes allowed only strictly between two
    alphanumeric characters (no leading or trailing dash, single characters
    still allowed) — and must not contain ``--`` (reserved as the id
    separator between project and instance).
    """
    if not _PART_RE.match(value):
        raise ValidationError(
            f"invalid name {value!r}: must match ^[a-z0-9]([a-z0-9-]*[a-z0-9])?$ "
            "(lowercase letters, digits, single dashes only, no leading/trailing dash)"
        )
    if "--" in value:
        raise ValidationError(
            f"invalid name {value!r}: must not contain '--' (reserved as "
            "the project/instance id separator)"
        )


def slugify(value: str) -> str:
    """Lowercase ``value``, collapse every run of characters outside
    ``[a-z0-9]`` into a single ``-``, and strip leading/trailing ``-``.

    Raises ``ValidationError`` if the result is empty (e.g. input was empty
    or consisted entirely of characters that get stripped).
    """
    slug = _SLUGIFY_RE.sub("-", value.lower()).strip("-")
    if not slug:
        raise ValidationError(f"cannot slugify {value!r}: result is empty")
    return slug


def instance_id(project: str, label: str) -> str:
    """Validate both parts and compose the instance id ``<project>--<label>``."""
    validate_part(project)
    validate_part(label)
    composed = f"{project}--{label}"
    if len(composed) > _MAX_INSTANCE_ID_LENGTH:
        raise ValidationError(
            f"instance id {composed!r} is {len(composed)} characters, exceeds "
            f"the {_MAX_INSTANCE_ID_LENGTH}-character DNS label limit"
        )
    return composed
