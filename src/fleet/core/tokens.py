"""The [[token]] substitution engine (spec §7.3)."""

import re
from pathlib import Path

from fleet.core.errors import TokenError

_TOKEN_RE = re.compile(r"\[\[([a-z0-9-]+)\]\]")
_MAX_SUBSTITUTE_BYTES = 1024 * 1024  # 1 MiB
_SNIFF_BYTES = 8192
_ISSUE_ID_CHARSET_RE = re.compile(r"^[A-Za-z0-9._/-]+$")


def build_context(
    project: str,
    instance: str,
    branch: str,
    domain: str,
    *,
    issue_id: str | None = None,
) -> dict[str, str]:
    instance_id_value = f"{project}--{instance}"
    context = {
        "instance-id": instance_id_value,
        "project": project,
        "instance": instance,
        "branch": branch,
        "fleet-domain": domain,
        "instance-fqdn": f"{instance_id_value}.{domain}",
    }
    if issue_id is not None:
        context["issue-id"] = issue_id
    return context


def _issue_id_from_match(match: "re.Match[str]") -> str | None:
    """Group 1 when the operator's pattern defines a capture group, else the
    whole match — so both `OAKS-[0-9]+` and `feature/(OAKS-[0-9]+)-` behave
    as the author intends. The value is uppercased before the charset check
    (uppercasing can't introduce an unsafe character, so order doesn't
    affect the outcome): issue keys are conventionally uppercase, and this
    gives one canonical form regardless of whether the id came from a
    lowercase label or a mixed-case branch — see `extract_issue_id` for why
    the label is lowercase in the first place. Rejects (returns None)
    anything outside the shell-safe charset: the value ends up typed into a
    tmux pane, so a permissive pattern must not become an injection
    vector."""
    if match.re.groups >= 1 and match.group(1) is not None:
        value = match.group(1)
    else:
        value = match.group(0)
    value = value.upper() if value else value
    if value and _ISSUE_ID_CHARSET_RE.match(value):
        return value
    return None


def extract_issue_id(pattern: str | None, label: str, branch: str) -> str | None:
    """Resolve `[[issue-id]]` from a project's `issue_id_regexp`: the
    instance label is tried first, the branch is the fallback (spec's
    `[[issue-id]]` resolution) — a deploy's label is operator-chosen and
    more specific than the branch name it may have defaulted from.

    Both candidates are matched case-insensitively, and the extracted value
    is always returned uppercased. Instance labels are DNS labels
    (`core/naming.py:validate_part` forces `^[a-z0-9]([a-z0-9-]*[a-z0-9])?$`,
    all lowercase), so a Jira-style label is `oaks-1781`, never `OAKS-1781`
    — an operator's natural `OAKS-[0-9]+` pattern would otherwise silently
    never fire on the label. Branches keep their original case. Matching
    case-insensitively and uppercasing the result gives one canonical
    `[[issue-id]]` regardless of which candidate matched or what case the
    operator wrote the pattern in.

    Returns None for no pattern, no match on either candidate, a match
    rejected by the shell-safe charset, or (defence in depth — the registry
    already validates patterns at load) an invalid regexp; never raises."""
    if not pattern:
        return None
    try:
        flags = re.IGNORECASE
        label_match = re.search(pattern, label, flags)
        if label_match is not None:
            issue_id = _issue_id_from_match(label_match)
            if issue_id is not None:
                return issue_id
        branch_match = re.search(pattern, branch, flags)
        if branch_match is not None:
            return _issue_id_from_match(branch_match)
    except re.error:
        return None
    return None


def _substitute(text: str, context: dict[str, str]) -> tuple[str, list[str]]:
    """Shared substitution pass backing both `substitute_text` (strict) and
    `substitute_lenient` (reporting): returns the substituted text plus the
    sorted, de-duplicated list of token names (without brackets) that
    stayed unresolved."""

    def _replace(match: "re.Match[str]") -> str:
        key = match.group(1)
        return context.get(key, match.group(0))

    result = _TOKEN_RE.sub(_replace, text)
    remaining = sorted(set(_TOKEN_RE.findall(result)))
    return result, remaining


def substitute_text(text: str, context: dict[str, str]) -> str:
    result, remaining = _substitute(text, context)
    if remaining:
        tokens = ", ".join(f"[[{name}]]" for name in remaining)
        raise TokenError(f"unresolved token(s): {tokens}")
    return result


def substitute_lenient(text: str, context: dict[str, str]) -> tuple[str, list[str]]:
    """Like `substitute_text`, but for tty commands (spec's "two deliberate
    paths" for unresolved tokens): reports what stayed unresolved instead of
    raising, so the caller can skip just that one command with a warning
    rather than aborting the whole deploy. Never raises."""
    return _substitute(text, context)


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
    env = {
        "FLEET_INSTANCE_ID": context["instance-id"],
        "FLEET_PROJECT": context["project"],
        "FLEET_INSTANCE": context["instance"],
        "FLEET_BRANCH": context["branch"],
        "FLEET_INSTANCE_FQDN": context["instance-fqdn"],
        "FLEET_DOMAIN": context["fleet-domain"],
    }
    if "issue-id" in context:
        env["FLEET_ISSUE_ID"] = context["issue-id"]
    return env
