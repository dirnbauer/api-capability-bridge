<?php

declare(strict_types=1);

namespace Dirnbauer\ApiCapabilityBridge\Service;

use SGalinski\SgApiCore\Service\ResourceRegistry;
use SGalinski\SgApiCore\Service\ApiRegistry;
use Symfony\Component\Yaml\Yaml;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Package\PackageManager;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use Webconsulting\CapabilityManifest\Manifest\ManifestLoader;
use Webconsulting\CapabilityManifest\Policy\PolicyChecker;
use Webconsulting\CapabilityManifest\Report\AuditReport;

final class CapabilityResourceRegistrar
{
    public const POLICY_FILE = 'config/capability-policy.yaml';

    public function __construct(
        private readonly PackageManager $packageManager,
        private readonly ManifestLoader $manifestLoader,
    ) {}

    public function registerConfiguredResources(): void
    {
        if (!class_exists(ResourceRegistry::class)) {
            return;
        }

        $configuration = $this->loadConfiguration();
        if (class_exists(ApiRegistry::class)) {
            foreach (($configuration['api_definitions'] ?? []) as $apiDefinition) {
                if (!is_array($apiDefinition) || !isset($apiDefinition['id'])) {
                    continue;
                }

                GeneralUtility::makeInstance(ApiRegistry::class)->registerApi(
                    (string)$apiDefinition['id'],
                    array_map('strval', (array)($apiDefinition['versions'] ?? ['1'])),
                    (array)($apiDefinition['security'] ?? []),
                );
            }
        }

        foreach (($configuration['api_resources'] ?? []) as $resource) {
            if (!is_array($resource) || !$this->isResourceAllowed($resource)) {
                continue;
            }

            GeneralUtility::makeInstance(ResourceRegistry::class)->registerResource(
                (string)($resource['api'] ?? 'public'),
                (string)$resource['table'],
                (string)$resource['basePath'],
                (array)($resource['options'] ?? []),
            );
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function loadConfiguration(): array
    {
        $policyFile = Environment::getProjectPath() . '/' . self::POLICY_FILE;
        if (!is_file($policyFile)) {
            return [];
        }

        $configuration = Yaml::parseFile($policyFile);
        return is_array($configuration) ? $configuration : [];
    }

    /**
     * @param array<string, mixed> $resource
     */
    private function isResourceAllowed(array $resource): bool
    {
        $extensionKey = (string)($resource['extension'] ?? '');
        if ($extensionKey === '' || !isset($resource['table'], $resource['basePath'])) {
            return false;
        }

        try {
            $extensionPath = $this->packageManager->getPackage($extensionKey)->getPackagePath();
            $manifestFile = (string)($resource['manifest'] ?? '');
            if ($manifestFile !== '') {
                $manifestPath = Environment::getProjectPath() . '/' . ltrim($manifestFile, '/');
                $manifest = $this->manifestLoader->loadFromFile($manifestPath, $extensionKey);
            } elseif ($this->manifestLoader->manifestExists($extensionPath)) {
                $manifest = $this->manifestLoader->loadFromExtension($extensionPath, $extensionKey);
            } else {
                return !$this->requiresManifest();
            }

            $report = new AuditReport();
            $report->extensionKey = $extensionKey;
            $report->hasManifest = true;
            $report->riskScore = $this->riskScore((string)($manifest->risk['level'] ?? 'low'));

            return PolicyChecker::fromFile(Environment::getProjectPath() . '/' . self::POLICY_FILE)
                ->check($report, $manifest) === [];
        } catch (\Throwable) {
            return false;
        }
    }

    private function requiresManifest(): bool
    {
        $configuration = $this->loadConfiguration();
        return (bool)($configuration['policy']['require_manifest'] ?? false);
    }

    private function riskScore(string $riskLevel): int
    {
        return match (strtolower($riskLevel)) {
            'critical' => 15,
            'high' => 10,
            'medium' => 5,
            default => 0,
        };
    }
}
