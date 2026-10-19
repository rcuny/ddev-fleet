#!/usr/bin/env bash
# Print the current test count of every suite (PHPUnit x4, Playwright, pytest).
# The counts are generated, never written into tests/README-tests.md (FLE-65).
# Run from anywhere. A suite whose tool is not installed prints "n/a".
set -u
root=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)
php=$(command -v php8.4 || command -v php)

row() { printf '%-22s %s\n' "$1" "$2"; }

for suite in Unit Integration Functional Repo; do
    n=$(cd "$root/app" && "$php" vendor/bin/phpunit --testsuite "$suite" --list-tests 2>/dev/null | grep -c '^ - ')
    row "PHPUnit $suite" "${n:-n/a}"
done

pw=$(cd "$root/tests/playwright" && npx playwright test --list 2>/dev/null | tail -1)
row "Playwright" "${pw:-n/a}"

py="$root/.venv/bin/pytest"
[ -x "$py" ] || py=$(command -v pytest || true)
if [ -n "$py" ]; then
    row "pytest" "$("$py" --collect-only -q "$root/tests/pytest" 2>/dev/null | tail -1)"
else
    row "pytest" "n/a"
fi
