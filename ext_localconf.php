<?php

declare(strict_types=1);

use Dirnbauer\ApiCapabilityBridge\Service\CapabilityResourceRegistrar;
use TYPO3\CMS\Core\Utility\GeneralUtility;

defined('TYPO3') or die();

if (class_exists(CapabilityResourceRegistrar::class)) {
    GeneralUtility::makeInstance(CapabilityResourceRegistrar::class)->registerConfiguredResources();
}
