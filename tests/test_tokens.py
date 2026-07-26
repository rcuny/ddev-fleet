import pytest

from fleet.core.errors import TokenError
from fleet.core.tokens import (
    build_context,
    env_vars,
    extract_issue_id,
    substitute_file,
    substitute_lenient,
    substitute_text,
)

ISSUE_ID_REGEXP = r"OAKS-[0-9]+"


def test_build_context_composes_fqdn():
    context = build_context("oak", "develop", "develop", "fleet.example.test")
    assert context == {
        "instance-id": "oak--develop",
        "project": "oak",
        "instance": "develop",
        "branch": "develop",
        "fleet-domain": "fleet.example.test",
        "instance-fqdn": "oak--develop.fleet.example.test",
    }


def test_substitute_text_replaces_known_tokens():
    context = build_context("oak", "develop", "develop", "fleet.example.test")
    text = "name: [[instance-id]]\nproject_tld: [[fleet-domain]]\n"
    result = substitute_text(text, context)
    assert result == "name: oak--develop\nproject_tld: fleet.example.test\n"


def test_substitute_text_raises_on_unresolved_token():
    context = build_context("oak", "develop", "develop", "fleet.example.test")
    with pytest.raises(TokenError) as exc_info:
        substitute_text("value=[[missing-token]]", context)
    assert "[[missing-token]]" in str(exc_info.value)


def test_substitute_file_skips_binary_content(tmp_path):
    path = tmp_path / "blob.bin"
    path.write_bytes(b"abc\x00def[[project]]")
    context = build_context("oak", "develop", "develop", "fleet.example.test")

    scanned = substitute_file(path, context)

    assert scanned is False
    assert path.read_bytes() == b"abc\x00def[[project]]"


def test_substitute_file_skips_large_files(tmp_path):
    path = tmp_path / "big.txt"
    with open(path, "wb") as fh:
        fh.truncate(1024 * 1024 + 1)  # size only — no real 1 MiB write
    context = build_context("oak", "develop", "develop", "fleet.example.test")

    scanned = substitute_file(path, context)

    assert scanned is False
    assert path.stat().st_size == 1024 * 1024 + 1


def test_substitute_file_rewrites_small_text_file(tmp_path):
    path = tmp_path / "config.yaml"
    path.write_text("name: [[instance-id]]\n", encoding="utf-8")
    context = build_context("oak", "develop", "develop", "fleet.example.test")

    scanned = substitute_file(path, context)

    assert scanned is True
    assert path.read_text(encoding="utf-8") == "name: oak--develop\n"


def test_substitute_file_raises_names_the_file(tmp_path):
    path = tmp_path / "config.yaml"
    path.write_text("name: [[missing-token]]\n", encoding="utf-8")
    context = build_context("oak", "develop", "develop", "fleet.example.test")

    with pytest.raises(TokenError) as exc_info:
        substitute_file(path, context)
    assert str(path) in str(exc_info.value)
    assert "[[missing-token]]" in str(exc_info.value)


def test_env_vars_maps_context_to_fleet_prefixed_names():
    context = build_context("oak", "develop", "develop", "fleet.example.test")
    assert env_vars(context) == {
        "FLEET_INSTANCE_ID": "oak--develop",
        "FLEET_PROJECT": "oak",
        "FLEET_INSTANCE": "develop",
        "FLEET_BRANCH": "develop",
        "FLEET_INSTANCE_FQDN": "oak--develop.fleet.example.test",
        "FLEET_DOMAIN": "fleet.example.test",
    }


def test_substitute_file_non_utf8_returns_false_unchanged(tmp_path):
    path = tmp_path / "latin1.txt"
    original = b"caf\xe9 [[project]]"  # invalid UTF-8, no null byte in the first 8 KiB
    path.write_bytes(original)
    context = build_context("demo", "develop", "main", "fleet.example.test")

    result = substitute_file(path, context)

    assert result is False
    assert path.read_bytes() == original


def test_substitute_file_exact_1mib_boundary_still_substitutes(tmp_path):
    path = tmp_path / "exact.txt"
    token_line = "PROJECT=[[project]]\n"
    filler = "x" * (1024 * 1024 - len(token_line))
    content = token_line + filler
    assert len(content.encode("utf-8")) == 1024 * 1024
    path.write_text(content, encoding="utf-8")
    context = build_context("demo", "develop", "main", "fleet.example.test")

    result = substitute_file(path, context)

    assert result is True
    rewritten = path.read_text(encoding="utf-8")
    assert rewritten.startswith("PROJECT=demo\n")


# --- extract_issue_id --------------------------------------------------


def test_extract_issue_id_label_wins_over_branch():
    """The label is tried first — a matching label always wins even when
    the branch also matches a *different* id."""
    issue_id = extract_issue_id(ISSUE_ID_REGEXP, "oaks-1781", "feature/OAKS-1999-x")
    assert issue_id == "OAKS-1781"


def test_extract_issue_id_matches_lowercase_dns_safe_label():
    """Labels are DNS labels (core/naming.py:validate_part), so they're
    always lowercase — a case-sensitive `OAKS-[0-9]+` would never fire on
    `oaks-1781`. Case-insensitive matching plus uppercasing the result is
    what makes the natural, mixed-case operator pattern actually match."""
    issue_id = extract_issue_id(ISSUE_ID_REGEXP, "oaks-1781", "develop")
    assert issue_id == "OAKS-1781"


def test_extract_issue_id_falls_back_to_branch():
    issue_id = extract_issue_id(ISSUE_ID_REGEXP, "test2", "feature/OAKS-1781-seo-geo-improvements")
    assert issue_id == "OAKS-1781"


