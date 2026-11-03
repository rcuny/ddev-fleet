<?php

declare(strict_types=1);

// symfony-design-system:start

namespace App\Controller\Admin;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The design system reference page. Access is decided by the app's `^/admin` access rule.
 *
 * CATALOG drives the page: each group lists the section partials to include (under
 * templates/admin/design_system/sections/_<partial>.html.twig, in order) and the anchors
 * the sidebar links to.
 */
final class DesignSystemController extends AbstractController
{
    private const array CATALOG = [
        [
            'label' => 'Branding',
            'sections' => ['colors', 'typography'],
            'items' => [
                ['id' => 'colors', 'label' => 'Colors'],
                ['id' => 'typography', 'label' => 'Typography'],
            ],
        ],
        [
            'label' => 'Structure',
            'sections' => ['shells', 'main-navigation', 'sub-navigation', 'page-headers', 'body-content', 'footers'],
            'items' => [
                ['id' => 'shells', 'label' => 'Shells'],
                ['id' => 'main-navigation', 'label' => 'Main navigation'],
                ['id' => 'sub-navigation', 'label' => 'Sub navigation'],
                ['id' => 'page-headers', 'label' => 'Page headers'],
                ['id' => 'body-content', 'label' => 'Body content'],
                ['id' => 'footers', 'label' => 'Footers'],
            ],
        ],
        [
            'label' => 'Elements',
            'sections' => ['iconography', 'buttons', 'forms', 'badges', 'listings', 'modal', 'dropdown-menu', 'callout'],
            'items' => [
                ['id' => 'iconography', 'label' => 'Iconography'],
                ['id' => 'buttons', 'label' => 'Buttons'],
                ['id' => 'forms', 'label' => 'Forms'],
                ['id' => 'badges', 'label' => 'Badges'],
                ['id' => 'listings', 'label' => 'Listings'],
                ['id' => 'modal', 'label' => 'Modal'],
                ['id' => 'dropdown-menu', 'label' => 'Dropdown menu'],
                ['id' => 'callout', 'label' => 'Callout'],
            ],
        ],
        [
            'label' => 'Base styles',
            'sections' => ['base-styles'],
            'items' => [
                ['id' => 'heading-scale', 'label' => 'Heading scale'],
                ['id' => 'h1', 'label' => 'H1'],
                ['id' => 'h2', 'label' => 'H2'],
                ['id' => 'h3', 'label' => 'H3'],
                ['id' => 'h4', 'label' => 'H4'],
                ['id' => 'h5', 'label' => 'H5'],
                ['id' => 'h6', 'label' => 'H6'],
                ['id' => 'anchor', 'label' => 'Anchor (a)'],
                ['id' => 'paragraph', 'label' => 'Paragraph (p)'],
                ['id' => 'strong', 'label' => 'Strong'],
                ['id' => 'lists', 'label' => 'Lists (ul / ol)'],
                ['id' => 'list-item', 'label' => 'List item (li)'],
                ['id' => 'blockquote', 'label' => 'Blockquote'],
                ['id' => 'label', 'label' => 'Label / legend'],
                ['id' => 'hr', 'label' => 'Horizontal rule'],
            ],
        ],
    ];

    #[Route('/admin/design-system', name: 'admin_design_system', methods: ['GET'])]
    public function __invoke(): Response
    {
        return $this->render('admin/design_system/index.html.twig', ['groups' => self::CATALOG]);
    }
}

// symfony-design-system:end
