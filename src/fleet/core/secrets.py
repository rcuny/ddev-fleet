"""Read/write $FLEET_HOME/.secrets (KEY=VALUE lines, mode 0600)."""

import os
from pathlib import Path


def read_secrets(path: Path) -> dict[str, str]:
    if not path.exists():
        return {}
    result: dict[str, str] = {}
    for line in path.read_text(encoding="utf-8").splitlines():
        stripped = line.strip()
        if not stripped or stripped.startswith("#") or "=" not in stripped:
            continue
        key, _, value = stripped.partition("=")
        result[key.strip()] = _unquote(value.strip())
    return result


def _unquote(value: str) -> str:
    """Strip one matching pair of surrounding quotes from a secret value.

    Secret values are substituted into asset files that usually quote the
    placeholder themselves (e.g. `TOKEN="[[some-token]]"`). A value stored
    as `"abc"` would then land as `""abc""`, which consumers that strip only
    one quote pair pass through to the API with a stray quote still attached
    — silently, since the file looks correct at a glance. Normalising on
    read makes a quoted secret file behave like a bare one.
    """
    if len(value) >= 2 and value[0] == value[-1] and value[0] in ("'", '"'):
        return value[1:-1]
    return value


def write_secret(path: Path, key: str, value: str) -> None:
    existing = read_secrets(path)
    existing[key] = value
    path.parent.mkdir(parents=True, exist_ok=True)
    lines = [f"{k}={v}" for k, v in existing.items()]
    path.write_text("\n".join(lines) + "\n", encoding="utf-8")
    os.chmod(path, 0o600)


def secret_tokens(secrets: dict[str, str]) -> dict[str, str]:
    """Map secret KEY=VALUE entries to [[token]] names: SLACK_BOT_TOKEN -> slack-bot-token."""
    return {key.lower().replace("_", "-"): value for key, value in secrets.items()}