def test_extract_issue_id_falls_back_to_branch_when_label_does_not_match():
    """Same pattern (no inline flag), label doesn't match at all — the
    branch fallback still resolves and is uppercased."""
    issue_id = extract_issue_id(
        ISSUE_ID_REGEXP, "unrelated-label", "feature/OAKS-1781-seo-geo-improvements"
    )
    assert issue_id == "OAKS-1781"


def test_extract_issue_id_already_uppercase_is_unchanged():
    """Idempotence: a branch that's already uppercase round-trips as-is."""
    issue_id = extract_issue_id(ISSUE_ID_REGEXP, "test2", "feature/OAKS-1781-x")
    assert issue_id == "OAKS-1781"


def test_extract_issue_id_no_match_returns_none():
    assert extract_issue_id(ISSUE_ID_REGEXP, "test2", "develop") is None


def test_extract_issue_id_no_pattern_returns_none():
    assert extract_issue_id(None, "OAKS-1781", "OAKS-1781") is None


def test_extract_issue_id_uses_capture_group_when_present():
    pattern = r"feature/(OAKS-[0-9]+)-"
    issue_id = extract_issue_id(pattern, "test2", "feature/OAKS-1781-seo-geo-improvements")
    assert issue_id == "OAKS-1781"


def test_extract_issue_id_capture_group_matches_lowercase_and_uppercases():
    """Capture-group pattern against a lowercase-in-the-relevant-part branch
    still returns group 1, uppercased."""
    pattern = r"feature/(oaks-[0-9]+)-"
    issue_id = extract_issue_id(pattern, "test2", "feature/OAKS-1781-seo-geo-improvements")
    assert issue_id == "OAKS-1781"


def test_extract_issue_id_inline_flag_pattern_not_double_flagged():
    """An operator pattern with its own inline `(?i)` must keep working —
    combining it with the externally-applied re.IGNORECASE must not raise
    or otherwise break the match."""
    pattern = r"(?i)OAKS-[0-9]+"
    issue_id = extract_issue_id(pattern, "oaks-1781", "develop")
    assert issue_id == "OAKS-1781"


def test_extract_issue_id_numeric_pattern_unaffected_by_uppercasing():
    """A purely numeric id has no case, so uppercasing is a no-op."""
    issue_id = extract_issue_id(r"[0-9]+", "build-4217", "develop")
    assert issue_id == "4217"


def test_extract_issue_id_rejects_shell_metacharacters():
    """A permissive operator pattern must not become an injection vector —
    a match outside the shell-safe charset is treated as no match. The
    branch is also rejected here so the fallback can't paper over it."""
    assert extract_issue_id(r".+", "a;rm -rf /", "also;bad") is None


def test_extract_issue_id_rejected_label_still_tries_branch():
    """A label match rejected by the charset check does not short-circuit —
    the branch is still tried as a fallback."""
    issue_id = extract_issue_id(r".+", "a;rm -rf /", "OAKS-1781")
    assert issue_id == "OAKS-1781"


def test_extract_issue_id_invalid_pattern_returns_none_not_raises():
    assert extract_issue_id("OAKS-[0-9", "OAKS-1781", "develop") is None


# --- build_context / env_vars with issue-id -----------------------------


def test_build_context_without_issue_id_has_no_issue_id_key():
    context = build_context("oak", "develop", "develop", "fleet.example.test")
    assert "issue-id" not in context


def test_build_context_with_issue_id_adds_key():
    context = build_context("oak", "develop", "develop", "fleet.example.test", issue_id="OAKS-1781")
    assert context["issue-id"] == "OAKS-1781"


def test_env_vars_omits_fleet_issue_id_when_absent():
    context = build_context("oak", "develop", "develop", "fleet.example.test")
    assert "FLEET_ISSUE_ID" not in env_vars(context)


def test_env_vars_includes_fleet_issue_id_when_present():
    context = build_context("oak", "develop", "develop", "fleet.example.test", issue_id="OAKS-1781")
    assert env_vars(context)["FLEET_ISSUE_ID"] == "OAKS-1781"


# --- substitute_lenient --------------------------------------------------


def test_substitute_lenient_leaves_unresolved_tokens_in_place():
    context = build_context("oak", "develop", "develop", "fleet.example.test")
    text = "ddev exec claude [[issue-id]] on [[project]]"

    result, unresolved = substitute_lenient(text, context)

    assert result == "ddev exec claude [[issue-id]] on oak"
    assert unresolved == ["issue-id"]


def test_substitute_lenient_reports_sorted_deduped_unresolved_names():
    context = build_context("oak", "develop", "develop", "fleet.example.test")
    text = "[[zeta-token]] [[issue-id]] [[issue-id]] [[alpha-token]]"

    _, unresolved = substitute_lenient(text, context)

    assert unresolved == ["alpha-token", "issue-id", "zeta-token"]


def test_substitute_lenient_resolves_everything_gives_empty_unresolved():
    context = build_context("oak", "develop", "develop", "fleet.example.test")
    result, unresolved = substitute_lenient("[[project]]", context)

    assert result == "oak"
    assert unresolved == []


def test_substitute_lenient_never_raises_while_substitute_text_still_does():
    """Contrast: substitute_text keeps its strict, raising behaviour on the
    exact same input that substitute_lenient tolerates."""
    context = build_context("oak", "develop", "develop", "fleet.example.test")
    text = "[[issue-id]]"

    # Does not raise.
    substitute_lenient(text, context)

    with pytest.raises(TokenError):
        substitute_text(text, context)
