<?php

return [
    'id' => 2,
    'name' => 'Page',
    'objectType' => 'page',
    'locale' => 'de_DE',
    'url' => '/page.php',
    'version' => '10',
    'base' => [
        'title' => 'Alt',
        'keep' => 'x',
        'removedField' => 'gone',
        // Full double precision; channelB holds the same point truncated to 8
        // decimals, which differs by ~6.1e-9 (see the float precision tests).
        'focalpoint' => ['x' => 0.49298245614035086],
    ],
];
