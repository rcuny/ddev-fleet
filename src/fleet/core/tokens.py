"""The [[token]] substitution engine (spec §7.3)."""

import re
from pathlib import Path

from fleet.core.errors import TokenError

_TOKEN_RE = re.compile(r"\[\[([a-z0-9-]+)\]\]")
_MAX_SUBSTITUTE_BYTES = 1024 * 1024  # 1 MiB
_SNIFF_BYTES = 8192


def build_context(project: str, instance: str, branch: str, domain: str) -> dict[str, str]:
    instance_id_value = f"{project}--{instance}"
    return {
        "instance-id": instance_id_value,
        "project": project,
        "instance": instance,
        "branch": branch,
        "fleet-domain": domain,
        "instance-fqdn": f"{instance_id_value}.{domain}",
    }


def substitute_text(text: str, context: dict[str, str]) -> str:
    def _replace(match: "re.Match[str]") -> str:
        key = match.group(1)
        return context.get(key, match.group(0))

    result = _TOKEN_RE.sub(_replace, text)

    remaining = _TOKEN_RE.findall(result)
    if remaining:
        tokens = ", ".join(f"[[{name}]]" for name in sorted(set(remaining)))
        raise TokenError(f"unresolved token(s): {tokens}")
    return result


def substitute_file(path: Path, context: dict[str, str]) -> bool:
    """Substitute tokens in-place in a single file.

    Returns False (does nothing) for files larger than 1 MiB, files
    containing a null byte in the first 8 KiB (treated as binary), or files
    that are not valid UTF-8 (treated as binary/skip, not an error — e.g. a
    Latin-1 asset file that happens to sneak past the null-byte sniff).
    Returns True once the file has been scanned and rewritten. Raises
    TokenError (naming the file) if an unresolved token remains.
    """
    size = path.stat().st_size
    if size > _MAX_SUBSTITUTE_BYTES:
        return False

    with open(path, "rb") as fh:
        head = fh.read(_SNIFF_BYTES)
    if b"\x00" in head:
        return False

    try:
        text = path.read_text(encoding="utf-8")
    except UnicodeDecodeError:
        return False

    try:
        substituted = substitute_text(text, context)
    except TokenError as exc:
        raise TokenError(f"{path}: {exc.message}") from exc
    path.write_text(substituted, encoding="utf-8")
    return True


def env_vars(context: dict[str, str]) -> dict[str, str]:
    return {
        "FLEET_INSTANCE_ID": context["instance-id"],
        "FLEET_PROJECT": context["project"],
        "FLEET_INSTANCE": context["instance"],
        "FLEET_BRANCH": context["branch"],
        "FLEET_INSTANCE_FQDN": context["instance-fqdn"],
        "FLEET_DOMAIN": context["fleet-domain"],
    }
