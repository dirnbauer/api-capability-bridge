<?php

declare(strict_types=1);

namespace Dirnbauer\ApiCapabilityBridge\Controller;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;
use SGalinski\SgApiCore\Attribute\ApiEndpoint;
use SGalinski\SgApiCore\Attribute\ApiPathParam;
use SGalinski\SgApiCore\Attribute\ApiRoute;
use SGalinski\SgApiCore\Attribute\RequireScopes;
use SGalinski\SgApiCore\Service\ResponseService;
use TYPO3\CMS\Backend\Routing\PreviewUriBuilder;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\Database\Query\Restriction\RootLevelRestriction;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Resource\DefaultUploadFolderResolver;
use TYPO3\CMS\Core\Resource\Enum\DuplicationBehavior;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Resource\FileInterface;
use TYPO3\CMS\Core\Resource\Folder;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use TYPO3\CMS\Core\Type\Bitmask\Permission;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Workspaces\Service\StagesService;

final class NewsStudioController
{
    private const NEWS_TABLE = 'tx_news_domain_model_news';

    public function __construct(
        private readonly ConnectionPool $connectionPool,
        private readonly ResponseService $responseService,
        private readonly ResourceFactory $resourceFactory,
        private readonly DefaultUploadFolderResolver $defaultUploadFolderResolver,
    ) {}

    #[ApiRoute('/studio/me', ['GET'], apiId: 'news', version: '1', authMode: 'token')]
    #[ApiEndpoint(summary: 'Current backend API user', tags: ['News Studio'])]
    #[RequireScopes(['news:read'])]
    public function meAction(ServerRequestInterface $request): ResponseInterface
    {
        $backendUser = $this->getBackendUser();
        if (!$backendUser instanceof BackendUserAuthentication) {
            return $this->forbidden('Backend user context is missing.');
        }

        $authContext = $request->getAttribute('api.auth');
        return $this->responseService->createSuccessResponse([
            'user' => [
                'uid' => (int)$backendUser->user['uid'],
                'username' => (string)($backendUser->user['username'] ?? ''),
                'realName' => (string)($backendUser->user['realName'] ?? ''),
                'email' => (string)($backendUser->user['email'] ?? ''),
                'admin' => $backendUser->isAdmin(),
            ],
            'token' => [
                'uid' => $authContext?->getTokenUid(),
                'scopes' => $authContext?->getScopes() ?? [],
            ],
            'workspace' => $this->serializeCurrentWorkspace($backendUser),
            'workspaces' => $this->listAccessibleWorkspaces($backendUser),
            'permissions' => [
                'canReadNews' => $this->canSelectTable(self::NEWS_TABLE),
                'canWriteNews' => $this->canModifyTable(self::NEWS_TABLE),
                'canReadFiles' => in_array('readFile', GeneralUtility::trimExplode(',', (string)($backendUser->groupData['file_permissions'] ?? ''), true), true) || $backendUser->isAdmin(),
                'canWriteFiles' => in_array('addFile', GeneralUtility::trimExplode(',', (string)($backendUser->groupData['file_permissions'] ?? ''), true), true) || $backendUser->isAdmin(),
            ],
        ]);
    }

    #[ApiRoute('/studio/schema/news', ['GET'], apiId: 'news', version: '1', authMode: 'token')]
    #[ApiEndpoint(summary: 'TCA generated news form schema', tags: ['News Studio'])]
    #[RequireScopes(['news:read'])]
    public function newsSchemaAction(ServerRequestInterface $request): ResponseInterface
    {
        if (!$this->canSelectTable(self::NEWS_TABLE)) {
            return $this->forbidden('The backend user is not allowed to read news records.');
        }

        $schema = $this->buildTcaSchema(self::NEWS_TABLE);
        return $this->responseService->createSuccessResponse($schema, [
            'schemaHash' => sha1(json_encode($schema, JSON_THROW_ON_ERROR)),
        ]);
    }

