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
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Regenerates all old page slugs, and optionally adds redirects when the Slug has been altered.
 */
class RegeneratePageSlugCommand extends Command
{
    /**
     * @var SlugHelper
     */
    protected $slugHelper;

    /**
     * Defines the allowed options for this command
     */
    protected function configure()
    {
        $this
            ->setDescription(
                'Regenerates page slugs for a specific site, or language, if it changes from the current one'
            )
            ->addOption(
                'site',
                's',
                InputOption::VALUE_REQUIRED,
                'Limit this to a specific site, giving an identifier like "main"'
            )
            ->addOption(
                'language',
                'l',
                InputOption::VALUE_OPTIONAL,
                'Limit the regeneration to a specific language ID, used in the site'
            )
            ->addOption(
                'redirects',
                'r',
                InputOption::VALUE_OPTIONAL,
                'Add redirects (if redirects extension is installed)',
                false
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

        $shouldAddRedirects = (bool)$input->getOption('redirects');

        if ($shouldAddRedirects && !ExtensionManagementUtility::isLoaded('redirects')) {
            $io->error('EXT:redirects is not installed, no redirects possible.');
            return 1;
        }

        $limitToLanguage = $input->getOption('language') ? (int)$input->getOption('language') : null;
        $limitToSite = $input->getOption('site') ? $input->getOption('site') : null;
        $sites = $this->getSites($limitToSite);

        $answer = $io->askQuestion(
            new Question(
                'About to start page slug regeneration for ' . count($sites) . ' Site(s). Should we continue?',
                'yes'
            )
        );
        if ($answer !== 'yes') {
            $io->error('Aborted.');
            return 0;
        }

        $this->slugHelper = GeneralUtility::makeInstance(
            SlugHelper::class,
            'pages',
            'slug',
            $GLOBALS['TCA']['pages']['columns']['slug']['config']
        );

        $pagesToMigrateTotal = 0;
        foreach ($sites as $site) {
            $name = (string)$site->getBase();
            $io->title('Regenerating URLs for site ' . $name);

            $migratedPages = [];
            $this->migratePagesByPid(
                $io,
                $site,
                $site->getRootPageId(),
                $migratedPages,
                $limitToLanguage,
                $shouldAddRedirects
            );

            $io->section('Migration for site ' . $name . ' finished. Migrated ' . count($migratedPages) . ' pages.');
            $io->text('The following pages have been migrated:');
            $io->table(['Page UID', 'Page PID', 'Language', 'Title', 'Slug', 'New Slug'], $migratedPages);

            $pagesToMigrateTotal += count($migratedPages);
        }

        $io->success('Migrated ' . $pagesToMigrateTotal . ' page URLs for ' . count($sites) . ' site(s) successfully.');
        return 0;
    }

    /**
     * @param SymfonyStyle $io
     * @param Site $site
     * @param int $pid
     * @param array $migratedPages
     * @param int $language
     * @param bool $addRedirects
     */
    protected function migratePagesByPid(
        SymfonyStyle $io,
        Site $site,
        int $pid,
        array &$migratedPages,
        ?int $language = null,
        $addRedirects = false
    ) {
        $pages = $this->getPages($pid, $language);

        if ($io->isVerbose()) {
            $io->note('Migrate all pages with PID = ' . $pid);
        }

        if (empty($pages)) {
            if ($io->isVerbose()) {
                $io->text('No pages found.');
            }

            return;
        }

        $pagesForListing = [];
        $pagesToMigrate = [];
        foreach ($pages as $page) {
            $row = [
                'uid' => $page['uid'],
                'pid' => $page['pid'],
                'language' => $page['sys_language_uid'],
                'title' => $page['title'],
                'old' => $page['slug'],
                'new' => '',
            ];

            $newSlug = $this->slugHelper->generate($page, (int)$page['pid']);
            if ($page['slug'] !== $newSlug) {
                $row['new'] = $newSlug;
                $pagesToMigrate[] = $migratedPages[] = $row;
            }

            $pagesForListing[] = $row;
        }

        if ($io->isVerbose()) {
            $io->text('The following pages have been found on this level:');
            $io->table(['Page UID', 'Page PID', 'Language', 'Title', 'Slug', 'New Slug'], $pagesForListing);
        }

        if (empty($pagesToMigrate)) {
            if ($io->isVerbose()) {
                $io->text('No migration needed.');
            }
        } else {
            // Write new slugs for this page tree level
            $this->migrateSlugs($site, $pagesToMigrate, $addRedirects);
        }

        // Start sub pages migration for each migrated page, now that we have their parent page slugs fixed
        foreach ($pages as $page) {
            $this->migratePagesByPid($io, $site, (int)$page['uid'], $migratedPages, $language, $addRedirects);
        }
    }

    /**
     * Fetch all pages of a given page ID
     *
     * @param int $pid
     * @param int|null $languageId
     * @return array
     */
    protected function getPages(int $pid, ?int $languageId): array
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
        }

        return $queryBuilder->execute()->fetchAll();
    }

    /**
     * @param string $limitToSite
     * @return array
     */
    protected function getSites($limitToSite): array
    {
        $sites = [];

        if ($limitToSite !== null) {
            $sites[] = GeneralUtility::makeInstance(SiteFinder::class)->getSiteByIdentifier($limitToSite);
        } else {
            $sites = GeneralUtility::makeInstance(SiteFinder::class)->getAllSites();
        }

        return $sites;
    }

    /**
     * @param Site $site
     * @param array $slugsToMigrate
     * @param $shouldAddRedirects
     */
    protected function migrateSlugs(Site $site, array $slugsToMigrate, $shouldAddRedirects = false): void
    {
        $dataForDataHandler = ['pages' => []];

        if ($shouldAddRedirects) {
            $dataForDataHandler['sys_redirect'] = [];
        }

        foreach ($slugsToMigrate as $slugData) {
            $uniqueId = uniqid('NEW_');
            $dataForDataHandler['pages'][$slugData['uid']] = ['slug' => $slugData['new']];
            if ($shouldAddRedirects) {
                $language = $site->getLanguageById((int)$slugData['language']);
                $dataForDataHandler['sys_redirect'][$uniqueId] = [
                    'pid' => 0,
                    'source_host' => $language->getBase()->getHost(),
                    'source_path' => $language->getBase()->getPath() . ltrim($slugData['old'], '/'),
                    'target' => 't3://page?uid=' . $slugData['uid'],
                    'target_statuscode' => 307,
                ];
            }
        }

        Bootstrap::initializeBackendAuthentication(true);
        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start($dataForDataHandler, []);
        $dataHandler->process_datamap();
    }
}
