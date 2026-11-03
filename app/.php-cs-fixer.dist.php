<?php

declare(strict_types=1);

$finder = (new PhpCsFixer\Finder())
    ->in(__DIR__)
    ->exclude(['var', 'vendor', 'assets/vendor'])
    ->notPath([
        'config/reference.php',
        'config/bundles.php',
        'importmap.php',
    ])
;

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(true)
    ->setRules([
        '@Symfony' => true,
        '@Symfony:risky' => true,
        '@PHP8x4Migration' => true,
        'declare_strict_types' => true,
        'ordered_imports' => true,
    ])
    ->setFinder($finder)
;