    #[ApiRoute('/studio/records/{table}', ['GET'], apiId: 'news', version: '1', authMode: 'token')]
    #[ApiEndpoint(summary: 'Search records for relation pickers', tags: ['News Studio'])]
    #[ApiPathParam(name: 'table', type: 'string')]
    #[RequireScopes(['news:read'])]
    public function recordsAction(ServerRequestInterface $request, string $table): ResponseInterface
    {
        if (!$this->isSearchableTable($table) || !$this->canSelectTable($table)) {
            return $this->forbidden('The backend user is not allowed to search this table.');
        }

        $queryParams = $request->getQueryParams();
        $search = trim((string)($queryParams['q'] ?? ''));
        $limit = max(1, min(50, (int)($queryParams['limit'] ?? 25)));
        $labelField = $this->getLabelField($table);
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable($table);
        $queryBuilder
            ->select('*')
            ->from($table)
            ->setMaxResults($limit)
            ->orderBy($labelField ?: 'uid', 'ASC');

        if ($search !== '') {
            $searchFields = $this->getSearchFields($table);
            $orConstraints = [];
            foreach ($searchFields as $field) {
                $orConstraints[] = $queryBuilder->expr()->like(
                    $field,
                    $queryBuilder->createNamedParameter('%' . $queryBuilder->escapeLikeWildcards($search) . '%')
                );
            }
            if ($orConstraints !== []) {
                $queryBuilder->andWhere($queryBuilder->expr()->or(...$orConstraints));
            }
        }

        $items = [];
        foreach ($queryBuilder->executeQuery()->fetchAllAssociative() as $record) {
            $items[] = [
                'uid' => (int)$record['uid'],
                'pid' => (int)($record['pid'] ?? 0),
                'label' => $this->getRecordLabel($table, $record),
                'table' => $table,
            ];
        }

        return $this->responseService->createSuccessResponse($items);
    }

    #[ApiRoute('/studio/files', ['GET'], apiId: 'news', version: '1', authMode: 'token')]
    #[ApiEndpoint(summary: 'List accessible FAL files and folders', tags: ['News Studio'])]
    #[RequireScopes(['files:read'])]
    public function filesAction(ServerRequestInterface $request): ResponseInterface
    {
        $backendUser = $this->requireBackendUser();
        if ($backendUser instanceof ResponseInterface) {
            return $backendUser;
        }

        $folder = $this->resolveFolder((string)($request->getQueryParams()['folder'] ?? ''), $backendUser);
        if (!$folder instanceof Folder || !$folder->checkActionPermission('read')) {
            return $this->forbidden('The backend user is not allowed to read this folder.');
        }

        $folders = [];
        foreach ($folder->getSubfolders(0, 100) as $subfolder) {
            if (!$subfolder->checkActionPermission('read')) {
                continue;
            }
            $folders[] = $this->serializeFolder($subfolder);
        }

        $files = [];
        foreach ($folder->getFiles(0, 100, Folder::FILTER_MODE_USE_OWN_AND_STORAGE_FILTERS, false, 'name') as $file) {
            if (!$file->checkActionPermission('read')) {
                continue;
            }
            $files[] = $this->serializeFile($file);
        }

        return $this->responseService->createSuccessResponse([
            'current' => $this->serializeFolder($folder),
            'folders' => $folders,
            'files' => $files,
        ]);
    }

    #[ApiRoute('/studio/files/upload', ['POST'], apiId: 'news', version: '1', authMode: 'token')]
    #[ApiEndpoint(summary: 'Upload a file into an allowed FAL folder', tags: ['News Studio'])]
    #[RequireScopes(['files:write'])]
    public function uploadFileAction(ServerRequestInterface $request): ResponseInterface
    {
        $backendUser = $this->requireBackendUser();
        if ($backendUser instanceof ResponseInterface) {
            return $backendUser;
        }

        $folder = $this->resolveFolder((string)($request->getParsedBody()['folder'] ?? $request->getQueryParams()['folder'] ?? ''), $backendUser);
        if (!$folder instanceof Folder || !$folder->checkActionPermission('write')) {
            return $this->forbidden('The backend user is not allowed to upload to this folder.');
        }

        $uploadedFile = $request->getUploadedFiles()['file'] ?? null;
        if (!$uploadedFile instanceof UploadedFileInterface || $uploadedFile->getError() !== UPLOAD_ERR_OK) {
            return $this->badRequest('Upload field "file" is required.');
        }

        $file = $folder->addUploadedFile($uploadedFile, DuplicationBehavior::RENAME);
        return $this->responseService->createSuccessResponse($this->serializeFile($file), status: 201);
    }

