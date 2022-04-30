<?php

namespace craftnet\composer;

use Composer\Repository\PlatformRepository;
use Composer\Semver\Comparator;
use Composer\Semver\Semver;
use Composer\Semver\VersionParser;
use Craft;
use craft\db\Query;
use craft\helpers\ArrayHelper;
use craft\helpers\Db;
use craft\helpers\Json;
use craftnet\ChangelogParser;
use craftnet\composer\jobs\UpdatePackage;
use craftnet\db\Table;
use craftnet\errors\MissingTokenException;
use craftnet\errors\VcsException;
use craftnet\helpers\Cache;
use craftnet\Module;
use craftnet\plugins\Plugin;
use UnexpectedValueException;
use yii\base\Component;
use yii\base\Exception;
use yii\base\InvalidArgumentException;
use yii\db\Expression;
use yii\helpers\Console;

/**
 * @property null|string $randomGitHubFallbackToken
 */
class PackageManager extends Component
{
    /**
     * @var string[]|null
     */
    public $githubFallbackTokens;

    /**
     * @var bool Whether plugins *must* have VCS tokens
     */
    public $requirePluginVcsTokens = true;

    /**
     * @var bool Whether we've already acquired a lock for updatePackages()
     */
    private $_acquiredLock = false;

    /**
     * @var array Stability sort orders
     * @see updatePluginReleaseOrder()
     */
    private $_stabilities = [
        'alpha' => 1,
        'beta' => 2,
        'RC' => 3,
        'stable' => 4,
    ];

    /**
     * @var array
     * @see getRelease()
     */
    private array $_releases = [];

    /**
     * @inheritdoc
     */
    public function init()
    {
        parent::init();

        if (is_string($this->githubFallbackTokens)) {
            $this->githubFallbackTokens = array_filter(explode(',', $this->githubFallbackTokens));
        }
    }

    /**
     * @param string $name
     * @return bool
     */
    public function packageExists(string $name): bool
    {
        return (new Query())
            ->from([Table::PACKAGES])
            ->where(['name' => $name])
            ->exists();
    }

