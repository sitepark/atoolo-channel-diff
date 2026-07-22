<?php

declare(strict_types=1);

$finder = (new \PhpCsFixer\Finder())
    ->in(__DIR__ . '/src')
    ->in(__DIR__ . '/tests')
    ->exclude('resources');

return (new \PhpCsFixer\Config())
    ->setCacheFile('var/cache/.php-cs-fixer.cache')
    ->setFinder($finder)
    ->setRules(['@PER-CS' => true]);
