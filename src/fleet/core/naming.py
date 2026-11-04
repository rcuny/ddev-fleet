"""Composition and validation of instance ids (spec §4.3, §5.1)."""

import hashlib
import re

from fleet.core.errors import ValidationError

_PART_RE = re.compile(r"^[a-z0-9]([a-z0-9-]*[a-z0-9])?$")
_SLUGIFY_RE = re.compile(r"[^a-z0-9]+")
# DDEV names the web container `ddev-<id>-web` and uses `<id>-web` as its
# hostname, a DNS label (RFC 1035, at most 63 characters; Docker rejects a
# longer container hostname, so `ddev start` fails). An instance id therefore
# has to leave room for the 4-character "-web": 63 - len("-web") = 59. This is
# the creation limit (matches the 1.x line, FLE-144); lookups, listing and
# destroy never check it, so older instances with ids of 60-63 characters stay
# listable and destroyable (FLE-166).
_DNS_LABEL_LIMIT = 63
_MAX_INSTANCE_ID_LENGTH = _DNS_LABEL_LIMIT - len("-web")
_LABEL_HASH_LENGTH = 6


def validate_part(value: str) -> None:
    """Validate a single project key or instance name.

    Must match ``^[a-z0-9]([a-z0-9-]*[a-z0-9])?$`` — lowercase letters and
    digits, with single dashes allowed only strictly between two
    alphanumeric characters (no leading or trailing dash, single characters
    still allowed) — and must not contain ``--`` (reserved as the id
    separator between project and instance).
    """
    if not _PART_RE.fullmatch(value):
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


def derive_label(
    project: str,
    branch: str,
    *,
    label: str | None = None,
    label_prefix: str | None = None,
    strip_suffix: str | None = None,
    reserve: int = 0,
) -> str:
    """The instance label for a branch deployed by a `repo:push` hook rule.

    `label`, when given, is used as is. Otherwise the label is `label_prefix` +
    `branch` minus a trailing `strip_suffix`. Either way the result is
    slugified, and when `<project>--<label>` would exceed the 59-character instance id
    limit the slug is cut and ends in `-<first 6 hex of sha1(branch)>`,
    so the id fits, stays deterministic (a re-run lands on the same instance)
    and two long branches sharing a prefix do not collide.

    `reserve` is the number of characters the caller needs to keep free on top
    of the id (the longest `<hostname>-` alias prefix of the project's
    `additional_hostnames`, whose label `<hostname>-<instance id>` is held to
    a 63-character limit, or room for an allocated `-N` suffix): the id is
    fitted to `59 - reserve`.

    Raises `ValidationError` if the slug is empty, or if `project` is so long
    that not even a shortened label fits."""
    if label:
        raw = label
    else:
        stem = branch.removesuffix(strip_suffix) if strip_suffix else branch
        raw = f"{label_prefix or ''}{stem}"
    return _fit_label(project, slugify(raw), branch, reserve)


def _fit_label(project: str, slug: str, hash_source: str, reserve: int) -> str:
    """`slug` unchanged when `<project>--<slug>` plus `reserve` extra characters
    fits the instance id limit, else cut and ended by `-<6 hex of
    sha1(hash_source)>`."""
    # 2 = the "--" separator
    room = _MAX_INSTANCE_ID_LENGTH - reserve - len(project) - 2
    if len(slug) <= room:
        return slug
    digest = hashlib.sha1(hash_source.encode("utf-8"), usedforsecurity=False).hexdigest()
    tail = f"-{digest[:_LABEL_HASH_LENGTH]}"
    keep = room - len(tail)
    if keep < 1:
        raise ValidationError(
            f"project name {project!r} is too long to fit any label for {hash_source!r} "
            f"within the {_MAX_INSTANCE_ID_LENGTH}-character instance id limit"
        )
    return slug[:keep].rstrip("-") + tail


def instance_id(project: str, label: str) -> str:
    """Validate both parts and compose the instance id ``<project>--<label>``."""
    validate_part(project)
    validate_part(label)
    composed = f"{project}--{label}"
    if len(composed) > _MAX_INSTANCE_ID_LENGTH:
        raise ValidationError(
            f"instance id {composed!r} is {len(composed)} characters, exceeds "
            f"the {_MAX_INSTANCE_ID_LENGTH}-character limit (DDEV appends '-web' to "
            f"the id for the web container hostname, a {_DNS_LABEL_LIMIT}-character DNS label)"
        )
    return composed


def allocate_multi_deploy_labels(
    existing_ids: set[str], project: str, base_label: str, count: int
) -> list[str]:
    """Return `count` free instance labels of the form f"{base_label}-{n}",
    n starting at 1, skipping any n whose composed instance id
    (f"{project}--{base_label}-{n}") is already in `existing_ids`. A fresh
    `existing_ids` scan (a directory listing) already reflects any prior
    batch's high-water mark, so this never needs to remember it across
    calls.

    Validates the WHOLE batch (all `count` candidates) via instance_id()
    before returning — raises ValidationError if any candidate would exceed
    the 59-char instance id limit, so a too-long batch fails atomically
    instead of partway through a later deploy loop."""
    if count == 0:
        return []

    labels: list[str] = []
    n = 1
    # Reserve room for a `-NN` suffix before fitting the base, so base +
    # suffix stays within the id limit (a batch past -99 is caught below).
    base_label = _fit_label(project, base_label, base_label, len("-NN"))
    while len(labels) < count:
        candidate_label = f"{base_label}-{n}"
        candidate_id = f"{project}--{candidate_label}"
        if candidate_id not in existing_ids:
            labels.append(candidate_label)
        n += 1

    for label in labels:
        instance_id(project, label)  # raises ValidationError if too long

    return labels


def allocate_free_label(existing_ids: set[str], project: str, base_label: str) -> str:
    """Return the first free label from the sequence `base_label`,
    `base_label-1`, `base_label-2`, … — "free" meaning the composed
    `f"{project}--{label}"` is not in `existing_ids`. Unlike
    `allocate_multi_deploy_labels` above, the BARE base label is returned
    when it is free (no suffix at all).

    This is deliberately the opposite default of `allocate_multi_deploy_labels`:
    this is the single-deploy allocator (`deploy()` never overwrites an
    existing instance — see 2026-07-27-fleet-redeploy-and-no-overwrite-design.md
    decision 1), where landing on the clean, unsuffixed id is the whole
    point when nothing is in the way. `allocate_multi_deploy_labels` ALWAYS
    suffixes starting at `-1` because a *batch* of `count` instances
    deployed in one `deploy --count=N` call has no single "the" instance to
    give the bare name to — a batch of one bare name makes no sense. Do
    not merge these two functions on the theory that one is a special case
    of the other: their starting points answer different questions ("is
    THIS name free?" vs "give me N names, none of which is THIS one").

    Validates only the FINAL chosen label through `instance_id()`, so the
    59-char instance id limit still raises.
    """
    n = 0
    candidate_label = base_label
    while f"{project}--{candidate_label}" in existing_ids:
        n += 1
        # The base is cut (hash-suffixed) so base + `-N` still fits the limit.
        fitted = _fit_label(project, base_label, base_label, len(f"-{n}"))
        candidate_label = f"{fitted}-{n}"
    instance_id(project, candidate_label)  # raises ValidationError if too long
    return candidate_label
