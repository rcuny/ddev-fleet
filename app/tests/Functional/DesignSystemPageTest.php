<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Twig\Environment;

final class DesignSystemPageTest extends WebTestCase
{
    private const array ANCHORS = [
        'colors', 'typography', 'shells', 'main-navigation', 'sub-navigation', 'page-headers', 'body-content', 'footers',
        'iconography', 'buttons', 'forms', 'badges', 'listings', 'modal', 'dropdown-menu', 'callout',
        'heading-scale', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'anchor', 'paragraph', 'strong', 'lists', 'list-item',
        'blockquote', 'hr', 'label',
    ];

    private const array TOKENS = [
        'page', 'surface', 'hairline', 'ink-body', 'ink-display', 'ink-muted',
        'brand', 'brand-faded', 'brand-darker',
        'signal', 'signal-faded', 'signal-darker',
        'danger', 'danger-faded', 'danger-darker',
    ];

    private const array COMPONENTS = [
        'Button', 'Input', 'Label', 'Badge', 'Card', 'Alert', 'Checkbox', 'NativeSelect', 'RadioGroup',
        'Dialog', 'DropdownMenu', 'Sheet', 'Separator', 'Table', 'ThemeToggle', 'PageHeader',
    ];

    public function testRouteResolvesToThePage(): void
    {
        $client = self::createClient();
        $router = self::getContainer()->get('router');

        self::assertSame('/admin/design-system', $router->generate('admin_design_system'));
        self::assertSame('admin_design_system', $router->match('/admin/design-system')['_route']);
        // The Task 5 catch-all still answers every other /admin path.
        self::assertSame('admin_fallback', $router->match('/admin/nothing-here')['_route']);
    }

    public function testPageIsAdminOnly(): void
    {
        $client = self::createClient();

        $client->request('GET', '/admin/design-system', server: ['HTTP_X_FLEET_STAND_IN_ROLE' => 'none']);
        self::assertResponseStatusCodeSame(401);

        $client->request('GET', '/admin/design-system', server: ['HTTP_X_FLEET_STAND_IN_ROLE' => 'member']);
        self::assertResponseStatusCodeSame(403);

        $client->request('GET', '/admin/design-system', server: ['HTTP_X_FLEET_STAND_IN_ROLE' => 'admin']);
        self::assertResponseStatusCodeSame(200);
    }

    public function testEveryCanonicalAnchorExists(): void
    {
        $crawler = $this->adminPage();

        foreach (self::ANCHORS as $id) {
            self::assertCount(1, $crawler->filter('[id="'.$id.'"]'), \sprintf('Anchor #%s must exist exactly once.', $id));
        }
        self::assertCount(31, $crawler->filter('[data-ds-section]'));
    }

    public function testEveryTokenHasASwatch(): void
    {
        $crawler = $this->adminPage();

        foreach (self::TOKENS as $token) {
            self::assertGreaterThan(0, $crawler->filter('[data-token="'.$token.'"]')->count(), \sprintf('Token %s needs a swatch.', $token));
        }
        self::assertCount(15, $crawler->filter('[data-token]'));
    }

    public function testEveryInstalledComponentIsDocumented(): void
    {
        $crawler = $this->adminPage();

        foreach (self::COMPONENTS as $component) {
            self::assertGreaterThan(0, $crawler->filter('[data-component="'.$component.'"]')->count(), \sprintf('Component %s must be documented.', $component));
        }
    }

    public function testEverySampleCompilesAsTwig(): void
    {
        $crawler = $this->adminPage();
        /** @var Environment $twig */
        $twig = self::getContainer()->get('twig');

        $samples = $crawler->filter('pre[data-sample]');
        self::assertCount(31, $samples);

        foreach ($samples as $index => $node) {
            $source = $node->textContent;
            self::assertNotSame('', trim($source), 'Sample #'.$index.' is empty.');
            $html = $twig->createTemplate($source, 'sample-'.$index)->render();
            self::assertNotSame('', trim($html), 'Sample #'.$index.' rendered nothing.');
        }
    }

    private function adminPage(): Crawler
    {
        $client = self::createClient();

        return $client->request('GET', '/admin/design-system', server: ['HTTP_X_FLEET_STAND_IN_ROLE' => 'admin']);
    }
}
