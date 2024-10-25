<?php

// @todo: self::getAbsRefPrefixFromTSFE() returns false instead of string.
//        Solve that issue and activate strict types.
//declare(strict_types=1);

/*
 * This file is part of the TYPO3 CMS project.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 *
 * The TYPO3 project - inspiring people to share!
 */

namespace ApacheSolrForTypo3\Solr\FrontendEnvironment;

use ApacheSolrForTypo3\Solr\System\Configuration\ConfigurationPageResolver;
use Doctrine\DBAL\Exception as DBALException;
use Throwable;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Context\Exception\AspectNotFoundException;
use TYPO3\CMS\Core\Context\LanguageAspectFactory;
use TYPO3\CMS\Core\Context\TypoScriptAspect;
use TYPO3\CMS\Core\Context\UserAspect;
use TYPO3\CMS\Core\Context\VisibilityAspect;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\Domain\Repository\PageRepository;
use TYPO3\CMS\Core\Exception\SiteNotFoundException;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Localization\Locales;
use TYPO3\CMS\Core\Routing\PageArguments;
use TYPO3\CMS\Core\SingletonInterface;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\TypoScript\TemplateService;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Frontend\Authentication\FrontendUserAuthentication;
use TYPO3\CMS\Frontend\Controller\TypoScriptFrontendController;

/**
 * Class Tsfe is a factory class for TSFE(TypoScriptFrontendController) objects.
 */
class Tsfe implements SingletonInterface
{
    /**
     * @var TypoScriptFrontendController[]
     */
    protected array $tsfeCache = [];

    /**
     * @var ServerRequest[]
     */
    protected array $serverRequestCache = [];

    protected SiteFinder $siteFinder;

    /**
     * Initializes isolated TypoScriptFrontendController for Indexing and backend actions.
     */
    public function __construct(?SiteFinder $siteFinder = null)
    {
        $this->siteFinder = $siteFinder ?? GeneralUtility::makeInstance(SiteFinder::class);
    }

    /**
     * Initializes the TSFE for a given page ID and language.
     *
     *
     *
     * @throws Exception\Exception
     * @throws SiteNotFoundException
     * @throws DBALException
     *
     *
     * @todo: Move whole caching stuff from this method and let return TSFE.
     */
    protected function initializeTsfe(int $pageId, int $language = 0, ?int $rootPageId = null): void
    {
        $cacheIdentifier = $this->getCacheIdentifier($pageId, $language, $rootPageId);

        // Handle spacer and sys-folders, since they are not accessible in frontend, and TSFE can not be fully initialized on them.
        // Apart from this, the plugin.tx_solr.index.queue.[indexConfig].additionalPageIds is handled as well.
        $pidToUse = $this->getPidToUseForTsfeInitialization($pageId, $rootPageId);
        if ($pidToUse !== $pageId) {
            $this->initializeTsfe($pidToUse, $language, $rootPageId);
            $reusedCacheIdentifier = $this->getCacheIdentifier($pidToUse, $language, $rootPageId);
            $this->serverRequestCache[$cacheIdentifier] = $this->serverRequestCache[$reusedCacheIdentifier];
            $this->tsfeCache[$cacheIdentifier] = $this->tsfeCache[$reusedCacheIdentifier];
            //            if ($rootPageId === null) {
            //                // @Todo: Resolve and set TSFE object for $rootPageId.
            //            }
            return;
        }

        /** @var Context $context */
        $context = clone GeneralUtility::makeInstance(Context::class);
        $site = $this->siteFinder->getSiteByPageId($pageId);
        // $siteLanguage and $languageAspect takes the language id into account.
        //   See: $site->getLanguageById($language);
        //   Therefore the whole TSFE stack is initialized and must be used as is.
        //   Note: ServerRequest, Context, Language, cObj of TSFE MUST NOT be changed or touched in any way,
        //         Otherwise the caching of TSFEs makes no sense anymore.
        //         If you want something to change in TSFE object, please use cloned one!
        $siteLanguage = $site->getLanguageById($language);
        $languageAspect = LanguageAspectFactory::createFromSiteLanguage($siteLanguage);
        $context->setAspect('language', $languageAspect);

        $serverRequest = $this->serverRequestCache[$cacheIdentifier] ?? null;
        if (!isset($this->serverRequestCache[$cacheIdentifier])) {
            $serverRequest = GeneralUtility::makeInstance(ServerRequest::class);
            $this->serverRequestCache[$cacheIdentifier] = $serverRequest =
                $serverRequest->withAttribute('site', $site)
                ->withAttribute('language', $siteLanguage)
                ->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_FE)
                ->withUri($site->getBase());
        }

