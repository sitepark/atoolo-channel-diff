<?php

// Identical in both channels except for the volatile "version" field.
return [
    'id' => 1,
    'name' => 'Index',
    'objectType' => 'page',
    'locale' => 'de_DE',
    'url' => '/index.php',
    'version' => '1000',
    'base' => [
        'title' => 'Home',
        'nested' => ['a' => 1, 'b' => 2],
    ],
    // Identical closure in both channels -> must compare equal by source.
    'render' => static function (): string {
        return 'hello';
    },
];
