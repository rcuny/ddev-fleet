<?php

declare(strict_types=1);

namespace App\Tests\Repo;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * bitbucket-pipelines.yml and renovate-config.json cannot be run here, so these tests pin their shape:
 * every gate is present, nothing is mirrored before the gates, nothing is ever force-pushed.
 */
final class PipelineConfigTest extends TestCase
{
    private const GATE_SNIPPETS = ['composer run gates', 'npm run typecheck', 'npx playwright test'];

    /** @var array<string, mixed> */
    private array $pipelines = [];

    protected function setUp(): void
    {
        $this->pipelines = Yaml::parseFile(self::root().'/bitbucket-pipelines.yml');
    }

    public function testGatesStepRunsEveryGate(): void
    {
        foreach ($this->flatSteps($this->pipelines['pipelines']['pull-requests']['**']) as $step) {
            if (str_starts_with((string) $step['name'], 'Gates')) {
                $script = $this->script($step);
                foreach (self::GATE_SNIPPETS as $snippet) {
                    self::assertStringContainsString($snippet, $script);
                }
                self::assertStringContainsString('playwright install', $script);
                self::assertStringContainsString('umask 022', $script);

                return;
            }
        }
        self::fail('No step named "Gates ..." runs on pull requests.');
    }

    public function testGatesRunBeforeEveryMirrorStep(): void
    {
        $develop = $this->pipelines['pipelines']['branches']['develop'];
        $mirrorAt = $this->indexOfStep($develop, static fn (array $s): bool => str_contains(self::scriptOf($s), 'git push'));
        $gatesAt = $this->indexOfStep($develop, static fn (array $s): bool => str_starts_with((string) $s['name'], 'Gates'));
        self::assertNotNull($mirrorAt, 'develop must mirror to GitHub');
        self::assertNotNull($gatesAt, 'develop must run the gates');
        self::assertLessThan($mirrorAt, $gatesAt);
        self::assertSame(\count($develop) - 1, $mirrorAt, 'the mirror step is last on develop');

        $tag = $this->pipelines['pipelines']['tags']['v*'];
        $publishAt = $this->indexOfStep($tag, static fn (array $s): bool => str_contains(self::scriptOf($s), 'git push'));
        $gatesAt = $this->indexOfStep($tag, static fn (array $s): bool => str_starts_with((string) $s['name'], 'Gates'));
        self::assertNotNull($publishAt);
        self::assertNotNull($gatesAt);
        self::assertLessThan($publishAt, $gatesAt);
        self::assertSame(\count($tag) - 1, $publishAt, 'the publish step is last on tags');

        // No other trigger may push anywhere.
        foreach (['pull-requests', 'branches'] as $kind) {
            foreach ($this->pipelines['pipelines'][$kind] as $name => $items) {
                if ('develop' === $name) {
                    continue;
                }
                foreach ($this->flatSteps($items) as $step) {
                    self::assertStringNotContainsString('git push', $this->script($step), "$kind $name");
                }
            }
        }
    }

    public function testNoPushIsEverForcedOrPlusRefspec(): void
    {
        $texts = [
            'bitbucket-pipelines.yml' => (string) file_get_contents(self::root().'/bitbucket-pipelines.yml'),
            'ci/systemd-security-live.sh' => (string) file_get_contents(self::root().'/ci/systemd-security-live.sh'),
        ];
        $pushLines = 0;
        foreach ($texts as $file => $text) {
            foreach (explode("\n", $text) as $line) {
                if (!str_contains($line, 'git push') || str_starts_with(ltrim($line), '#')) {
                    continue;
                }
                ++$pushLines;
                self::assertDoesNotMatchRegularExpression('/--force|--mirror|--delete|\s-f(\s|$)|[\s:"]\+\S*refs/', $line, "$file: $line");
            }
        }
        self::assertGreaterThan(0, $pushLines);
    }

    public function testTagPublishUsesTheGuard(): void
    {
        $tag = $this->pipelines['pipelines']['tags']['v*'];
        $script = $this->script($this->lastStep($tag));

        self::assertStringContainsString('ci/tag-guard.sh', $script);
        self::assertStringContainsString('"$BITBUCKET_TAG" "$BITBUCKET_COMMIT"', $script);
        self::assertStringContainsString('origin/develop', $script);
        self::assertStringContainsString('origin/main', $script);
        self::assertLessThan(
            strpos($script, 'git push'),
            strpos($script, 'ci/tag-guard.sh'),
            'the guard runs before the push',
        );
    }