        if (!isset($this->tsfeCache[$cacheIdentifier])) {
            // TYPO3 by default enables a preview mode if a backend user is logged in,
            // the VisibilityAspect is configured to show hidden elements.
            // Due to this setting hidden relations/translations might be indexed
            // when running the Solr indexer via the TYPO3 backend.
            // To avoid this, the VisibilityAspect is adapted for indexing.
            $context->setAspect(
                'visibility',
                GeneralUtility::makeInstance(
                    VisibilityAspect::class,
                    false,
                    false
                )
            );

            /** @var FrontendUserAuthentication $feUser */
            $feUser = GeneralUtility::makeInstance(FrontendUserAuthentication::class);
            // for certain situations we need to trick TSFE into granting us
            // access to the page in any case to make getPageAndRootline() work
            // see http://forge.typo3.org/issues/42122
            $pageRecord = BackendUtility::getRecord('pages', $pageId, 'fe_group');

            // Check rootline for feGroups from page with extendToSubpages set
            $rootline = BackendUtility::BEgetRootLine($pageId);
            // remove the current page from the rootline
            array_shift($rootline);
            $rootlineUserGroups = [];
            foreach ($rootline as $page) {
                // Skip root node, invalid pages and pages which do not define extendToSubpages
                if ((int)($page['uid'] ?? 0) <= 0 || !($page['extendToSubpages'] ?? false)) {
                    continue;
                }
                $rootlineUserGroups = explode(',', $page['fe_group']);
                // Stop as soon as a page in the rootline has extendToSubpages set
                break;
            }
            
            if (!empty($pageRecord['fe_group'])) {
                $userGroups = explode(',', $pageRecord['fe_group']);

                // If page has set usergroups and usergroups where also found in rootline combine them so the TSFE object can be built correctly
                $userGroups = $rootlineUserGroups ? array_unique(array_merge($userGroups, $rootlineUserGroups)) : $userGroups;
            } else {
                $userGroups = $rootlineUserGroups ?: [0, -1];
            }
            $feUser->user = ['uid' => 0, 'username' => '', 'usergroup' => implode(',', $userGroups) ];
            $feUser->fetchGroupData($serverRequest);
            $context->setAspect('frontend.user', GeneralUtility::makeInstance(UserAspect::class, $feUser, $userGroups));

            /** @var PageArguments $pageArguments */
            $pageArguments = GeneralUtility::makeInstance(PageArguments::class, $pageId, '0', []);

            /** @var TypoScriptFrontendController $tsfe */
            $tsfe = GeneralUtility::makeInstance(TypoScriptFrontendController::class, $context, $site, $siteLanguage, $pageArguments, $feUser);

            // @extensionScannerIgnoreLine
            /** Done in {@link TypoScriptFrontendController::settingLanguage} */
            //$tsfe->sys_page = GeneralUtility::makeInstance(PageRepository::class);

            $template = GeneralUtility::makeInstance(TemplateService::class, $context, null, $tsfe);
            $template->tt_track = false;
            $tsfe->tmpl = $template;
            $context->setAspect('typoscript', GeneralUtility::makeInstance(TypoScriptAspect::class, true));
            $tsfe->no_cache = true;

            $backedUpBackendUser = $GLOBALS['BE_USER'] ?? null;
            try {
                $serverRequest = $serverRequest->withAttribute('frontend.controller', $tsfe);
                $tsfe->determineId($serverRequest);
                $tsfe->no_cache = false;
                /** @var ServerRequest $serverRequest */
                $serverRequest = $tsfe->getFromCache($serverRequest);
                // The manual releasing of locks is low level api and should be avoided in EXT:solr.
                $tsfe->releaseLocks();

                $tsfe->newCObj($serverRequest);
                $tsfe->absRefPrefix = self::getAbsRefPrefixFromTSFE($tsfe);
                $tsfe->calculateLinkVars([]);
            } catch (Throwable $exception) {
                // @todo: logging
                $this->serverRequestCache[$cacheIdentifier] = null;
                $this->tsfeCache[$cacheIdentifier] = null;
                // Restore backend user, happens when initializeTsfe() is called from Backend context
                if ($backedUpBackendUser) {
                    $GLOBALS['BE_USER'] = $backedUpBackendUser;
                }
                return;
            }
            // Restore backend user, happens when initializeTsfe() is called from Backend context
            if ($backedUpBackendUser) {
                $GLOBALS['BE_USER'] = $backedUpBackendUser;
            }

            $this->serverRequestCache[$cacheIdentifier] = $serverRequest;
            $this->tsfeCache[$cacheIdentifier] = $tsfe;
        }