    /**
     * @param string $name
     * @param array $constraints
     * @return bool
     */
    public function packageVersionsExist(string $name, array $constraints): bool
    {
        // Get all of the known versions for the package
        $versions = (new Query())
            ->select(['version'])
            ->distinct()
            ->from(['pv' => Table::PACKAGEVERSIONS])
            ->innerJoin(['p' => Table::PACKAGES], '[[p.id]] = [[pv.packageId]]')
            ->where([
                'p.name' => $name,
                'pv.valid' => true,
            ])
            ->column();

        // Make sure each of the constraints is satisfied by at least one of those versions
        foreach ($constraints as $constraint) {
            $satisfied = false;
            foreach ($versions as $version) {
                try {
                    if (Semver::satisfies($version, $constraint)) {
                        $satisfied = true;
                        break;
                    }
                } catch (UnexpectedValueException $e) {
                    // empty
                }
            }
            if (!$satisfied) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param string $name The package name
     * @param string $version The package version
     * @return PackageRelease|null
     */
    public function getRelease(string $name, string $version): ?PackageRelease
    {
        $version = (new VersionParser())->normalize($version);
        if (!isset($this->_releases[$name]) || !array_key_exists($version, $this->_releases[$name])) {
            $result = $this->createReleaseQuery($name, $version)->one();
            $this->_releases[$name][$version] = $result ? new PackageRelease($result) : null;
        }
        return $this->_releases[$name][$version];
    }

    /**
     * @param string $name The package name
     * @param string|null $minStability The minimum required stability (dev, alpha, beta, RC, or stable)
     * @param string|null $constraint The version constraint, if any
     * @param bool $sort Whether the versions should be sorted
     * @return string[] The known package versions
     */
    public function getAllVersions(string $name, ?string $minStability = 'stable', ?string $constraint = null, bool $sort = true): array
    {
        $query = (new Query())
            ->select(['pv.version'])
            ->distinct()
            ->from(['pv' => Table::PACKAGEVERSIONS])
            ->innerJoin(['p' => Table::PACKAGES], '[[p.id]] = [[pv.packageId]]')
            ->where([
                'p.name' => $name,
                'pv.valid' => true,
            ]);

        // Restrict to certain stabilities?
        if ($minStability !== null) {
            $allowedStabilities = $this->getStabilities($minStability);
            if (!empty($allowedStabilities)) {
                $query->andWhere(['pv.stability' => $allowedStabilities]);
            }
        }

        // Was a specific version requested by the constraint?
        if ($constraint !== null) {
            try {
                $version = (new VersionParser())->normalize($constraint);
                $query->andWhere(['pv.normalizedVersion' => $version]);
                $constraint = null;
            } catch (UnexpectedValueException $e) {
            }
        }

        $versions = $query->column();

        if ($constraint !== null) {
            $versions = array_filter($versions, function($version) use ($constraint) {
                return Semver::satisfies($version, $constraint);
            });
        }

        if ($sort) {
            $this->sortVersions($versions);
        }

        return $versions;
    }

    /**
     * @param string $name The package name
     * @param string|null $minStability The minimum required stability (dev, alpha, beta, RC, or stable)
     * @param string|null $constraint The version constraint, if any
     * @return string|null The latest version, or null if none can be found
     */
    public function getLatestVersion(string $name, ?string $minStability = 'stable', ?string $constraint = null): ?string
    {
        // Get all the versions
        $versions = $this->getAllVersions($name, $minStability, $constraint);

        // Return the last one
        return array_pop($versions);
    }

    /**
     * @param string $name The package name
     * @param string|null $minStability The minimum required stability
     * @param string|null $constraint The version constraint, if any
     * @return PackageRelease|null The latest release, or null if none can be found
     */
    public function getLatestRelease(string $name, ?string $minStability = 'stable', ?string $constraint = null): ?PackageRelease
    {
        $version = $this->getLatestVersion($name, $minStability, $constraint);
        return $version ? $this->getRelease($name, $version) : null;
    }

    /**
     * Returns all the versions after a given version
     *
     * @param string $name The package name
     * @param string $from The version that others should be after
     * @param string|null $minStability The minimum required stability
     * @param string|null $constraint The version constraint, if any
     * @param bool $sort Whether the versions should be sorted
     * @return string[] The versions after $from, sorted oldest-to-newest
     */
    public function getVersionsAfter(string $name, string $from, ?string $minStability = 'stable', ?string $constraint = null, bool $sort = true): array
    {
        $vp = new VersionParser();
        $from = $vp->normalize($from);

        // Get all the versions
        $versions = $this->getAllVersions($name, $minStability, $constraint, false);

        // Filter out the ones <= $from
        $versions = array_filter($versions, function($version) use ($vp, $from) {
            return Comparator::greaterThan($vp->normalize($version), $from);
        });

        if ($sort) {
            $this->sortVersions($versions);
        }

        return $versions;
    }

    /**
     * Returns all the versions after a given version and up to (and including) another version
     *
     * @param string $name The package name
     * @param string $from The version that others should be after
     * @param string $to The version that others should be before or equal to
     * @param string|null $minStability The minimum required stability
     * @param string|null $constraint The version constraint, if any
     * @param bool $sort Whether the versions should be sorted
     * @return string[] The versions after $from and up to $to, sorted oldest-to-newest
     */
    public function getVersionsBetween(
        string $name,
        string $from,
        string $to,
        ?string $minStability = 'stable',
        ?string $constraint = null,
        bool $sort = true
    ): array {
        $vp = new VersionParser();
        $from = $vp->normalize($from);
        $to = $vp->normalize($to);

        // Get all the versions
        $versions = $this->getAllVersions($name, $minStability, $constraint, false);

        // Filter out the ones <= $from
        $versions = array_filter($versions, function($version) use ($vp, $from, $to) {
            $version = $vp->normalize($version);
            return (Comparator::greaterThan($version, $from) && Comparator::lessThanOrEqualTo($version, $to));
        });

        if ($sort) {
            $this->sortVersions($versions);
        }

        return $versions;
    }

    /**
     * Returns all the releases for a package.
     *
     * @param string $name The package name
     * @param string|null $minStability The minimum required stability
     * @param string|null $constraint The version constraint, if any
     * @param bool $sort Whether the releases should be sorted by version
     * @return PackageRelease[] The releases, sorted oldest-to-newest
     */
    public function getAllReleases(string $name, ?string $minStability = 'stable', ?string $constraint = null, bool $sort = true): array
    {
        if ($minStability !== null || $constraint !== null) {
            $versions = $this->getAllVersions($name, $minStability, $constraint, false);
        } else {
            $versions = null;
        }

        $results = $this->createReleaseQuery($name, $versions)->all();
        $releases = [];

        foreach ($results as $result) {
            $releases[] = new PackageRelease($result);
        }

        if ($sort) {
            // Sort them oldest-to-newest
            $this->sortVersions($releases);
        }

        return $releases;
    }

    /**
     * Returns all the releases after a given version
     *
     * @param string $name The package name
     * @param string $from The version that others should be after
     * @param string|null $minStability The minimum required stability
     * @param string|null $constraint The version constraint, if any
     * @return PackageRelease[] The releases after $from, sorted oldest-to-newest
     */
    public function getReleasesAfter(string $name, string $from, ?string $minStability = 'stable', ?string $constraint = null): array
    {
        $versions = $this->getVersionsAfter($name, $from, $minStability, $constraint, false);
        $results = $this->createReleaseQuery($name, $versions)->all();
        $releases = [];

        foreach ($results as $result) {
            $releases[] = new PackageRelease($result);
        }

        // Sort them oldest-to-newest
        $this->sortVersions($releases);

        return $releases;
    }

    /**
     * @param string $minStability The minimum required stability (dev, alpha, beta, RC, or stable)
     * @return string[] The allowed stabilities, or an empty array if all stabilities should be allowed
     */
    public function getStabilities(string $minStability = 'stable'): array
    {
        $allowedStabilities = [];

        switch ($minStability) {
            // no break
            case 'alpha':
                $allowedStabilities[] = 'alpha';
            // no break
            case 'beta':
                $allowedStabilities[] = 'beta';
            // no break
            case 'RC':
                $allowedStabilities[] = 'RC';
            // no break
            case 'stable':
                $allowedStabilities[] = 'stable';
        }

        return $allowedStabilities;
    }

    /**
     * Sorts a given list of versions.
     *
     * @param string[]|PackageRelease[]|array[] &$versions
     * @param int $dir The sort direction (SORT_ASC = oldest -> newest; SORT_DESC = newest -> oldest)
     */
    public function sortVersions(array &$versions, int $dir = SORT_ASC): void
    {
        $vp = new VersionParser();

        usort($versions, function($a, $b) use ($vp, $dir): int {
            if ($a instanceof PackageRelease) {
                $a = $a->version;
            } else if (is_array($a) && isset($a['version'])) {
                $a = $a['version'];
            }

            if ($b instanceof PackageRelease) {
                $b = $b->version;
            } else if (is_array($b) && isset($b['version'])) {
                $b = $b['version'];
            }

            $a = $vp->normalize($a);
            $b = $vp->normalize($b);

            if (Comparator::equalTo($a, $b)) {
                return 0;
            }
            if (Comparator::lessThan($a, $b)) {
                return $dir === SORT_ASC ? -1 : 1;
            }
            return $dir === SORT_ASC ? 1 : -1;
        });
    }

    /**
     * @param int|array $versionId The package version ID(s)
     * @return string|array|null
     */
    public function getPhpConstraintByVersionId(int|array $versionId): string|array|null
    {
        $query = (new Query())
            ->from([Table::PACKAGEDEPS])
            ->where(['versionId' => $versionId, 'name' => 'php']);

        if (is_int($versionId)) {
            return $query
                ->select(['constraints'])
                ->scalar() ?: null;
        }

        return $query
            ->select(['versionId', 'constraints'])
            ->pairs();
    }

    /**
     * @param string $name The dependency package name
     * @param string $version The dependency package version
     * @return bool Whether any managed packages require this dependency/version
     */
    public function isDependencyVersionRequired(string $name, string $version): bool
    {
        $constraints = (new Query())
            ->select(['constraints'])
            ->distinct()
            ->from([Table::PACKAGEDEPS])
            ->where(['name' => $name])
            ->column();

        foreach ($constraints as $constraint) {
            if (Semver::satisfies($version, $constraint)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param Package $package
     */
    public function savePackage(Package $package): void
    {
        $data = [
            'developerId' => $package->developerId,
            'name' => $package->name,
            'type' => $package->type,
            'managed' => $package->managed,
            'repository' => $package->repository,
            'abandoned' => $package->abandoned,
            'replacementPackage' => $package->replacementPackage,
        ];

        $db = Craft::$app->getDb();

        if ($package->id === null) {
            $db->createCommand()
                ->insert(Table::PACKAGES, $data)
                ->execute();
            $package->id = (int)$db->getLastInsertID(Table::PACKAGES);
        } else {
            $db->createCommand()
                ->update(Table::PACKAGES, $data, ['id' => $package->id])
                ->execute();
        }
    }

    /**
     * @param string|Package $package
     */
    public function removePackage($package): void
    {
        if (is_string($package)) {
            $package = $this->getPackage($package);
        }

        try {
            $this->deleteWebhook($package->name);
        } catch (Exception $e) {
        }

        Craft::$app->getDb()->createCommand()
            ->delete(Table::PACKAGES, ['name' => $package->name])
            ->execute();
    }

    /**
     * Returns all package names.
     *
     * @return string[]
     */
    public function getPackageNames(): array
    {
        return $this->_createPackageQuery()
            ->select(['name'])
            ->column();
    }

    /**
     * @param string $name
     * @return Package
     * @throws InvalidArgumentException
     */
    public function getPackage(string $name): Package
    {
        $result = $this->_createPackageQuery()
            ->where(['name' => $name])
            ->one();
        if (!$result) {
            throw new InvalidArgumentException('Invalid package name: ' . $name);
        }
        return new Package($result);
    }

    /**
     * @param int $id
     * @return Package
     * @throws Exception
     */
    public function getPackageById(int $id): Package
    {
        $result = $this->_createPackageQuery()
            ->where(['id' => $id])
            ->one();
        if (!$result) {
            throw new Exception('Invalid package ID: ' . $id);
        }
        return new Package($result);
    }

    /**
     * @param string $url
     * @return string|null
     */
    public function getPackageNameByRepoUrl(string $url): ?string
    {
        return $this->_createPackageQuery()
            ->select(['name'])
            ->where(new Expression('lower([[repository]]) = :url', [':url' => strtolower($url)]))
            ->scalar();
    }

    /**
     * Creates a VCS webhook for a given package.
     *
     * @param string|Package $package The package or its name
     * @param bool $force Whether the webhook should be created even if one already exists
     * @throws Exception if the package couldn't be found
     */
    public function createWebhook($package, bool $force = false): void
    {
        if (is_string($package)) {
            $package = $this->getPackage($package);
        }

        $isConsole = Craft::$app->getRequest()->getIsConsoleRequest();

        // Does the package already have a webhook registered?
        if ($package->webhookId) {
            if (!$force) {
                if ($isConsole) {
                    Console::output("A webhook for {$package->name} already exists.");
                }
                return;
            }

            $this->deleteWebhook($package);
        }

        $package->webhookId = null;
        $package->webhookSecret = Craft::$app->getSecurity()->generateRandomString();

        // Store the secret first so we're ready for the VCS's test hook request
        Craft::$app->getDb()->createCommand()
            ->update(
                Table::PACKAGES,
                ['webhookSecret' => $package->webhookSecret],
                ['id' => $package->id])
            ->execute();

        try {
            $package->getVcs()->createWebhook();
        } catch (\Throwable $e) {
            Craft::warning("Could not create a webhook for {$package->name}: {$e->getMessage()}", __METHOD__);
            Craft::$app->getErrorHandler()->logException($e->getPrevious() ?? $e);

            if ($isConsole) {
                Console::error("Could not create a webhook for {$package->name}: {$e->getMessage()}");
            }

            // Clear out the secret
            $package->webhookSecret = null;
            Craft::$app->getDb()->createCommand()
                ->update(
                    Table::PACKAGES,
                    ['webhookSecret' => null],
                    ['id' => $package->id])
                ->execute();

            return;
        }

        // Store the new ID
        Craft::$app->getDb()->createCommand()
            ->update(
                Table::PACKAGES,
                ['webhookId' => $package->webhookId],
                ['id' => $package->id])
            ->execute();

        if ($isConsole) {
            Console::output("Webhook created for {$package->name}.");
        }
    }

    /**
     * Deletes a VCS webhook for a given package.
     *
     * @param string|Package $package The package or its name
     * @throws Exception if the package couldn't be found
     */
    public function deleteWebhook($package): void
    {
        if (is_string($package)) {
            $package = $this->getPackage($package);
        }

        $isConsole = Craft::$app->getRequest()->getIsConsoleRequest();

        if (!$package->webhookId) {
            if ($isConsole) {
                Console::output("No webhook for {$package->name} exists.");
            }
            return;
        }

        try {
            $package->getVcs()->deleteWebhook();
        } catch (VcsException $e) {
            Craft::warning("Could not delete the webhook for {$package->name}: {$e->getMessage()}", __METHOD__);
            Craft::$app->getErrorHandler()->logException($e->getPrevious() ?? $e);
            if ($isConsole) {
                Console::error("Could not delete the webhook for {$package->name}: {$e->getMessage()}");
            }
            return;
        }

        // Remove our record of it
        Craft::$app->getDb()->createCommand()
            ->update(
                Table::PACKAGES,
                [
                    'webhookId' => null,
                    'webhookSecret' => null,
                ],
                ['id' => $package->id])
            ->execute();

        if ($isConsole) {
            Console::output("Webhook deleted for {$package->name}.");
        }
    }

    /**
     * @param string|null $name
     * @param string|string[]|null $version
     * @return Query
     */
    public function createReleaseQuery(?string $name = null, $version = null): Query
    {
        $query = (new Query())
            ->select([
                'pv.id',
                'pv.packageId',
                'pv.sha',
                'pv.description',
                'pv.version',
                'pv.type',
                'pv.keywords',
                'pv.homepage',
                'pv.time',
                'pv.license',
                'pv.authors',
                'pv.support',
                'pv.conflict',
                'pv.replace',
                'pv.provide',
                'pv.suggest',
                'pv.autoload',
                'pv.includePaths',
                'pv.targetDir',
                'pv.extra',
                'pv.binaries',
                'pv.source',
                'pv.dist',
                'pv.changelog',
                'pv.date',
                'pv.critical',
                'pv.notes',
            ])
            ->from(['pv' => Table::PACKAGEVERSIONS])
            ->innerJoin(['p' => Table::PACKAGES], '[[p.id]] = [[pv.packageId]]')
            ->where(['pv.valid' => true]);

        if ($name !== null) {
            $query->andWhere(['p.name' => $name]);
        }

        if ($version !== null) {
            $vp = new VersionParser();
            if (is_array($version)) {
                foreach ($version as $k => $v) {
                    $version[$k] = $vp->normalize($v);
                }
            } else if (is_string($version)) {
                $version = $vp->normalize($version);
            }
            $query->andWhere(['pv.normalizedVersion' => $version]);
        }

        return $query;
    }

    /**
     * @param string $name The Composer package name
     * @param bool $force Whether to update package releases even if their SHA hasn't changed
     * @param bool $queue Whether to queue the update
     * @param bool $dumpJson Whether to update the JSON if anything changed
     * @param string|null $onlyVersion The specific version to update
     * @return int The total number of added/removed package releases
     * @throws InvalidArgumentException if the package name doesn't exist
     * @throws MissingTokenException if the package is a plugin, but we don't have a VCS token for it
     * @throws \Throwable if reasons
     */
    public function updatePackage(string $name, bool $force = false, bool $queue = false, bool $dumpJson = false, ?string $onlyVersion = null): int
    {
        if ($queue) {
            Craft::$app->getQueue()->push(new UpdatePackage([
                'name' => $name,
                'force' => $force,
                'dumpJson' => $dumpJson,
            ]));
            return 0;
        }

        $isConsole = Craft::$app->getRequest()->getIsConsoleRequest();

        $acquiredLock = false;
        if (!$this->_acquiredLock) {
            if ($isConsole) {
                Console::stdout("Acquiring a lock to update {$name} ... ");
            }

            $mutex = Craft::$app->getMutex();

            if (!$mutex->acquire(__METHOD__, 10)) {
                if ($isConsole) {
                    Console::output('failed');
                }
                throw new Exception("Failed to acquire a lock to update {$name}.");
            }

            $this->_acquiredLock = $acquiredLock = true;

            if ($isConsole) {
                Console::output('done');
            }
        }

        $hasValidNewVersion = false;
        $latestNewRelease = null;
        $vp = new VersionParser();

        // Start a transaction
        $transaction = Craft::$app->getDb()->beginTransaction();
        try {
            $package = $this->getPackage($name);
            $vcs = $package->getVcs();
            $plugin = $package->getPlugin();
            $db = Craft::$app->getDb();

            if ($isConsole) {
                Console::output("Updating version data for {$name} ...");
            }

            // Get all of the already known versions (including invalid releases)
            $storedVersionInfo = (new Query())
                ->select(['id', 'version', 'normalizedVersion', 'sha'])
                ->from([Table::PACKAGEVERSIONS])
                ->where(['packageId' => $package->id])
                ->indexBy('normalizedVersion')
                ->all();

            // Get the versions from the VCS
            $vcsVersionInfo = [];
            foreach ($vcs->getVersions() as $version => $sha) {
                // Don't include development versions, and versions that aren't actually required by any managed packages
                if (($stability = VersionParser::parseStability($version)) === 'dev') {
                    if ($isConsole) {
                        Console::output(Console::ansiFormat("- skipping {$version} ({$sha}) - dev stability", [Console::FG_RED]));
                    }
                    continue;
                }

                // Don't include invalid versions
                try {
                    $normalizedVersion = (new VersionParser())->normalize($version);
                } catch (UnexpectedValueException $e) {
                    if ($isConsole) {
                        Console::output(Console::ansiFormat("- skipping {$version} ({$sha}) - invalid version", [Console::FG_RED]));
                    }
                    continue;
                }

                // Don't include duplicate versions
                if (isset($vcsVersionInfo[$normalizedVersion])) {
                    if ($isConsole) {
                        Console::output(Console::ansiFormat("- skipping {$version} ({$sha}) - duplicate version", [Console::FG_RED]));
                    }
                    continue;
                }

                if (!$package->managed && !$this->isDependencyVersionRequired($package->name, $version)) {
                    if ($isConsole) {
                        Console::output(Console::ansiFormat("- skipping {$version} ({$sha}) - not required", [Console::FG_RED]));
                    }
                    continue;
                }

                // It's a keeper
                $vcsVersionInfo[$normalizedVersion] = [
                    'version' => $version,
                    'stability' => $stability,
                    'sha' => $sha,
                ];
            }

            // See which already-stored versions have been deleted/updated
            $normalizedStoredVersions = array_keys($storedVersionInfo);
            $normalizedVcsVersions = array_keys($vcsVersionInfo);

            $deletedVersions = array_diff($normalizedStoredVersions, $normalizedVcsVersions);
            $newVersions = array_diff($normalizedVcsVersions, $normalizedStoredVersions);

            if ($onlyVersion) {
                $onlyVersion = (new VersionParser())->normalize($onlyVersion);
            }

            $updatedVersions = [];
            foreach (array_intersect($normalizedStoredVersions, $normalizedVcsVersions) as $version) {
                if (
                    ($onlyVersion === null || $version === $onlyVersion) &&
                    ($force || $storedVersionInfo[$version]['sha'] !== $vcsVersionInfo[$version]['sha'])
                ) {
                    $updatedVersions[] = $version;
                }
            }

            if ($isConsole) {
                Console::stdout(Console::ansiFormat('- new: ', [Console::FG_YELLOW]));
                Console::output(count($newVersions));
                Console::stdout(Console::ansiFormat('- updated: ', [Console::FG_YELLOW]));
                Console::output(count($updatedVersions));
                Console::stdout(Console::ansiFormat('- deleted: ', [Console::FG_YELLOW]));
                Console::output(count($deletedVersions));
            }

            if (!empty($deletedVersions) || !empty($updatedVersions)) {
                if ($isConsole) {
                    Console::stdout('Deleting old versions ... ');
                }

                $versionIdsToDelete = [];
                foreach (array_merge($deletedVersions, $updatedVersions) as $version) {
                    $versionIdsToDelete[] = $storedVersionInfo[$version]['id'];
                }

                $db->createCommand()
                    ->delete(Table::PACKAGEVERSIONS, ['id' => $versionIdsToDelete])
                    ->execute();

                if ($isConsole) {
                    Console::output('done.');
                }
            }

            // We can treat "updated" versions as "new" now.
            $newVersions = array_merge($updatedVersions, $newVersions);
            $packageDeps = [];

            // Process new versions
            if (!empty($newVersions)) {
                if ($isConsole) {
                    Console::output('Processing new versions ...');
                }

                // Sort by newest => oldest
                $this->sortVersions($newVersions, SORT_DESC);

                foreach ($newVersions as $normalizedVersion) {
                    $version = $vcsVersionInfo[$normalizedVersion]['version'];
                    $sha = $vcsVersionInfo[$normalizedVersion]['sha'];

                    if ($isConsole) {
                        Console::stdout(Console::ansiFormat("- processing {$version} ({$sha}) ... ", [Console::FG_YELLOW]));
                    }

                    $release = new PackageRelease([
                        'packageId' => $package->id,
                        'version' => $version,
                        'sha' => $sha,
                    ]);

                    $vcs->populateRelease($release);

                    if ($release->valid) {
                        $hasValidNewVersion = true;

                        if ($latestNewRelease === null) {
                            $latestNewRelease = $release;
                        }
                    } else if ($isConsole) {
                        Console::stdout(Console::ansiFormat('invalid' . ($release->invalidReason ? " ({$release->invalidReason})" : ''), [Console::FG_RED]));
                        Console::stdout(Console::ansiFormat(' ... ', [Console::FG_YELLOW]));
                    }

                    $this->savePackageRelease($release);

                    if (!empty($release->require)) {
                        $depValues = [];
                        foreach ($release->require as $depName => $constraints) {
                            if (trim($constraints) === 'self.version') {
                                $constraints = $release->version;
                            }

                            $depValues[] = [$package->id, $release->id, $depName, $constraints];

                            if (
                                $depName !== '__root__' &&
                                $depName !== 'composer-plugin-api' &&
                                !preg_match(PlatformRepository::PLATFORM_PACKAGE_REGEX, $depName) &&
                                strpos($depName, 'bower-asset/') !== 0 &&
                                strpos($depName, 'npm-asset/') !== 0
                            ) {
                                $packageDeps[$depName][$constraints] = true;
                            }
                        }
                        $db->createCommand()
                            ->batchInsert(Table::PACKAGEDEPS, ['packageId', 'versionId', 'name', 'constraints'], $depValues)
                            ->execute();
                    }

                    if ($release->valid) {
                        if ($package->name === 'craftcms/cms') {
                            $this->updatePluginCompatIndexForCmsRelease($release);
                        } else if ($plugin !== null) {
                            $this->updatePluginCompatIndexForPluginRelease($release);
                        }
                    }

                    if ($isConsole) {
                        Console::output(Console::ansiFormat('done.', [Console::FG_YELLOW]));
                    }
                }

                if ($isConsole) {
                    Console::output('Done processing ' . count($newVersions) . ' versions.');
                }

                if ($plugin !== null) {
                    $this->updatePluginReleaseOrder($plugin);
                }

                Cache::invalidate(Cache::TAG_PACKAGES);
            } else {
                if ($isConsole) {
                    Console::output('No new versions to process.');
                }
            }

            $totalAffected = count($deletedVersions) + count($newVersions);

            // For each dependency, see if we already have a version that satisfies the conditions
            if (!empty($packageDeps)) {
                $depsToUpdate = [];
                foreach ($packageDeps as $depName => $depVersions) {
                    $update = false;
                    if (!$this->packageExists($depName)) {
                        if ($isConsole) {
                            Console::stdout("Adding dependency {$depName} ... ");
                        }
                        $this->savePackage(new Package([
                            'name' => $depName,
                            'type' => 'library',
                            'managed' => false,
                        ]));
                        if ($isConsole) {
                            Console::output('done.');
                        }
                        $update = true;
                    } else if (!$this->packageVersionsExist($depName, array_keys($depVersions))) {
                        $update = true;
                    }
                    if ($update) {
                        $depsToUpdate[] = $depName;
                    }
                }

                if (!empty($depsToUpdate)) {
                    if ($isConsole) {
                        Console::output('Updating missing dependencies ...');
                    }

                    foreach ($depsToUpdate as $depName) {
                        $totalAffected += $this->updatePackage($depName, $force);
                    }
                }
            }

            if ($dumpJson && $totalAffected !== 0) {
                Module::getInstance()->getJsonDumper()->dump();
            }

            $transaction->commit();
        } catch (\Throwable $exception) {
            $transaction->rollBack();
            // throw the exception later
        }

        $totalAffected = 0;

        if ($acquiredLock) {
            if ($isConsole && !isset($exception)) {
                Console::stdout('Releasing the lock ... ');
            }

            /** @noinspection PhpUndefinedVariableInspection */
            $mutex->release(__METHOD__);

            if ($isConsole && !isset($exception)) {
                Console::output('done');
            }
        }

        if (isset($exception)) {
            throw $exception;
        }

        // Did we just update the latest version of the package, for its stability level?
        if ($latestNewRelease) {
            $latestNewVersion = $latestNewRelease->getNormalizedVersion();
            $stability = VersionParser::parseStability($latestNewVersion);
            if ($latestNewVersion === $vp->normalize($this->getLatestVersion($name, $stability))) {
                $this->processPackageChangelog($name, $latestNewVersion);
            }
        }

        // Did we just save the first version of an already approved plugin?
        if (
            $hasValidNewVersion &&
            isset($plugin) &&
            $plugin->enabled
        ) {
            $plugin->publish();
        }

        // Clear caches
        unset($this->_releases[$name]);

        return $totalAffected;
    }

    /**
     * @param Plugin $plugin
     */
    public function updatePluginReleaseOrder(Plugin $plugin): void
    {
        $isConsole = Craft::$app->getRequest()->getIsConsoleRequest();

        if ($isConsole) {
            Console::stdout('Updating plugin release order ... ');
        }

        // Delete existing release order data
        $db = Craft::$app->getDb();
        $db->createCommand()
            ->delete(Table::PLUGINVERSIONORDER, ['pluginId' => $plugin->id])
            ->execute();

        // Get the new plugin releases
        $releases = $this->createReleaseQuery($plugin->packageName)
            ->select([
                'pv.id',
                'version' => 'pv.normalizedVersion',
                'pv.stability',
            ])
            ->indexBy('id')
            ->all();

        // get versionId => version map
        $versions = array_column($releases, 'version', 'id');

        // sort it by version oldest-to-newest, preserving versionId keys
        uasort($versions, function($a, $b): int {
            return Comparator::equalTo($a, $b) ? 0 : (Comparator::lessThan($a, $b) ? -1 : 1);
        });

        // versionId => sort order
        $releaseOrder = array_flip(array_keys($versions));

        // Create our multisort arrays
        $stabilities = [];
        $orders = [];
        $releaseIds = [];
        foreach ($releases as &$release) {
            $stabilities[] = $this->_stabilities[$release['stability']];
            $orders[] = $releaseOrder[$release['id']];
            $releaseIds[] = $release['id'];
        }
        unset($release);

        // versionId => stability-inspired sort order
        array_multisort($stabilities, SORT_NUMERIC, $orders, SORT_NUMERIC, $releaseIds);
        $releaseStableOrder = array_flip($releaseIds);

        // build and insert the order data
        $orderData = [];

        foreach ($releases as $releaseId => $release) {
            $orderData[] = [
                $release['id'],
                $plugin->id,
                $releaseOrder[$releaseId],
                $releaseStableOrder[$releaseId],
            ];
        }

        $db->createCommand()
            ->batchInsert(Table::PLUGINVERSIONORDER, [
                'versionId',
                'pluginId',
                'order',
                'stableOrder',
            ], $orderData, false)
            ->execute();

        if ($isConsole) {
            Console::output('done');
        }
    }

    /**
     * @param PackageRelease $cmsRelease
     */
    public function updatePluginCompatIndexForCmsRelease(PackageRelease $cmsRelease): void
    {
        if (Craft::$app->getRequest()->getIsConsoleRequest()) {
            Console::stdout(Console::ansiFormat('updating compatibility index ... ', [Console::FG_YELLOW]));
        }

        // fetch all plugin releases ever, sorted by newest to oldest
        $pluginData = (new Query())
            ->select([
                'v.packageId',
                'versionId' => 'v.id',
                'v.version',
                'd.constraints',
            ])
            ->from(['v' => Table::PACKAGEVERSIONS])
            ->innerJoin(['o' => Table::PLUGINVERSIONORDER], '[[o.versionId]] = [[v.id]]')
            ->innerJoin(['d' => Table::PACKAGEDEPS], [
                'and',
                '[[d.versionId]] = [[v.id]]',
                ['d.name' => 'craftcms/cms'],
            ])
            ->orderBy(['o.order' => SORT_DESC])
            ->all();

        $pluginData = ArrayHelper::index($pluginData, null, 'packageId');
        $compatData = [];

        foreach ($pluginData as $releases) {
            $foundMatch = false;
            foreach ($releases as $release) {
                if (Semver::satisfies($cmsRelease->version, $this->tightenConstraint($release['constraints']))) {
                    $compatData[] = [$release['versionId'], $cmsRelease->id];
                    $foundMatch = true;
                } else if ($foundMatch) {
                    // if this release wasn't a match, chances are older releases won't be a match either
                    break;
                }
            }
        }

        Craft::$app->getDb()->createCommand()
            ->batchInsert(Table::PLUGINVERSIONCOMPAT, [
                'pluginVersionId',
                'cmsVersionId',
            ], $compatData, false)
            ->execute();
    }

    /**
     * @param PackageRelease $pluginRelease
     */
    public function updatePluginCompatIndexForPluginRelease(PackageRelease $pluginRelease): void
    {
        $isConsole = Craft::$app->getRequest()->getIsConsoleRequest();

        if (!isset($pluginRelease->require['craftcms/cms'])) {
            if ($isConsole) {
                Console::stdout(Console::ansiFormat('no craftcms/cms requirement', [Console::FG_RED]));
                Console::stdout(Console::ansiFormat(' ... ', [Console::FG_YELLOW]));
            }
            return;
        }

        if ($isConsole) {
            Console::stdout(Console::ansiFormat('updating compatibility index ... ', [Console::FG_YELLOW]));
        }

        $compatData = [];
        $cmsConstraint = $this->tightenConstraint($pluginRelease->require['craftcms/cms']);

        foreach ($this->getAllReleases('craftcms/cms', null) as $cmsRelease) {
            if (Semver::satisfies($cmsRelease->version, $cmsConstraint)) {
                $compatData[] = [$pluginRelease->id, $cmsRelease->id];
            }
        }

        Craft::$app->getDb()->createCommand()
            ->batchInsert(Table::PLUGINVERSIONCOMPAT, [
                'pluginVersionId',
                'cmsVersionId',
            ], $compatData, false)
            ->execute();
    }

    /**
     * Tightens `>=` constraints to `^` because `>=` shouldn’t exist.
     *
     * @param string $constraint
     * @return string
     */
    private function tightenConstraint(string $constraint): string
    {
        return str_replace('>=', '^', $constraint);
    }

    /**
     * @param PackageRelease $release
     */
    public function savePackageRelease(PackageRelease $release): void
    {
        $db = Craft::$app->getDb();
        $db->createCommand()
            ->insert(Table::PACKAGEVERSIONS, [
                'packageId' => $release->packageId,
                'sha' => $release->sha,
                'description' => $release->description,
                'version' => $release->version,
                'normalizedVersion' => $release->getNormalizedVersion(),
                'stability' => $release->getStability(),
                'type' => $release->type,
                'keywords' => $release->keywords ? Json::encode($release->keywords) : null,
                'homepage' => $release->homepage,
                'time' => $release->time,
                'license' => $release->license ? Json::encode($release->license) : null,
                'authors' => $release->authors ? Json::encode($release->authors) : null,
                'support' => $release->support ? Json::encode($release->support) : null,
                'conflict' => $release->conflict ? Json::encode($release->conflict) : null,
                'replace' => $release->replace ? Json::encode($release->replace) : null,
                'provide' => $release->provide ? Json::encode($release->provide) : null,
                'suggest' => $release->suggest ? Json::encode($release->suggest) : null,
                'autoload' => $release->autoload ? Json::encode($release->autoload) : null,
                'includePaths' => $release->includePaths ? Json::encode($release->includePaths) : null,
                'targetDir' => $release->targetDir,
                'extra' => $release->extra ? Json::encode($release->extra) : null,
                'binaries' => $release->binaries ? Json::encode($release->binaries) : null,
                'source' => $release->source ? Json::encode($release->source) : null,
                'dist' => $release->dist ? Json::encode($release->dist) : null,
                'changelog' => $release->changelog,
                'valid' => $release->valid,
            ])
            ->execute();
        $release->id = (int)$db->getLastInsertID(Table::PACKAGEVERSIONS);
    }

    /**
     * Updates a package’s releases’ dates, critical flags, and notes based on its changelog.
     *
     * @param string $name The package name
     * @param string|null The version to fetch the changelog from. If `null` the latest version will be used
     * @throws InvalidArgumentException if `$version` is unknown
     */
    public function processPackageChangelog(string $name, ?string $version = null): void
    {
        // Get all the releases
        /** @var PackageRelease[] $releases */
        $releases = ArrayHelper::index($this->getAllReleases($name, null), 'normalizedVersion');

        if (empty($releases)) {
            return;
        }

        if ($version !== null) {
            $normalizedVersion = (new VersionParser())->normalize($version);
            if (!isset($releases[$normalizedVersion])) {
                throw new InvalidArgumentException("Unknown version: $version");
            }
            $sourceRelease = $releases[$normalizedVersion];
        } else {
            // Just go with the latest release
            $sourceRelease = end($releases);
        }

        if (!$sourceRelease->changelog) {
            return;
        }

        $releaseInfo = (new ChangelogParser)->parse($sourceRelease->changelog, null, array_keys($releases));
        $db = Craft::$app->getDb();

        foreach ($releaseInfo as $version => $info) {
            $release = $releases[$version];

            // Only update if something has changed
            if (
                $release->date !== $info['date'] ||
                $release->critical !== $info['critical'] ||
                $release->notes !== $info['notes']
            ) {
                $db->createCommand()
                    ->update(Table::PACKAGEVERSIONS, [
                        'date' => Db::prepareDateForDb($info['date']),
                        'critical' => $info['critical'],
                        'notes' => $info['notes'],
                    ], ['id' => $release->id], [], false)
                    ->execute();
            }
        }
    }

    /**
     * Updates all of the unmanaged package dependencies.
     *
     * @param bool $force Whether to update package releases even if their SHA hasn't changed
     * @param bool $queue Whether to queue the updates
     * @param array|null $errors Any errors that occur when updating
     */
    public function updateDeps(bool $force = false, bool $queue = false, array &$errors = null): void
    {
        Craft::info('Starting to update package dependencies.', __METHOD__);

        $names = $this->_createPackageQuery()
            ->select(['name'])
            ->where(['managed' => false])
            ->column();

        $errors = [];
        foreach ($names as $name) {
            try {
                $this->updatePackage($name, $force, $queue);
            } catch (\Throwable $e) {
                // log and keep going
                Craft::warning("Error updating package {$name}: {$e->getMessage()}", __METHOD__);
                Craft::$app->getErrorHandler()->logException($e);
                $errors[$name][] = $e->getMessage();
            }
        }

        Craft::info('Done updating package dependencies.', __METHOD__);
    }

    /**
     * Updates all of the managed package dependencies.
     *
     * @param bool $force Whether to update package releases even if their SHA hasn't changed
     * @param bool $queue Whether to queue the updates
     * @param array|null $errors Any errors that occur when updating
     */
    public function updateManagedPackages(bool $force = false, bool $queue = false, array &$errors = null): void
    {
        Craft::info('Starting to update managed packages.', __METHOD__);

        $names = $this->_createPackageQuery()
            ->select(['name'])
            ->where(['managed' => true])
            ->column();

        $errors = [];
        foreach ($names as $name) {
            try {
                $this->updatePackage($name, $force, $queue);
            } catch (\Throwable $e) {
                // log and keep going
                Craft::warning("Error updating package {$name}: {$e->getMessage()}", __METHOD__);
                Craft::$app->getErrorHandler()->logException($e);
                $errors[$name][] = $e->getMessage();
            }
        }

        Craft::info('Done updating managed packages.', __METHOD__);
    }

    /**
     * Returns a random fallback GitHub API token.
     *
     * @return string|null
     */
    public function getRandomGitHubFallbackToken(): ?string
    {
        if (empty($this->githubFallbackTokens)) {
            return null;
        }

        $key = array_rand($this->githubFallbackTokens);
        return $this->githubFallbackTokens[$key];
    }

    /**
     * @return Query
     */
    private function _createPackageQuery(): Query
    {
        return (new Query())
            ->select(['id', 'developerId', 'name', 'type', 'repository', 'managed', 'abandoned', 'replacementPackage', 'webhookId', 'webhookSecret'])
            ->from([Table::PACKAGES]);
    }
}