    #[ApiRoute('/studio/workspaces', ['GET'], apiId: 'news', version: '1', authMode: 'token')]
    #[ApiEndpoint(summary: 'List accessible workspaces', tags: ['News Studio'])]
    #[RequireScopes(['workspace:read'])]
    public function workspacesAction(ServerRequestInterface $request): ResponseInterface
    {
        $backendUser = $this->requireBackendUser();
        if ($backendUser instanceof ResponseInterface) {
            return $backendUser;
        }

        return $this->responseService->createSuccessResponse([
            'current' => $this->serializeCurrentWorkspace($backendUser),
            'items' => $this->listAccessibleWorkspaces($backendUser),
        ]);
    }

    #[ApiRoute('/studio/workspaces/switch', ['POST'], apiId: 'news', version: '1', authMode: 'token')]
    #[ApiEndpoint(summary: 'Validate a workspace choice for stateless clients', tags: ['News Studio'])]
    #[RequireScopes(['workspace:read'])]
    public function switchWorkspaceAction(ServerRequestInterface $request): ResponseInterface
    {
        $backendUser = $this->requireBackendUser();
        if ($backendUser instanceof ResponseInterface) {
            return $backendUser;
        }

        $workspace = (int)($request->getParsedBody()['workspace'] ?? 0);
        if (!$backendUser->setTemporaryWorkspace($workspace)) {
            return $this->forbidden('The backend user has no access to this workspace.');
        }

        return $this->responseService->createSuccessResponse([
            'current' => $this->serializeCurrentWorkspace($backendUser),
        ]);
    }

    #[ApiRoute('/studio/news/{id}/submit', ['POST'], apiId: 'news', version: '1', authMode: 'token')]
    #[ApiEndpoint(summary: 'Move a workspace news record to the publish stage', tags: ['News Studio'])]
    #[ApiPathParam(name: 'id', type: 'integer')]
    #[RequireScopes(['news:write', 'workspace:read'])]
    public function submitNewsAction(ServerRequestInterface $request, int $id): ResponseInterface
    {
        if (!$this->canModifyTable(self::NEWS_TABLE)) {
            return $this->forbidden('The backend user is not allowed to modify news records.');
        }

        $version = $this->resolveWorkspaceVersion($id);
        if ($version === null) {
            return $this->badRequest('This news record has no workspace version to submit.');
        }

        $comment = (string)($request->getParsedBody()['comment'] ?? 'Submitted from TYPO3 News API Studio');
        $cmdMap = [
            self::NEWS_TABLE => [
                (int)$version['versionUid'] => [
                    'version' => [
                        'action' => 'setStage',
                        'stageId' => StagesService::STAGE_PUBLISH_ID,
                        'comment' => $comment,
                    ],
                ],
            ],
        ];

        $errors = $this->processCommandMap($cmdMap);
        if ($errors !== []) {
            return $this->dataHandlerError($errors);
        }

        return $this->responseService->createSuccessResponse([
            'uid' => (int)$version['versionUid'],
            'stage' => StagesService::STAGE_PUBLISH_ID,
        ]);
    }

    #[ApiRoute('/studio/news/{id}/publish', ['POST'], apiId: 'news', version: '1', authMode: 'token')]
    #[ApiEndpoint(summary: 'Publish a workspace news record', tags: ['News Studio'])]
    #[ApiPathParam(name: 'id', type: 'integer')]
    #[RequireScopes(['news:write', 'workspace:publish'])]
    public function publishNewsAction(ServerRequestInterface $request, int $id): ResponseInterface
    {
        if (!$this->canModifyTable(self::NEWS_TABLE)) {
            return $this->forbidden('The backend user is not allowed to modify news records.');
        }

        $version = $this->resolveWorkspaceVersion($id);
        if ($version === null) {
            return $this->badRequest('This news record has no workspace version to publish.');
        }

        $cmdMap = [
            self::NEWS_TABLE => [
                (int)$version['liveUid'] => [
                    'version' => [
                        'action' => 'publish',
                        'swapWith' => (int)$version['versionUid'],
                        'comment' => (string)($request->getParsedBody()['comment'] ?? 'Published from TYPO3 News API Studio'),
                    ],
                ],
            ],
        ];

        $errors = $this->processCommandMap($cmdMap);
        if ($errors !== []) {
            return $this->dataHandlerError($errors);
        }

        return $this->responseService->createSuccessResponse([
            'uid' => (int)$version['liveUid'],
            'published' => true,
        ]);
    }

