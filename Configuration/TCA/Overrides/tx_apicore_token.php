<?php

declare(strict_types=1);

defined('TYPO3') or die();

use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;

$GLOBALS['TCA']['tx_apicore_token']['columns']['be_user_uid'] = [
    'label' => 'LLL:EXT:api_capability_bridge/Resources/Private/Language/locallang_db.xlf:tx_apicore_token.be_user_uid',
    'description' => 'LLL:EXT:api_capability_bridge/Resources/Private/Language/locallang_db.xlf:tx_apicore_token.be_user_uid.description',
    'config' => [
        'type' => 'group',
        'allowed' => 'be_users',
        'size' => 1,
        'maxitems' => 1,
        'suggestOptions' => [
            'default' => [
                'additionalSearchFields' => 'username,realName,email',
                'addWhere' => ' AND be_users.deleted=0 AND be_users.disable=0',
            ],
        ],
        'default' => 0,
    ],
];

ExtensionManagementUtility::addToAllTCAtypes('tx_apicore_token', 'be_user_uid', '', 'after:user_id');