    public function testFeatureAndReleaseBranchesRunGates(): void
    {
        foreach (['feature/**', 'release/**'] as $pattern) {
            self::assertArrayHasKey($pattern, $this->pipelines['pipelines']['branches']);
            $names = array_map(static fn (array $s): string => (string) $s['name'], $this->flatSteps($this->pipelines['pipelines']['branches'][$pattern]));
            self::assertNotEmpty(array_filter($names, static fn (string $n): bool => str_starts_with($n, 'Gates')), $pattern);
        }
    }

    public function testGatesImageIsDebianTrixie(): void
    {
        self::assertStringStartsWith('debian:trixie', (string) $this->pipelines['image']);
        foreach ($this->flatSteps($this->pipelines['pipelines']['pull-requests']['**']) as $step) {
            if (str_starts_with((string) $step['name'], 'Gates')) {
                self::assertStringStartsWith('debian:trixie', (string) ($step['image'] ?? $this->pipelines['image']));
            }
        }
    }

    public function testGatesInstallPhp84AndNode22(): void
    {
        foreach ($this->flatSteps($this->pipelines['pipelines']['pull-requests']['**']) as $step) {
            if (!str_starts_with((string) $step['name'], 'Gates')) {
                continue;
            }
            $script = $this->script($step);
            foreach (['php8.4-cli', 'php8.4-sqlite3', 'php8.4-intl', 'composer', 'git'] as $package) {
                self::assertStringContainsString($package, $script);
            }
            // @axe-core/playwright needs Node >= 22.12; Debian 13 ships Node 20.
            self::assertMatchesRegularExpression('/setup_22\.x|n 22/', $script);
            self::assertStringContainsString('node -v', $script);
        }
    }

    public function testInfraTestsStepKeepsThePythonInfrastructureChecks(): void
    {
        $steps = $this->flatSteps($this->pipelines['pipelines']['pull-requests']['**']);
        $infra = array_values(array_filter($steps, static fn (array $s): bool => str_starts_with((string) $s['name'], 'Infra')));
        self::assertCount(1, $infra);
        $script = $this->script($infra[0]);
        self::assertStringStartsWith('python:3.11', (string) $infra[0]['image']);
        self::assertStringContainsString('pip install -q -r ci/requirements-dev.txt', $script);
        self::assertStringContainsString('FLEET_REQUIRE_SYSTEMD_ANALYZE=1', $script);
        self::assertStringContainsString('pytest -q tests/pytest', $script);
        self::assertStringContainsString('ci/systemd_security.py offline', $script);
        self::assertStringContainsString('ci/systemd_security.py compare', $script);
        self::assertContains('reports/**', $infra[0]['artifacts']);
    }

    public function testRenovateMergeStepUsesPython(): void
    {
        $steps = $this->flatSteps($this->pipelines['pipelines']['custom']['renovate-merge']);
        self::assertStringStartsWith('python:3', (string) $steps[0]['image']);
        self::assertStringContainsString('python3 scripts/renovate_merge.py', $this->script($steps[0]));
        self::assertFileExists(self::root().'/scripts/renovate_merge.py');
    }

    public function testCustomPipelinesDoNotNeedThePythonApp(): void
    {
        self::assertArrayHasKey('renovate', $this->pipelines['pipelines']['custom']);
        $live = $this->flatSteps($this->pipelines['pipelines']['custom']['systemd-security-live']);
        self::assertStringNotContainsString('pip install -e', $this->script($live[0]));
        self::assertStringContainsString('pip install -q jinja2 PyYAML', $this->script($live[0]));
        self::assertFileExists(self::root().'/ci/systemd-security-live.sh');
    }

    public function testPipelineHasNoStalePythonAppReferences(): void
    {
        $text = (string) file_get_contents(self::root().'/bitbucket-pipelines.yml');
        foreach (['pyproject', 'ruff', 'black', 'pip install -e', 'tests/node', 'FLEET_REQUIRE_PGP_TOOLS'] as $stale) {
            self::assertStringNotContainsString($stale, $text);
        }
    }