        // @todo: Not right place for that action, move on more convenient place: indexing a single item+id+lang.
        Locales::setSystemLocaleFromSiteLanguage($siteLanguage);
    }

    /**
     * Returns TypoScriptFrontendController with sand cast context.
     *
     * @throws SiteNotFoundException
     * @throws Exception\Exception
     * @throws DBALException
     */
    public function getTsfeByPageIdAndLanguageId(int $pageId, int $language = 0, ?int $rootPageId = null): ?TypoScriptFrontendController
    {
        $this->assureIsInitialized($pageId, $language, $rootPageId);
        return $this->tsfeCache[$this->getCacheIdentifier($pageId, $language, $rootPageId)];
    }

    /**
     * Returns TypoScriptFrontendController for first available language id in fallback chain.
     *
     * Is usable for BE-Modules/CLI-Commands stack only, where the rendered TypoScript configuration
     * of EXT:solr* stack is wanted and the language id does not matter.
     *
     * NOTE: This method MUST NOT be used on indexing context.
     *
     * @param int ...$languageFallbackChain
     */
    public function getTsfeByPageIdAndLanguageFallbackChain(int $pageId, int ...$languageFallbackChain): ?TypoScriptFrontendController
    {
        foreach ($languageFallbackChain as $languageId) {
            try {
                $tsfe = $this->getTsfeByPageIdAndLanguageId($pageId, $languageId);
                if ($tsfe instanceof TypoScriptFrontendController) {
                    return $tsfe;
                }
            } catch (Throwable $e) {
                // no needs to log or do anything, the method MUST not return anything if it can't.
                continue;
            }
        }
        return null;
    }

    /**
     * Returns TSFE for first initializable site language.
     *
     * Is usable for BE-Modules/CLI-Commands stack only, where the rendered TypoScript configuration
     * of EXT:solr* stack is wanted and the language id does not matter.
     */
    public function getTsfeByPageIdIgnoringLanguage(int $pageId): ?TypoScriptFrontendController
    {
        try {
            $typo3Site = $this->siteFinder->getSiteByPageId($pageId);
        } catch (Throwable $e) {
            return null;
        }
        $availableLanguageIds = array_map(static function ($siteLanguage) {
            return $siteLanguage->getLanguageId();
        }, $typo3Site->getLanguages());

        if (empty($availableLanguageIds)) {
            return null;
        }
        return $this->getTsfeByPageIdAndLanguageFallbackChain($pageId, ...$availableLanguageIds);
    }

    /**
     * Returns TypoScriptFrontendController with sand cast context.
     *
     * @throws SiteNotFoundException
     * @throws Exception\Exception
     * @throws DBALException
     *
     * @noinspection PhpUnused
     */
    public function getServerRequestForTsfeByPageIdAndLanguageId(int $pageId, int $language = 0, ?int $rootPageId = null): ?ServerRequest
    {
        $this->assureIsInitialized($pageId, $language, $rootPageId);
        return $this->serverRequestCache[$this->getCacheIdentifier($pageId, $language, $rootPageId)];
    }

    /**
     * Initializes the TSFE, ServerRequest, Context if not already done.
     *
     *
     *
     * @throws SiteNotFoundException
     * @throws Exception\Exception
     * @throws DBALException
     */
    protected function assureIsInitialized(int $pageId, int $language, ?int $rootPageId = null): void
    {
        $cacheIdentifier = $this->getCacheIdentifier($pageId, $language, $rootPageId);
        if (!array_key_exists($cacheIdentifier, $this->tsfeCache)) {
            $this->initializeTsfe($pageId, $language, $rootPageId);
            return;
        }
        if ($this->tsfeCache[$cacheIdentifier] instanceof TypoScriptFrontendController) {
            $this->tsfeCache[$cacheIdentifier]->newCObj($this->serverRequestCache[$cacheIdentifier]);
        }
    }

    /**
     * Returns the cache identifier for cached TSFE and ServerRequest objects.
     */
    protected function getCacheIdentifier(int $pageId, int $language, ?int $rootPageId = null): string
    {
        return 'root:' . ($rootPageId ?? 'null') . '|page:' . $pageId . '|lang:' . $language;
    }

    /**
     * The TSFE can not be initialized for Spacer and sys-folders.
     * See: "Spacer and sys folders is not accessible in frontend" on {@link TypoScriptFrontendController::getPageAndRootline}
     *
     * Note: The requested $pidToUse can be one of configured plugin.tx_solr.index.queue.[indexConfig].additionalPageIds.
     *
     * @throws Exception\Exception
     * @throws DBALException
     */
    protected function getPidToUseForTsfeInitialization(int $pidToUse, ?int $rootPageId = null): ?int
    {
        $incomingPidToUse = $pidToUse;
        $incomingRootPageId = $rootPageId;

        // handle plugin.tx_solr.index.queue.[indexConfig].additionalPageIds
        if (isset($rootPageId) && !$this->isRequestedPageAPartOfRequestedSite($pidToUse)) {
            return $rootPageId;
        }
        $pageRecord = BackendUtility::getRecord('pages', $pidToUse);
        $isSpacerOrSysfolder = ($pageRecord['doktype'] ?? null) == PageRepository::DOKTYPE_SPACER || ($pageRecord['doktype'] ?? null) == PageRepository::DOKTYPE_SYSFOLDER;
        if ($isSpacerOrSysfolder === false && $this->isPageAvailableForTSFE($pageRecord)) {
            return $pidToUse;
        }
        /** @var ConfigurationPageResolver $configurationPageResolver */
        $configurationPageResolver = GeneralUtility::makeInstance(ConfigurationPageResolver::class);
        $askedPid = $pidToUse;
        $pidToUse = $configurationPageResolver->getClosestPageIdWithActiveTemplate($pidToUse);
        if (!isset($pidToUse) && !isset($rootPageId)) {
            throw new Exception\Exception(
                "The closest page with active template to page \"$askedPid\" could not be resolved and alternative rootPageId is not provided.",
                1637339439
            );
        }
        if (isset($rootPageId)) {
            return $rootPageId;
        }

        // Check for recursion that can happen if the root page is a sysfolder with a typoscript template
        if ($pidToUse === $incomingPidToUse && $rootPageId === $incomingRootPageId) {
            throw new Exception\Exception(
                "Infinite recursion detected while looking for the closest page with active template to page \"$askedPid\" . Please note that the page with active template (usually the root page of the current tree) MUST NOT be a sysfolder.",
                1637339476
            );
        }

        return $this->getPidToUseForTsfeInitialization($pidToUse, $rootPageId);
    }

    /**
     * Checks if the page is available for TSFE.
     *
     * @param array $pageRecord
     * @return bool
     * @throws AspectNotFoundException
     */
    protected function isPageAvailableForTSFE(array $pageRecord): bool
    {
        $currentTime = GeneralUtility::makeInstance(Context::class)->getPropertyFromAspect('date', 'timestamp');
        return $pageRecord['hidden'] === 0 &&
            $pageRecord['starttime'] <= $currentTime &&
            ($pageRecord['endtime'] === 0 || $pageRecord['endtime'] > 0 && $pageRecord['endtime'] > $currentTime)
        ;
    }

    /**
     * Checks if the requested page belongs to site of given root page.
     */
    protected function isRequestedPageAPartOfRequestedSite(int $pageId, ?int $rootPageId = null): bool
    {
        if (!isset($rootPageId)) {
            return false;
        }
        try {
            $site = $this->siteFinder->getSiteByPageId($pageId);
        } catch (SiteNotFoundException $e) {
            return false;
        }
        return $rootPageId === $site->getRootPageId();
    }

    /**
     * Resolves the configured absRefPrefix to a valid value and resolved if absRefPrefix
     * is set to "auto".
     */
    private function getAbsRefPrefixFromTSFE(TypoScriptFrontendController $TSFE): string
    {
        $absRefPrefix = '';
        if (empty($TSFE->config['config']['absRefPrefix'])) {
            return $absRefPrefix;
        }

        $absRefPrefix = trim($TSFE->config['config']['absRefPrefix']);
        if ($absRefPrefix === 'auto') {
            $absRefPrefix = GeneralUtility::getIndpEnv('TYPO3_SITE_PATH');
        }

        return $absRefPrefix;
    }
}
