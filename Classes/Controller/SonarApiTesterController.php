<?php

declare(strict_types=1);

namespace Dirnbauer\ApiCapabilityBridge\Controller;

use Dirnbauer\ApiCapabilityBridge\Service\CapabilityResourceRegistrar;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Attribute\AsController;
use TYPO3\CMS\Backend\Template\Enum\ModuleLayout;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Page\PageRenderer;

#[AsController]
final class SonarApiTesterController
{
    public function __construct(
        private readonly ModuleTemplateFactory $moduleTemplateFactory,
        private readonly CapabilityResourceRegistrar $resourceRegistrar,
        private readonly PageRenderer $pageRenderer,
    ) {}

    public function handleRequest(ServerRequestInterface $request): ResponseInterface
    {
        $view = $this->moduleTemplateFactory->create($request);
        $view->setLayout(ModuleLayout::NORMAL);
        $view->setTitle('Sonar API Tester', 'Capability-gated CRUD examples');
        $view->setModuleClass('module-sonar-api-tester');

        $configuration = $this->resourceRegistrar->loadConfiguration();
        $resources = array_values(array_filter(
            (array)($configuration['api_resources'] ?? []),
            static fn (mixed $resource): bool => is_array($resource)
        ));
        $examples = $this->buildExamples($resources);

        $this->pageRenderer->loadJavaScriptModule('@dirnbauer/api-capability-bridge/sonar-api-tester.js');

        $view->assignMultiple([
            'policyFile' => CapabilityResourceRegistrar::POLICY_FILE,
            'resources' => $resources,
            'examplesJson' => json_encode($examples, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'defaultTenant' => 'camino',
        ]);

        return $view->renderResponse('Backend/SonarApiTester');
    }

    /**
     * @param array<int, array<string, mixed>> $resources
     * @return array<int, array<string, string>>
     */
    private function buildExamples(array $resources): array
    {
        $examples = [];
        foreach ($resources as $resource) {
            $api = (string)($resource['api'] ?? 'news');
            $version = (string)($resource['version'] ?? '1');
            $basePath = '/' . ltrim((string)($resource['basePath'] ?? ''), '/');
            $endpoint = '/api/' . $api . '/v' . $version . $basePath;
            $tag = (string)($resource['label'] ?? $resource['table'] ?? 'Resource');

            $examples[] = [
                'label' => $tag . ': list',
                'method' => 'GET',
                'url' => $endpoint . '?page=1&limit=10&sort=-uid',
                'body' => '',
            ];
            $examples[] = [
                'label' => $tag . ': create news with image reference',
                'method' => 'POST',
                'url' => $endpoint,
                'body' => json_encode([
                    'pid' => 16,
                    'title' => 'API created news',
                    'teaser' => 'Created from Sonar API Tester.',
                    'bodytext' => '<p>Full news body from the TYPO3 CRUD API.</p>',
                    'datetime' => time(),
                    'hidden' => 0,
                    'fal_media' => [
                        [
                            'uid_local' => 1,
                            'title' => 'Example image',
                            'alternative' => 'Example image',
                        ],
                    ],
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '',
            ];
            $examples[] = [
                'label' => $tag . ': update',
                'method' => 'PATCH',
                'url' => $endpoint . '/1',
                'body' => json_encode([
                    'title' => 'Updated by API',
                    'teaser' => 'Updated teaser text.',
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '',
            ];
            $examples[] = [
                'label' => $tag . ': delete',
                'method' => 'DELETE',
                'url' => $endpoint . '/1',
                'body' => '',
            ];
            $examples[] = [
                'label' => $tag . ': current BE-user token context',
                'method' => 'GET',
                'url' => '/api/' . $api . '/v' . $version . '/studio/me',
                'body' => '',
            ];
            $examples[] = [
                'label' => $tag . ': TCA form schema',
                'method' => 'GET',
                'url' => '/api/' . $api . '/v' . $version . '/studio/schema/news',
                'body' => '',
            ];
            $examples[] = [
                'label' => $tag . ': FAL file browser',
                'method' => 'GET',
                'url' => '/api/' . $api . '/v' . $version . '/studio/files',
                'body' => '',
            ];
            $examples[] = [
                'label' => $tag . ': submit workspace news',
                'method' => 'POST',
                'url' => '/api/' . $api . '/v' . $version . '/studio/news/1/submit?workspace=1',
                'body' => json_encode(['comment' => 'Submit from Sonar API Tester'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '',
            ];
            $examples[] = [
                'label' => $tag . ': publish workspace news',
                'method' => 'POST',
                'url' => '/api/' . $api . '/v' . $version . '/studio/news/1/publish?workspace=1',
                'body' => json_encode(['comment' => 'Publish from Sonar API Tester'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '',
            ];
        }

        return $examples;
    }
}