    public function testRenovateConfigIsValidJsonTargetsDevelopAndHasComposerAndNpmRules(): void
    {
        $config = $this->renovate();

        self::assertSame('bitbucket', $config['platform']);
        self::assertSame(['develop'], $config['baseBranchPatterns']);
        self::assertSame('FLE-10', $config['commitMessagePrefix']);

        $groups = [];
        foreach ($config['packageRules'] as $rule) {
            if (isset($rule['groupName'])) {
                $groups[$rule['groupName']] = $rule;
            }
        }
        foreach (['symfony', 'symfony ux', 'doctrine', 'php dev tooling', 'playwright', 'python ci tooling', 'ansible collections'] as $group) {
            self::assertArrayHasKey($group, $groups);
        }
        self::assertSame(['composer'], $groups['symfony']['matchManagers']);
        self::assertSame(['symfony/**'], $groups['symfony']['matchPackageNames']);
        self::assertFalse($groups['symfony']['automerge']);
        self::assertContains('symfony/ux-*', $groups['symfony ux']['matchPackageNames']);
        self::assertContains('symfony/stimulus-bundle', $groups['symfony ux']['matchPackageNames']);
        self::assertSame(['doctrine/**'], $groups['doctrine']['matchPackageNames']);
        self::assertSame(['npm'], $groups['playwright']['matchManagers']);
        self::assertContains('@playwright/test', $groups['playwright']['matchPackageNames']);
        self::assertContains('@axe-core/playwright', $groups['playwright']['matchPackageNames']);
        self::assertStringContainsString('companion', $groups['playwright']['description']);
        self::assertSame(['pip_requirements'], $groups['python ci tooling']['matchManagers']);

        // Symfony minor and major bumps never share a PR with patch bumps, and none is merged by itself.
        $symfonyGroups = array_filter(array_keys($groups), static fn (string $g): bool => str_starts_with($g, 'symfony') && 'symfony ux' !== $g);
        self::assertGreaterThanOrEqual(3, \count($symfonyGroups));

        $disabled = array_values(array_filter($config['packageRules'], static fn (array $r): bool => false === ($r['enabled'] ?? null)));
        $disabledText = json_encode($disabled, \JSON_THROW_ON_ERROR);
        self::assertStringContainsString('debian', $disabledText);
        self::assertStringContainsString('"php"', $disabledText);
    }

    public function testRenovateConfigHasNoPythonAppOrVendoredAssetRules(): void
    {
        $config = $this->renovate();

        self::assertArrayNotHasKey('customManagers', $config);
        $text = (string) file_get_contents(self::root().'/renovate-config.json');
        foreach (['pypi', 'pep621', 'poetry', 'pyproject', 'htmx', 'openpgp', 'vendored-assets', 'python runtime'] as $stale) {
            self::assertStringNotContainsString($stale, $text);
        }
        self::assertFileDoesNotExist(self::root().'/pyproject.toml');
    }

    public function testMirrorStepsCloneFullHistorySkipWithoutAMirrorUrlAndOnlyMirrorTheLatestCommit(): void
    {
        $develop = $this->lastStep($this->pipelines['pipelines']['branches']['develop']);
        $tag = $this->lastStep($this->pipelines['pipelines']['tags']['v*']);
        foreach ([$develop, $tag] as $mirror) {
            self::assertSame('full', $mirror['clone']['depth']);
            self::assertStringContainsString('if [ -z "${GITHUB_MIRROR_URL:-}" ]', $this->script($mirror));
        }
        $script = $this->script($develop);
        self::assertStringContainsString('git rev-parse origin/develop)" != "$BITBUCKET_COMMIT"', $script);
        self::assertLessThan(strpos($script, 'git push'), strpos($script, 'rev-parse origin/develop'));
    }

    public function testTheReportCommitCannotLoopBackIntoTheLiveCheck(): void
    {
        // The live check commits SYSTEMD-SECURITY-REPORT.md to develop without [skip ci]: no push pipeline may run it.
        foreach (['branches', 'tags', 'pull-requests'] as $kind) {
            self::assertStringNotContainsString('systemd-security-live', json_encode($this->pipelines['pipelines'][$kind], \JSON_THROW_ON_ERROR));
        }
    }

    public function testAnsibleLintIsNonBlockingAndNotPartOfReleases(): void
    {
        $lint = null;
        foreach ($this->flatSteps($this->pipelines['pipelines']['branches']['develop']) as $step) {
            if (str_starts_with((string) $step['name'], 'Ansible lint')) {
                $lint = $step;
            }
        }
        self::assertNotNull($lint);
        self::assertContains('ansible-lint ansible/ || echo "ansible-lint reported findings (non-blocking)"', $lint['script']);
        self::assertStringContainsString('ci/requirements-infra.txt', $this->script($lint));
        foreach ($this->flatSteps($this->pipelines['pipelines']['tags']['v*']) as $step) {
            self::assertStringStartsNotWith('Ansible lint', (string) $step['name']);
        }
    }

