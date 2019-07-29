<?php
declare(strict_types = 1);
namespace B13\Slugr\Command;

/*
 * This file is part of the b13's slugr extension.
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

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Style\SymfonyStyle;
use TYPO3\CMS\Core\Core\Bootstrap;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\DataHandling\SlugHelper;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Regenerates all old page slugs, and optionally adds redirects when the Slug has been altered.
 */
class RegeneratePageSlugCommand extends Command
{
    /**
     * Defines the allowed options for this command
     */
    protected function configure()
    {
        $this
            ->setDescription('Regenerates page slugs for a specific site, or language, if it changes from the current one')
            ->addOption(
                'site',
                'string',
                InputOption::VALUE_REQUIRED,
                'Limit this to a specific site, giving an identifier like "main"'
            )
            ->addOption(
                'language',
                'l',
                InputOption::VALUE_REQUIRED,
                'Limit the regeneration to a specific language ID, used in the site'
            )
            ->addOption(
                'redirects',
                '',
                InputOption::VALUE_NONE,
                'Add redirects (if redirects extension is installed)'
            );
    }

    /**
     * Updates Slugs
     *
     * @inheritdoc
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $io = new SymfonyStyle($input, $output);

        $shouldAddRedirects = $input->getOption('redirects');

        if ($shouldAddRedirects && !ExtensionManagementUtility::isLoaded('redirects')) {
            $io->error('EXT:redirects is not installed, no redirects possible.');
            return 1;
        }

        if (!$shouldAddRedirects) {

        }

        $limitToLanguage = $input->getOption('language') ? (int)$input->getOption('language') : false;
        $limitToSite = $input->getOption('site') ? $input->getOption('site') : null;

        $sites = [];
        if ($limitToSite !== null) {
            $sites[] = GeneralUtility::makeInstance(SiteFinder::class)->getSiteByIdentifier($limitToSite);
        } else {
            $sites = GeneralUtility::makeInstance(SiteFinder::class)->getAllSites();
        }

        $slugHelper = GeneralUtility::makeInstance(
            SlugHelper::class,
            'pages',
            'slug',
            $GLOBALS['TCA']['pages']['columns']['slug']['config']
        );
        $slugsToMigrate = [];
        $slugsToMigrateWithLanguage = [];
        foreach ($sites as $site) {
            if ($io->isVerbose()) {
                $io->section('Regenerating URLs for site ' . (string)$site->getBase());
            }

            // Find all pages within one site
            $pagesOfSite = $this->getSubPages($site->getRootPageId(), $limitToLanguage);

            $pagesForListing = [];
            foreach ($pagesOfSite as $page) {
                $newSlug = $slugHelper->generate($page, (int)$page['pid']);
                $language = $site->getLanguageById((int)$page['sys_language_uid']);
                $pagesForListing[] = [
                    'uid' => $page['uid'],
                    'language' => $page['sys_language_uid'],
                    'title' => $page['title'],
                    'slug' => $page['slug'],
                    'new' => $newSlug,
                ];

                if ($page['slug'] !== $newSlug) {
                    $slugsToMigrateWithLanguage[] = ['page' => $page['uid'], 'old' => $page['slug'], 'new' => $newSlug, 'language' => $language];
                    $slugsToMigrate[] = ['page' => $page['uid'], 'old' => $page['slug'], 'new' => $newSlug];
                }
            }
            if ($io->isVerbose()) {
                $io->section('The following pages have been found');
                $io->table(['Page ID', 'Language', 'Title', 'Slug', 'New Slug'], $pagesForListing);
            }
        }

        $io->caution('The following slugs will now be migrated.');
        $io->table(['Page ID' , 'Old Slug', 'New Slug'], $slugsToMigrate);
        $proceed = $io->askQuestion(new Question('We found ' . count($slugsToMigrate) . ' page URLs that will be updated. Should we continue?', 'yes')) === 'yes';
        if ($proceed) {
            $dataForDataHandler = ['pages' => []];
            if ($shouldAddRedirects) {
                $dataForDataHandler['sys_redirect'] = [];
            }
            foreach ($slugsToMigrateWithLanguage as $slugData) {
                $uniqueId = uniqid('NEW_');
                $dataForDataHandler['pages'][$slugData['page']] = ['slug' => $slugData['new']];
                if ($shouldAddRedirects) {
                    $dataForDataHandler['sys_redirect'][$uniqueId] = [
                        'pid' => 0,
                        'source_host' => $slugData['language']->getBase()->getHost(),
                        'source_path' => $slugData['language']->getBase()->getPath() . ltrim($slugData['old'], '/'),
                        'target' => 't3://page?uid=' . $slugData['page'],
                        'target_statuscode' => 307
                    ];
                }
            }
            Bootstrap::initializeBackendAuthentication(true);
            $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
            $dataHandler->start($dataForDataHandler, []);
            $dataHandler->process_datamap();
            $io->success('Migrated ' . count($slugsToMigrate) . ' Page URLs successfully.');
        } else {
            $io->error('Aborted.');
        }
    }

    /**
     * Fetch all subpages of a given page ID
     * @param int $pid
     * @param int|null $languageId
     * @return array
     */
    protected function getSubPages(int $pid, ?int $languageId): array
    {
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getQueryBuilderForTable('pages');
        $queryBuilder->getRestrictions()->removeAll()->add(GeneralUtility::makeInstance(DeletedRestriction::class));
        $queryBuilder
            ->select('*')
            ->from('pages')
            ->where(
                $queryBuilder->expr()->eq('pid', $queryBuilder->createNamedParameter($pid))
            )
            ->orderBy('sys_language_uid');

        if ($languageId !== null) {
            $queryBuilder->andWhere(
                $queryBuilder->expr()->eq('sys_language_uid', $queryBuilder->createNamedParameter($languageId))
            );
        };
        $pages = $queryBuilder->execute()->fetchAll();

        // First add all of this level, then add subpages
        $allPages = $pages;
        foreach ($pages ?? [] as $page) {
            $allPages += $this->getSubPages((int)($page['l10n_parent'] ?: $page['uid']), $languageId);
        }
        return $allPages;
    }
}
