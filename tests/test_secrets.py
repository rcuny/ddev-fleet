import stat

from fleet.core.secrets import read_secrets, secret_tokens, write_secret


def test_read_secrets_missing_file_returns_empty_dict(tmp_path):
    assert read_secrets(tmp_path / "does-not-exist") == {}


def test_write_then_read_round_trip(tmp_path):
    path = tmp_path / ".secrets"
    write_secret(path, "CLAUDE_CODE_OAUTH_TOKEN", "sk-ant-oat01-xyz")
    assert read_secrets(path) == {"CLAUDE_CODE_OAUTH_TOKEN": "sk-ant-oat01-xyz"}


def test_write_secret_sets_mode_0600(tmp_path):
    path = tmp_path / ".secrets"
    write_secret(path, "FOO", "bar")
    mode = stat.S_IMODE(path.stat().st_mode)
    assert mode == 0o600


def test_write_secret_upserts_existing_key(tmp_path):
    path = tmp_path / ".secrets"
    write_secret(path, "FOO", "bar")
    write_secret(path, "FOO", "baz")
    assert read_secrets(path) == {"FOO": "baz"}


def test_write_secret_preserves_other_keys(tmp_path):
    path = tmp_path / ".secrets"
    write_secret(path, "FOO", "bar")
    write_secret(path, "OTHER", "value")
    assert read_secrets(path) == {"FOO": "bar", "OTHER": "value"}


def test_read_secrets_ignores_blank_lines_and_comments(tmp_path):
    path = tmp_path / ".secrets"
    path.write_text("# a comment\n\nFOO=bar\n", encoding="utf-8")
    assert read_secrets(path) == {"FOO": "bar"}


def test_write_secret_upsert_keeps_mode_0600(tmp_path):
    path = tmp_path / ".secrets"
    write_secret(path, "A", "1")
    write_secret(path, "A", "2")  # upsert
    assert oct(path.stat().st_mode & 0o777) == "0o600"
    assert read_secrets(path) == {"A": "2"}


def test_secret_tokens_maps_key_to_dashed_lowercase_token():
    assert secret_tokens({"SLACK_BOT_TOKEN": "xoxb-1"}) == {"slack-bot-token": "xoxb-1"}


def test_secret_tokens_empty_dict_returns_empty_dict():
    assert secret_tokens({}) == {}


def test_secret_tokens_maps_multiple_keys_with_multi_word_underscores():
    secrets = {
        "SLACK_BOT_TOKEN": "xoxb-1",
        "SLACK_APP_TOKEN": "xapp-2",
        "SLACK_ASK_TIMEOUT_SECONDS": "600",
    }
    assert secret_tokens(secrets) == {
        "slack-bot-token": "xoxb-1",
        "slack-app-token": "xapp-2",
        "slack-ask-timeout-seconds": "600",
    }
