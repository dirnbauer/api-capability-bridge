<?php

declare(strict_types=1);

use TYPO3\CMS\Core\Imaging\IconProvider\SvgIconProvider;

return [
    'api-capability-bridge-module' => [
        'provider' => SvgIconProvider::class,
        'source' => 'EXT:api_capability_bridge/Resources/Public/Icons/module-api.svg',
    ],
];
