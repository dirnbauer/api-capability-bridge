<?php

$EM_CONF[$_EXTKEY] = [
    'title' => 'API Capability Bridge',
    'description' => 'Registers sg_apicore CRUD resources from a capability policy.',
    'category' => 'be',
    'author' => 'Dirnbauer',
    'author_email' => 'office@webconsulting.at',
    'state' => 'alpha',
    'version' => '0.1.0',
    'constraints' => [
        'depends' => [
            'typo3' => '14.3.0-14.9.99',
            'sg_apicore' => '1.20.0-1.20.99',
            'capability_manifest' => '0.0.0-999.999.999',
        ],
    ],
];
