<?php

namespace craftnet\console\controllers;

use Craft;
use craft\db\Query;
use craft\helpers\DateTimeHelper;
use craft\helpers\Db;
use craftnet\Module;
use craftnet\plugins\Plugin;
use DateTime;
use DateTimeZone;
use Github\AuthMethod;
use Github\Client as GithubClient;
use Github\ResultPager;
use Throwable;
use yii\console\Controller;
use yii\console\ExitCode;
use yii\helpers\ArrayHelper;
use yii\helpers\Console;

/**
 * Manages plugins.
 *
 * @property Module $module
 */
class PluginsController extends Controller
{
    /**
     * Displays info about all plugins
     *
     * @return int
     */
    public function actionInfo(): int
    {
        $formatter = Craft::$app->getFormatter();
        $total = $formatter->asDecimal(Plugin::find()->count(), 0);
        $totalAbandoned = $formatter->asDecimal(Plugin::find()->status(Plugin::STATUS_ABANDONED)->count(), 0);
        $totalPending = $formatter->asDecimal(Plugin::find()->status(Plugin::STATUS_PENDING)->count(), 0);

        $output = <<<OUTPUT
Total approved:  $total
Total abandoned: $totalAbandoned
Total pending:   $totalPending

OUTPUT;

        if ($totalPending) {
            $output .= "\nPending plugins:\n\n";
            $pending = Plugin::find()->status(Plugin::STATUS_PENDING)->all();
            $maxLength = max(array_map('mb_strlen', ArrayHelper::getColumn($pending, 'name'))) + 2;
            foreach ($pending as $plugin) {
                $output .= str_pad($plugin->name, $maxLength) . $plugin->getCpEditUrl() . "\n";
            }
        }

        $this->stdout($output);
        return ExitCode::OK;
    }

    /**
     * Updates plugin install counts
     *
     * @return int
     */
    public function actionUpdateInstallCounts(): int
    {
        $db = Craft::$app->getDb();

        $db
            ->createCommand('update craftnet_plugins as p set "activeInstalls" = (
    select count(*) from craftnet_cmslicense_plugins as lp
    where lp."pluginId" = p.id
    and lp.timestamp > :date
)', [
                'date' => Db::prepareDateForDb(new \DateTime('1 year ago')),
            ])
            ->execute();

        // Make sure we haven't inserted any historical data for today yet
        $timestamp = (new DateTime('now', new DateTimeZone('UTC')))->format('Y-m-d');
        $exists = (new Query())
            ->from('craftnet_plugin_installs')
            ->where(['date' => $timestamp])
            ->exists();

        if (!$exists) {
            $db
                ->createCommand('insert into craftnet_plugin_installs("pluginId", "activeInstalls", date) ' .
                    'select id, "activeInstalls", :date from craftnet_plugins', [
                    'date' => $timestamp,
                ])
                ->execute();
        }

        return ExitCode::OK;
    }

    /**
     * Updates plugin issue statistics
     *
     * @param string|null $pluginHandle The plugin handle to update. If empty, all plugins will be updated.
     *
     * @return int
     */
    public function actionUpdateIssueStats(?string $pluginHandle = null): int
    {
        $oathService = $this->module->getOauth();
        $periodDates = [
            30 => new DateTime('-30 days'),
            7 => new DateTime('-7 days'),
            3 => new DateTime('-3 days'),
            1 => new DateTime('-1 day'),
        ];

        foreach (Plugin::find()->handle($pluginHandle)->each() as $plugin) {
            /** @var Plugin $plugin */
            $parsedRepoUrl = $plugin->repository ? parse_url($plugin->repository) : null;

            if (!isset($parsedRepoUrl['host']) || $parsedRepoUrl['host'] !== 'github.com') {
                $this->stdout("- Skipping $plugin->name (invalid repo URL: $plugin->repository)\n", Console::FG_GREY);
                continue;
            }

            [$ghOwner, $ghRepo] = array_pad(explode('/', trim($parsedRepoUrl['path'], '/'), 2), 2, null);

            if (!$ghOwner || !$ghRepo) {
                $this->stdout("- Skipping $plugin->name (invalid repo URL: $plugin->repository)\n", Console::FG_GREY);
                continue;
            }

            try {
                // Get the GitHub auth token
                $token = $oathService->getAuthTokenByUserId('Github', $plugin->developerId);
                if (!$token) {
                    $this->stdout("- Skipping $plugin->name (no GitHub auth token)\n", Console::FG_GREY);
                    continue;
                }

                // Create an authenticated GitHub API client
                $client = new GithubClient();
                $client->authenticate($token, null, AuthMethod::ACCESS_TOKEN);
                $pager = new ResultPager($client);

                // Reference: https://docs.github.com/en/rest/reference/issues#list-repository-issues
                $allIssues = $pager->fetchAll($client->issues(), 'all', [
                    $ghOwner, $ghRepo, [
                        'state' => 'all',
                        'since' => $periodDates[30]->format(DateTime::ATOM),
                    ]
                ]);

                $totals = [
                    30 => [],
                    7 => [],
                    3 => [],
                    1 => [],
                ];

                foreach ($allIssues as $issue) {
                    if (isset($issue['pull_request'])) {
                        if (!empty($issue['pull_request']['merged_at'])) {
                            $counter = 'mergedPulls';
                        } else if ($issue['state'] === 'open') {
                            $counter = 'openPulls';
                        } else {
                            // Not counting closed PRs
                            continue;
                        }
                    } else if ($issue['state'] === 'open') {
                        $counter = 'openIssues';
                    } else {
                        $counter = 'closedIssues';
                    }

                    $timestamp = DateTimeHelper::toDateTime($issue['updated_at']);
                    foreach ($periodDates as $period => $periodDate) {
                        if ($period === 30 || $timestamp > $periodDate) {
                            if (!isset($totals[$period][$counter])) {
                                $totals[$period][$counter] = 1;
                            } else {
                                $totals[$period][$counter]++;
                            }
                        } else {
                            break;
                        }
                    }
                }

                $rows = [];
                $timestamp = Db::prepareDateForDb(new DateTime());

                foreach ($totals as $period => $periodTotals) {
                    $rows[] = [
                        $plugin->id,
                        $period,
                        $periodTotals['openIssues'] ?? 0,
                        $periodTotals['closedIssues'] ?? 0,
                        $periodTotals['openPulls'] ?? 0,
                        $periodTotals['mergedPulls'] ?? 0,
                        $timestamp,
                    ];
                }

                Db::batchInsert('craftnet_plugin_issue_stats', [
                    'pluginId',
                    'period',
                    'openIssues',
                    'closedIssues',
                    'openPulls',
                    'mergedPulls',
                    'dateUpdated',
                ], $rows, false);

                $this->stdout("- Updated $plugin->name\n", Console::FG_GREEN);
            } catch (Throwable $e) {
                $this->stderr("- Error updating $plugin->name: {$e->getMessage()}\n", Console::FG_RED);
            }
        }

        return ExitCode::OK;
    }
}
