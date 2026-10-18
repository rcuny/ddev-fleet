<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ui;

use PHPUnit\Framework\TestCase;

/**
 * Pins the design tokens and the shadcn bridge in assets/styles/design-system.css.
 */
final class TokensCssTest extends TestCase
{
    /** token => [light, dark]; every token except danger-faded, which is derived (see testDangerFadedIsTheMix). */
    private const array PINNED = [
        'page' => ['#ffffff', '#020617'],
        'surface' => ['#f8fafc', '#0f172a'],
        'hairline' => ['#e2e8f0', '#1e293b'],
        'ink-body' => ['#334155', '#e2e8f0'],
        'ink-display' => ['#0f172a', '#f8fafc'],
        'ink-muted' => ['#64748b', '#94a3b8'],
        'brand' => ['#0891b2', '#0891b2'],
        'brand-faded' => ['#e1f2f6', '#031f33'],
        'brand-darker' => ['#0e7490', '#0e7490'],
        'signal' => ['#fcd34d', '#fcd34d'],
        'signal-faded' => ['#fffaea', '#2f2b21'],
        'signal-darker' => ['#b45309', '#b45309'],
        'danger' => ['#dc2626', '#f87171'],
        'danger-darker' => ['#b91c1c', '#b91c1c'],
    ];

    public function testEveryTokenHasALightAndADarkValue(): void
    {
        $light = $this->light();
        $dark = $this->dark();

        foreach ([...array_keys(self::PINNED), 'danger-faded'] as $token) {
            self::assertArrayHasKey('--color-'.$token, $light, $token.' has no light value in @theme.');
            self::assertArrayHasKey('--color-'.$token, $dark, $token.' has no dark value in .dark.');
        }
    }

    public function testPinnedValues(): void
    {
        foreach (self::PINNED as $token => [$lightValue, $darkValue]) {
            self::assertSame($lightValue, $this->light()['--color-'.$token], $token.' (light)');
            self::assertSame($darkValue, $this->dark()['--color-'.$token], $token.' (dark)');
        }
    }

    public function testDangerFadedIsTheMix(): void
    {
        // danger mixed 12 % on white (light) and 18 % on the dark page (dark).
        self::assertSame(self::mix($this->light()['--color-danger'], $this->light()['--color-page'], 0.12), $this->light()['--color-danger-faded']);
        self::assertSame(self::mix($this->dark()['--color-danger'], $this->dark()['--color-page'], 0.18), $this->dark()['--color-danger-faded']);
    }

    public function testBridgeIsPerMode(): void
    {
        $light = $this->bridge('light');
        $dark = $this->bridge('dark');

        self::assertSame('var(--color-brand-darker)', $light['--primary']);
        self::assertSame('#ffffff', $light['--primary-foreground']);
        self::assertSame('var(--color-brand)', $dark['--primary']);
        self::assertSame('var(--color-page)', $dark['--primary-foreground']);
        self::assertSame('var(--color-danger-darker)', $light['--destructive']);
        self::assertSame('var(--color-danger)', $dark['--destructive']);
    }

    public function testBridgeDefinesEveryVariableTheKitReads(): void
    {
        $required = $this->kitVariables();
        self::assertNotEmpty($required);
        self::assertContains('--primary', $required);
        self::assertContains('--sidebar-ring', $required);
        self::assertContains('--radius', $required);

        foreach (['light', 'dark'] as $mode) {
            $bridge = $this->bridge($mode);
            foreach ($required as $variable) {
                self::assertArrayHasKey($variable, $bridge, \sprintf('The %s bridge does not define %s.', $mode, $variable));
            }
        }
    }

    public function testBridgeValuesNeverUseTheKitDefaults(): void
    {
        foreach (['light', 'dark'] as $mode) {
            foreach ($this->bridge($mode) as $variable => $value) {
                self::assertStringNotContainsString('oklch(', $value, $variable.' ('.$mode.') still has a kit default.');
            }
        }
    }

    public function testBackgroundIsDefinedOnlyInTheDesignSystem(): void
    {
        $app = $this->read('assets/styles/app.css');
        $ds = $this->read('assets/styles/design-system.css');

        self::assertSame(0, preg_match_all('/--background\s*:/', $app), 'app.css must not define --background (kit default removed).');
        self::assertSame(1, preg_match_all('/--background\s*:/', $ds));
        self::assertSame(1, substr_count($app, "@import './design-system.css';"));
        self::assertSame(1, preg_match_all('/@custom-variant dark/', $app.$ds), 'The dark variant must exist once.');
    }

