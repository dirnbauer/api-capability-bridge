<?php

declare(strict_types=1);

use Dirnbauer\ApiCapabilityBridge\Controller\SonarApiTesterController;

return [
    'tools_sonarApiTester' => [
        'parent' => 'tools',
        'position' => ['after' => '*'],
        'access' => 'admin',
        'workspaces' => 'live',
        'path' => '/module/tools/sonar-api-tester',
        'iconIdentifier' => 'api-capability-bridge-module',
        'labels' => 'LLL:EXT:api_capability_bridge/Resources/Private/Language/locallang_module.xlf',
        'routes' => [
            '_default' => [
                'target' => SonarApiTesterController::class . '::handleRequest',
            ],
        ],
    ],
];