    public function testRenovateMergeRunsRenovateOnlyWhenRequestedAndHoldsNoSecret(): void
    {
        [$merge, $after] = $this->flatSteps($this->pipelines['pipelines']['custom']['renovate-merge']);
        self::assertStringContainsString('--renovate-flag renovate-requested.flag', $this->script($merge));
        self::assertSame(['renovate-requested.flag'], $merge['artifacts']);
        self::assertStringStartsWith('renovate/renovate:', (string) $after['image']);
        self::assertStringContainsString('RENOVATE_REPOSITORIES="$BITBUCKET_REPO_FULL_NAME"', $this->script($after));

        $text = (string) file_get_contents(self::root().'/bitbucket-pipelines.yml');
        $section = substr($text, (int) strpos($text, "    renovate-merge:\n"));
        $section = substr($section, 0, (int) strpos($section, 'systemd-security-live:'));
        self::assertStringNotContainsString('RENOVATE_PASSWORD', $section);
        self::assertStringNotContainsString('Authorization', $section);

        $renovate = $this->flatSteps($this->pipelines['pipelines']['custom']['renovate']);
        self::assertStringStartsWith('renovate/renovate:', (string) $renovate[0]['image']);
        self::assertStringContainsString('renovate-config.json', $this->script($renovate[0]));
    }

    public function testRenovateKeepsItsStandingBehaviour(): void
    {
        $config = $this->renovate();

        self::assertFalse($config['onboarding']);
        self::assertSame('bump', $config['rangeStrategy']);
        self::assertSame('behind-base-branch', $config['rebaseWhen']);
        self::assertArrayNotHasKey('automerge', $config);
        self::assertMatchesRegularExpression('/^[A-Z][A-Z0-9]+-\d+$/', $config['commitMessagePrefix']);
    }

    public function testPlaywrightIsPinnedExactlyInItsOwnPackage(): void
    {
        $package = json_decode((string) file_get_contents(self::root().'/tests/playwright/package.json'), true, 512, \JSON_THROW_ON_ERROR);

        self::assertTrue($package['private']);
        self::assertMatchesRegularExpression('/^\d+\.\d+\.\d+$/', $package['devDependencies']['@playwright/test']);
        self::assertFileExists(self::root().'/tests/playwright/package-lock.json');
        self::assertFileDoesNotExist(self::root().'/.github/workflows/ci.yml');
    }

    private static function root(): string
    {
        return \dirname(__DIR__, 3);
    }

    /**
     * @return array<string, mixed>
     */
    private function renovate(): array
    {
        return json_decode((string) file_get_contents(self::root().'/renovate-config.json'), true, 512, \JSON_THROW_ON_ERROR);
    }

    /**
     * Steps of one trigger in execution order, parallel groups expanded in place.
     *
     * @param list<array<string, mixed>> $items
     *
     * @return list<array<string, mixed>>
     */
    private function flatSteps(array $items): array
    {
        $steps = [];
        foreach ($items as $item) {
            foreach ($item['parallel'] ?? [$item] as $entry) {
                $steps[] = $entry['step'];
            }
        }

        return $steps;
    }

    /**
     * @param list<array<string, mixed>> $items
     *
     * @return array<string, mixed>
     */
    private function lastStep(array $items): array
    {
        $steps = $this->flatSteps($items);

        return $steps[\count($steps) - 1];
    }

    /**
     * Position (among top-level items) of the first item holding a step that matches.
     *
     * @param list<array<string, mixed>>           $items
     * @param callable(array<string, mixed>): bool $matches
     */
    private function indexOfStep(array $items, callable $matches): ?int
    {
        foreach ($items as $index => $item) {
            foreach ($this->flatSteps([$item]) as $step) {
                if ($matches($step)) {
                    return $index;
                }
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $step
     */
    private function script(array $step): string
    {
        return self::scriptOf($step);
    }

    /**
     * @param array<string, mixed> $step
     */
    private static function scriptOf(array $step): string
    {
        return implode("\n", array_map('strval', $step['script']));
    }
}
