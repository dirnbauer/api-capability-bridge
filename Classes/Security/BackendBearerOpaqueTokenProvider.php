<?php

declare(strict_types=1);

namespace Dirnbauer\ApiCapabilityBridge\Security;

use Psr\Http\Message\ServerRequestInterface;
use SGalinski\SgApiCore\Configuration\ExtensionConfiguration;
use SGalinski\SgApiCore\Domain\Repository\TokenRepository;
use SGalinski\SgApiCore\Security\AuthContext;
use SGalinski\SgApiCore\Security\LoginProviderInterface;
use SGalinski\SgApiCore\Security\TokenExtractionTrait;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;

final class BackendBearerOpaqueTokenProvider implements LoginProviderInterface
{
    use TokenExtractionTrait;

    public function __construct(
        private readonly TokenRepository $tokenRepository,
        private readonly ExtensionConfiguration $extensionConfiguration,
        private readonly LanguageServiceFactory $languageServiceFactory,
    ) {}

    public function authenticate(
        ServerRequestInterface $request,
        string $apiId,
        ?string $tenantId,
        array $activeProviders = [],
    ): ?AuthContext {
        $tenantId ??= '';
        $token = $this->extractToken($request);
        if ($token === '') {
            return null;
        }

        $tenantContext = $request->getAttribute('api.tenant');
        $siteRootPageId = $tenantContext?->getSiteRootPageId();
        $tokenRecord = $this->tokenRepository->findByHashApiAndTenant(
            hash('sha256', $token),
            $apiId,
            $tenantId,
            $siteRootPageId,
        );

        if (!is_array($tokenRecord)) {
            return null;
        }
        if ((int)($tokenRecord['expires_at'] ?? 0) > 0 && (int)$tokenRecord['expires_at'] < time()) {
            return null;
        }

        $backendUserUid = (int)($tokenRecord['be_user_uid'] ?? 0);
        if ($backendUserUid <= 0 || !$this->initializeBackendUser($backendUserUid, $request)) {
            return null;
        }

        $this->tokenRepository->updateLastUsed((int)$tokenRecord['uid']);

        return new AuthContext(
            apiId: $apiId,
            tenantId: $tenantId,
            tokenUid: (int)$tokenRecord['uid'],
            scopes: $this->decodeScopes((string)($tokenRecord['scopes'] ?? '')),
            userId: $backendUserUid,
        );
    }

    protected function getExtensionConfiguration(): ExtensionConfiguration
    {
        return $this->extensionConfiguration;
    }

    private function initializeBackendUser(int $backendUserUid, ServerRequestInterface $request): bool
    {
        $backendUser = GeneralUtility::makeInstance(BackendUserAuthentication::class);
        $backendUser->setBeUserByUid($backendUserUid);
        if (empty($backendUser->user)) {
            return false;
        }

        $backendUser->fetchGroupData();
        $this->applyRequestedWorkspace($backendUser, $request);
        $GLOBALS['BE_USER'] = $backendUser;
        if (!(($GLOBALS['LANG'] ?? null) instanceof LanguageService)) {
            $GLOBALS['LANG'] = $this->languageServiceFactory->create('default');
        }
        return true;
    }

    private function applyRequestedWorkspace(BackendUserAuthentication $backendUser, ServerRequestInterface $request): void
    {
        $queryParams = $request->getQueryParams();
        $workspaceHeader = $request->getHeaderLine('X-TYPO3-Workspace');
        $workspace = $workspaceHeader !== ''
            ? (int)$workspaceHeader
            : (isset($queryParams['workspace']) ? (int)$queryParams['workspace'] : null);

        if ($workspace !== null) {
            $backendUser->setTemporaryWorkspace($workspace);
        }
    }

    /**
     * @return list<string>
     */
    private function decodeScopes(string $scopes): array
    {
        if ($scopes === '') {
            return [];
        }

        try {
            $decoded = json_decode($scopes, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        return is_array($decoded) ? array_values(array_filter($decoded, 'is_string')) : [];
    }
}