    #[ApiRoute('/studio/news/{id}/preview', ['GET'], apiId: 'news', version: '1', authMode: 'token')]
    #[ApiEndpoint(summary: 'Build a TYPO3 preview URL for a news record', tags: ['News Studio'])]
    #[ApiPathParam(name: 'id', type: 'integer')]
    #[RequireScopes(['news:read'])]
    public function previewNewsAction(ServerRequestInterface $request, int $id): ResponseInterface
    {
        $record = BackendUtility::getRecord(self::NEWS_TABLE, $id, '*');
        if (!is_array($record)) {
            return $this->responseService->createErrorResponse('Not Found', 'News record not found.', 404);
        }

        $previewUri = PreviewUriBuilder::createForRecordPreview(self::NEWS_TABLE, $record, (int)$record['pid'])
            ->withModuleLoading(false)
            ->buildUri();

        return $this->responseService->createSuccessResponse([
            'available' => $previewUri !== null,
            'url' => $previewUri ? (string)$previewUri : null,
            'diagnostics' => $previewUri === null
                ? ['No preview URL could be generated. Check TCEMAIN.preview.tx_news_domain_model_news PageTSconfig and site routing.']
                : [],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function buildTcaSchema(string $table): array
    {
        $tca = $GLOBALS['TCA'][$table] ?? [];
        $columns = $tca['columns'] ?? [];
        $fields = [];
        foreach ($columns as $fieldName => $column) {
            if (!$this->isUsefulFormField($fieldName, $column)) {
                continue;
            }
            $fields[$fieldName] = $this->normalizeTcaField($table, $fieldName, $column);
        }

        $tabs = $this->parseShowitemLayout($table, $fields);
        $listedFields = [];
        foreach ($tabs as $tab) {
            foreach ($tab['fields'] as $fieldName) {
                $listedFields[$fieldName] = true;
            }
        }
        $additionalFields = array_values(array_diff(array_keys($fields), array_keys($listedFields)));
        if ($additionalFields !== []) {
            $tabs[] = [
                'id' => 'additional',
                'label' => 'Additional fields',
                'fields' => $additionalFields,
            ];
        }

        return [
            'table' => $table,
            'label' => $this->translate((string)($tca['ctrl']['title'] ?? $table)),
            'fields' => $fields,
            'tabs' => $tabs,
            'defaultValues' => [
                'pid' => 16,
                'hidden' => 0,
                'datetime' => time(),
            ],
        ];
    }

    /**
     * @param array<string, mixed> $column
     * @return array<string, mixed>
     */
    private function normalizeTcaField(string $table, string $fieldName, array $column): array
    {
        $config = (array)($column['config'] ?? []);
        $type = (string)($config['type'] ?? 'input');
        $renderType = (string)($config['renderType'] ?? '');
        $eval = (string)($config['eval'] ?? '');
        $foreignTable = (string)($config['foreign_table'] ?? '');

        return [
            'name' => $fieldName,
            'label' => $this->translate((string)($column['label'] ?? $fieldName)),
            'description' => $this->translate((string)($column['description'] ?? '')),
            'type' => $this->mapFieldType($type, $renderType, $config),
            'tcaType' => $type,
            'renderType' => $renderType,
            'required' => str_contains($eval, 'required'),
            'readOnly' => (bool)($config['readOnly'] ?? false) || $type === 'passthrough',
            'richtext' => $type === 'text' && ((bool)($config['enableRichtext'] ?? false) || !empty($config['richtextConfiguration'])),
            'writeable' => $this->canEditField($table, $fieldName, $column),
            'relation' => $foreignTable !== '' ? [
                'table' => $foreignTable,
                'multiple' => (int)($config['maxitems'] ?? 1) !== 1,
                'searchUrl' => '/studio/records/' . $foreignTable,
            ] : null,
            'items' => $this->normalizeSelectItems((array)($config['items'] ?? [])),
            'maxitems' => (int)($config['maxitems'] ?? 0),
            'allowed' => $config['allowed'] ?? null,
            'size' => $config['size'] ?? null,
            'rows' => $config['rows'] ?? null,
        ];
    }

    /**
     * @param array<string, mixed> $fields
     * @return list<array{id: string, label: string, fields: list<string>}>
     */
    private function parseShowitemLayout(string $table, array $fields): array
    {
        $tca = $GLOBALS['TCA'][$table] ?? [];
        $typeKey = array_key_first((array)($tca['types'] ?? [])) ?: '0';
        $showitem = (string)($tca['types'][$typeKey]['showitem'] ?? '');
        $tabs = [['id' => 'main', 'label' => 'Main', 'fields' => []]];

        foreach (GeneralUtility::trimExplode(',', $showitem, true) as $item) {
            $parts = GeneralUtility::trimExplode(';', $item, false);
            if (($parts[0] ?? '') === '--div--') {
                $label = $this->translate((string)($parts[1] ?? 'Tab'));
                $tabs[] = [
                    'id' => strtolower(preg_replace('/[^a-z0-9]+/i', '-', $label) ?: 'tab'),
                    'label' => $label,
                    'fields' => [],
                ];
                continue;
            }
            if (($parts[0] ?? '') === '--palette--') {
                $paletteName = (string)($parts[2] ?? '');
                $paletteShowitem = (string)($tca['palettes'][$paletteName]['showitem'] ?? '');
                foreach (GeneralUtility::trimExplode(',', $paletteShowitem, true) as $paletteItem) {
                    $fieldName = GeneralUtility::trimExplode(';', $paletteItem, false)[0] ?? '';
                    if (isset($fields[$fieldName])) {
                        $tabs[array_key_last($tabs)]['fields'][] = $fieldName;
                    }
                }
                continue;
            }

            $fieldName = (string)($parts[0] ?? '');
            if (isset($fields[$fieldName])) {
                $tabs[array_key_last($tabs)]['fields'][] = $fieldName;
            }
        }

        return array_values(array_filter($tabs, static fn(array $tab): bool => $tab['fields'] !== []));
    }

    /**
     * @return list<array{label: string, value: string|int}>
     */
    private function normalizeSelectItems(array $items): array
    {
        $normalized = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $label = (string)($item['label'] ?? $item[0] ?? '');
            $value = $item['value'] ?? $item[1] ?? '';
            $normalized[] = [
                'label' => $this->translate($label),
                'value' => $value,
            ];
        }
        return $normalized;
    }

    /**
     * @param array<string, mixed> $column
     */
    private function isUsefulFormField(string $fieldName, array $column): bool
    {
        if (in_array($fieldName, [
            'deleted',
            'tstamp',
            'crdate',
            'cruser_id',
            't3ver_oid',
            't3ver_wsid',
            't3ver_state',
            't3ver_stage',
            't3ver_count',
            't3ver_tstamp',
            't3ver_move_id',
            'l10n_diffsource',
            'l10n_state',
            'import_id',
            'import_source',
        ], true)) {
            return false;
        }
        return (($column['config']['type'] ?? '') !== 'passthrough');
    }

    /**
     * @param array<string, mixed> $config
     */
    private function mapFieldType(string $type, string $renderType, array $config): string
    {
        if ($type === 'text' && ((bool)($config['enableRichtext'] ?? false) || !empty($config['richtextConfiguration']))) {
            return 'richtext';
        }
        if ($type === 'input' && ($renderType === 'inputDateTime' || str_contains((string)($config['eval'] ?? ''), 'datetime'))) {
            return 'datetime';
        }
        if ($type === 'input' && str_contains((string)($config['eval'] ?? ''), 'int')) {
            return 'number';
        }
        return match ($type) {
            'check' => 'boolean',
            'select', 'group' => 'relation',
            'file' => 'file',
            'number' => 'number',
            'slug' => 'slug',
            default => $type,
        };
    }

    /**
     * @param array<string, mixed> $column
     */
    private function canEditField(string $table, string $fieldName, array $column): bool
    {
        if (!$this->canModifyTable($table) || (bool)($column['config']['readOnly'] ?? false)) {
            return false;
        }
        if (!(bool)($column['exclude'] ?? false)) {
            return true;
        }

        $backendUser = $this->getBackendUser();
        return $backendUser instanceof BackendUserAuthentication
            && ($backendUser->isAdmin() || $backendUser->check('non_exclude_fields', $table . ':' . $fieldName));
    }

    private function canSelectTable(string $table): bool
    {
        $backendUser = $this->getBackendUser();
        return $backendUser instanceof BackendUserAuthentication
            && ($backendUser->isAdmin() || $backendUser->check('tables_select', $table));
    }

    private function canModifyTable(string $table): bool
    {
        $backendUser = $this->getBackendUser();
        return $backendUser instanceof BackendUserAuthentication
            && ($backendUser->isAdmin() || $backendUser->check('tables_modify', $table));
    }

    private function isSearchableTable(string $table): bool
    {
        return isset($GLOBALS['TCA'][$table])
            && ($table === 'pages'
                || $table === 'tt_content'
                || $table === 'sys_category'
                || str_starts_with($table, 'tx_news_domain_model_'));
    }

    /**
     * @return list<string>
     */
    private function getSearchFields(string $table): array
    {
        $fields = GeneralUtility::trimExplode(',', (string)($GLOBALS['TCA'][$table]['ctrl']['searchFields'] ?? ''), true);
        $labelField = $this->getLabelField($table);
        if ($labelField !== '' && !in_array($labelField, $fields, true)) {
            array_unshift($fields, $labelField);
        }
        return $fields !== [] ? $fields : ['uid'];
    }

    private function getLabelField(string $table): string
    {
        return (string)($GLOBALS['TCA'][$table]['ctrl']['label'] ?? 'uid');
    }

    /**
     * @param array<string, mixed> $record
     */
    private function getRecordLabel(string $table, array $record): string
    {
        $labelField = $this->getLabelField($table);
        $label = (string)($record[$labelField] ?? '');
        return $label !== '' ? $label : $table . ' #' . (int)$record['uid'];
    }

    private function resolveFolder(string $combinedIdentifier, BackendUserAuthentication $backendUser): ?Folder
    {
        if ($combinedIdentifier !== '') {
            try {
                return $this->resourceFactory->getFolderObjectFromCombinedIdentifier($combinedIdentifier);
            } catch (\Throwable) {
                return null;
            }
        }

        $folder = $this->defaultUploadFolderResolver->resolve($backendUser, 16, self::NEWS_TABLE, 'fal_media');
        if ($folder instanceof Folder) {
            return $folder;
        }

        foreach ($backendUser->getFileStorages() as $storage) {
            try {
                return $storage->getDefaultFolder();
            } catch (\Throwable) {
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeFolder(Folder $folder): array
    {
        return [
            'name' => $folder->getName(),
            'identifier' => $folder->getIdentifier(),
            'combinedIdentifier' => $folder->getCombinedIdentifier(),
            'publicUrl' => $folder->getPublicUrl(),
            'permissions' => [
                'read' => $folder->checkActionPermission('read'),
                'write' => $folder->checkActionPermission('write'),
                'delete' => $folder->checkActionPermission('delete'),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeFile(FileInterface $file): array
    {
        $permissions = [
            'read' => true,
            'write' => false,
            'delete' => false,
        ];
        if ($file instanceof File) {
            $permissions = [
                'read' => $file->checkActionPermission('read'),
                'write' => $file->checkActionPermission('write'),
                'delete' => $file->checkActionPermission('delete'),
            ];
        }

        return [
            'uid' => method_exists($file, 'getUid') ? $file->getUid() : null,
            'name' => $file->getName(),
            'identifier' => $file->getIdentifier(),
            'combinedIdentifier' => method_exists($file, 'getCombinedIdentifier') ? $file->getCombinedIdentifier() : null,
            'publicUrl' => $file->getPublicUrl(),
            'mimeType' => $file->getMimeType(),
            'size' => $file->getSize(),
            'extension' => $file->getExtension(),
            'permissions' => $permissions,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function listAccessibleWorkspaces(BackendUserAuthentication $backendUser): array
    {
        $workspaces = [];
        $liveWorkspace = $backendUser->checkWorkspace(0);
        if ($liveWorkspace !== false) {
            $workspaces[] = [
                'uid' => 0,
                'title' => 'Live',
                'access' => $liveWorkspace['_ACCESS'] ?? 'online',
                'current' => (int)$backendUser->workspace === 0,
                'publishAllowed' => true,
            ];
        }

        if (!isset($GLOBALS['TCA']['sys_workspace'])) {
            return $workspaces;
        }

        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('sys_workspace');
        $queryBuilder->getRestrictions()->add(GeneralUtility::makeInstance(RootLevelRestriction::class));
        foreach ($queryBuilder->select('*')->from('sys_workspace')->orderBy('title')->executeQuery()->fetchAllAssociative() as $workspaceRecord) {
            $checkedWorkspace = $backendUser->checkWorkspace($workspaceRecord);
            if ($checkedWorkspace === false) {
                continue;
            }
            $workspaces[] = [
                'uid' => (int)$workspaceRecord['uid'],
                'title' => (string)$workspaceRecord['title'],
                'access' => (string)($checkedWorkspace['_ACCESS'] ?? ''),
                'current' => (int)$backendUser->workspace === (int)$workspaceRecord['uid'],
                'publishAllowed' => $backendUser->isAdmin() || ($checkedWorkspace['_ACCESS'] ?? '') === 'owner',
            ];
        }

        return $workspaces;
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeCurrentWorkspace(BackendUserAuthentication $backendUser): array
    {
        return [
            'uid' => (int)$backendUser->workspace,
            'title' => (string)($backendUser->workspaceRec['title'] ?? ((int)$backendUser->workspace === 0 ? 'Live' : 'Workspace')),
            'access' => (string)($backendUser->workspaceRec['_ACCESS'] ?? ''),
        ];
    }

    /**
     * @return array{versionUid: int, liveUid: int}|null
     */
    private function resolveWorkspaceVersion(int $id): ?array
    {
        $record = BackendUtility::getRecord(self::NEWS_TABLE, $id, '*');
        if (!is_array($record)) {
            return null;
        }

        if ((int)($record['t3ver_wsid'] ?? 0) > 0) {
            return [
                'versionUid' => (int)$record['uid'],
                'liveUid' => (int)($record['t3ver_oid'] ?: $record['uid']),
            ];
        }

        $backendUser = $this->getBackendUser();
        $workspace = $backendUser instanceof BackendUserAuthentication ? (int)$backendUser->workspace : 0;
        if ($workspace <= 0) {
            return null;
        }

        $workspaceRecord = BackendUtility::getWorkspaceVersionOfRecord($workspace, self::NEWS_TABLE, $id, '*');
        if (!is_array($workspaceRecord)) {
            return null;
        }

        return [
            'versionUid' => (int)$workspaceRecord['uid'],
            'liveUid' => (int)($workspaceRecord['t3ver_oid'] ?: $id),
        ];
    }

    /**
     * @param array<string, mixed> $cmdMap
     * @return list<string>
     */
    private function processCommandMap(array $cmdMap): array
    {
        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->bypassAccessCheckForRecords = false;
        $dataHandler->start([], $cmdMap);
        $dataHandler->process_cmdmap();
        return array_values($dataHandler->errorLog);
    }

    private function requireBackendUser(): BackendUserAuthentication|ResponseInterface
    {
        $backendUser = $this->getBackendUser();
        return $backendUser instanceof BackendUserAuthentication
            ? $backendUser
            : $this->forbidden('Backend user context is missing.');
    }

    private function getBackendUser(): ?BackendUserAuthentication
    {
        return ($GLOBALS['BE_USER'] ?? null) instanceof BackendUserAuthentication ? $GLOBALS['BE_USER'] : null;
    }

    private function translate(string $label): string
    {
        if ($label === '') {
            return '';
        }
        $languageService = $GLOBALS['LANG'] ?? null;
        if ($languageService && str_starts_with($label, 'LLL:')) {
            return $languageService->sL($label) ?: $label;
        }
        return $label;
    }

    private function badRequest(string $detail): ResponseInterface
    {
        return $this->responseService->createErrorResponse('Bad Request', $detail, 400);
    }

    private function forbidden(string $detail): ResponseInterface
    {
        return $this->responseService->createErrorResponse('Forbidden', $detail, 403);
    }

    /**
     * @param list<string> $errors
     */
    private function dataHandlerError(array $errors): ResponseInterface
    {
        return $this->responseService->createErrorResponse(
            'DataHandler Error',
            'TYPO3 rejected the requested operation.',
            422,
            additionalData: ['errors' => $errors],
        );
    }
}