    public function testStylesheetUsesNoWebFontsOrThirdParties(): void
    {
        $ds = $this->read('assets/styles/design-system.css');

        self::assertStringNotContainsString('@font-face', $ds);
        self::assertStringNotContainsString('http', $ds);
    }

    public function testMarkersAppearOnce(): void
    {
        $ds = $this->read('assets/styles/design-system.css');

        self::assertSame(1, substr_count($ds, '/* symfony-design-system:start */'));
        self::assertSame(1, substr_count($ds, '/* symfony-design-system:end */'));
    }

    /**
     * @return array<string, string> variable => value in the light cascade (@theme, then :root)
     */
    private function light(): array
    {
        return $this->cascade(static fn (array $selectors): bool => [] !== array_intersect($selectors, ['@theme', ':root']));
    }

    /**
     * @return array<string, string> variable => value in the .dark cascade
     */
    private function dark(): array
    {
        return $this->cascade(static fn (array $selectors): bool => \in_array('.dark', $selectors, true));
    }

    /**
     * @return array<string, string> the shadcn variables (no leading --color-) for one mode
     */
    private function bridge(string $mode): array
    {
        $all = 'light' === $mode ? $this->light() : $this->dark();

        return array_filter($all, static fn (string $name): bool => !str_starts_with($name, '--color-') && !str_starts_with($name, '--font-') && !str_starts_with($name, '--breakpoint-'), \ARRAY_FILTER_USE_KEY);
    }

    /**
     * @param callable(list<string>): bool $matches
     *
     * @return array<string, string>
     */
    private function cascade(callable $matches): array
    {
        $css = (string) preg_replace('~/\*.*?\*/~s', '', $this->read('assets/styles/design-system.css'));
        $values = [];

        // Flat rule blocks only; the @layer wrappers hold no variables.
        preg_match_all('/([^{}]+)\{([^{}]*)\}/', $css, $blocks, \PREG_SET_ORDER);
        foreach ($blocks as [, $selectorList, $body]) {
            // A preceding at-rule statement (@import ...;) shares the match; the selector follows the last ";".
            $selector = trim(substr($selectorList, (int) strrpos(';'.$selectorList, ';')));
            $selectors = array_map(trim(...), explode(',', $selector));
            if (!$matches($selectors)) {
                continue;
            }
            preg_match_all('/(--[a-z0-9-]+)\s*:\s*([^;]+);/', $body, $declarations, \PREG_SET_ORDER);
            foreach ($declarations as [, $name, $value]) {
                $values[$name] = trim($value);
            }
        }

        return $values;
    }

    /**
     * The variables the installed kit's own stylesheet defines or reads through var(--x) in @theme inline.
     *
     * @return list<string>
     */
    private function kitVariables(): array
    {
        $kit = $this->read('vendor/symfony/ux-toolkit/kits/shadcn/kit.css');
        $variables = [];

        // Defined in :root / .dark by the kit.
        preg_match_all('/^\s{4}(--[a-z0-9-]+)\s*:/m', (string) preg_replace('/@theme inline \{.*?\n\}/s', '', $kit), $defined);
        // Read by the kit's @theme inline mapping.
        preg_match('/@theme inline \{(.*?)\n\}/s', $kit, $theme);
        preg_match_all('/var\((--[a-z0-9-]+)\)/', $theme[1] ?? '', $read);

        foreach ([...$defined[1], ...$read[1]] as $variable) {
            $variables[$variable] = true;
        }
        $names = array_keys($variables);
        sort($names);

        return $names;
    }

    private function read(string $path): string
    {
        $file = \dirname(__DIR__, 3).'/'.$path;
        self::assertFileExists($file);

        return (string) file_get_contents($file);
    }

    private static function mix(string $foreground, string $background, float $proportion): string
    {
        $channels = static function (string $hex): array {
            self::assertMatchesRegularExpression('/^#[0-9a-f]{6}$/', $hex);

            return [hexdec(substr($hex, 1, 2)), hexdec(substr($hex, 3, 2)), hexdec(substr($hex, 5, 2))];
        };
        [$fr, $fg, $fb] = $channels($foreground);
        [$br, $bg, $bb] = $channels($background);
        $blend = static fn (int|float $f, int|float $b): int => (int) round($f * $proportion + $b * (1 - $proportion));

        return \sprintf('#%02x%02x%02x', $blend($fr, $br), $blend($fg, $bg), $blend($fb, $bb));
    }
}
