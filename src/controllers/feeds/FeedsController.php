<?php

namespace craftnet\controllers\feeds;

use Craft;
use craft\db\Query;
use craft\helpers\DateTimeHelper;
use craft\helpers\Db;
use craft\helpers\UrlHelper;
use craft\web\Controller;
use craftnet\db\Table;
use craft\db\Table as CraftTable;
use craftnet\Module;
use craftnet\plugins\Plugin;
use DateTime;
use DOMDocument;
use DOMElement;
use DOMText;
use yii\base\InvalidArgumentException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * Class FeedsController
 *
 * @property Module $module
 */
class FeedsController extends Controller
{
    /**
     * @inheritdoc
     */
    protected $allowAnonymous = true;

    /**
     * Returns a feed of newly added plugins.
     *
     * @return Response
     */
    public function actionNew(): Response
    {
        $plugins = Plugin::find()
            ->withLatestReleaseInfo()
            ->with(['developer'])
            ->limit(20)
            ->andWhere(['not', [Table::PLUGINS . '.dateApproved' => null]])
            ->orderBy([Table::PLUGINS . '.dateApproved' => SORT_DESC])
            ->all();

        return $this->_asFeed('New Plugins', array_map(function(Plugin $plugin): array {
            return [
                'id' => $plugin->handle,
                'title' => $plugin->name,
                'link' => "https://plugins.craftcms.com/$plugin->handle",
                'updated' => $plugin->dateApproved,
                'author' => [
                    'name' => $plugin->getDeveloper()->getDeveloperName(),
                    'url' => $plugin->getDeveloper()->developerUrl,
                ],
                'summary' => $plugin->shortDescription,
            ];
        }, $plugins));
    }

    public function actionReleases(): Response
    {
        return $this->_asReleaseFeed('All Releases');
    }

    public function actionCritical(): Response
    {
        return $this->_asReleaseFeed('Critical Releases', function(Query $query): void {
            $query->andWhere(['pv.critical' => true]);
        });
    }

    public function actionCms(): Response
    {
        return $this->_asReleaseFeed('Craft CMS Releases', function(Query $query): void {
            $query->andWhere(['p.name' => 'craftcms/cms']);
        }, [
            'link' => 'https://craftcms.com',
            'author' => [
                'name' => 'Pixel & Tonic',
                'url' => 'https://pixelandtonic.com',
            ],
        ]);
    }

    public function actionPlugin(string $handle): Response
    {
        $plugin = Plugin::find()
            ->handle(Db::escapeParam($handle))
            ->one();

        if (!$plugin) {
            throw new NotFoundHttpException("Invalid plugin handle: $handle");
        }

        return $this->_asReleaseFeed("$plugin->name Releases", function(Query $query) use ($plugin): void {
            $query->andWhere(['p.id' => $plugin->packageId]);
        }, [
            'link' => "https://plugins.craftcms.com/$plugin->handle",
            'author' => [
                'name' => $plugin->getDeveloper()->getDeveloperName(),
                'url' => $plugin->getDeveloper()->developerUrl,
            ],
            'icon' => $plugin->icon->url ?? null,
        ]);
    }

    private function _asReleaseFeed(string $title, ?callable $modifyQuery = null, ?array $info = []): Response
    {
        $query = $this->module->getPackageManager()->createReleaseQuery()
            ->select([
                'pv.id',
                'pv.version',
                'pv.time',
                'pv.date',
                'pv.critical',
                'pv.notes',
                'pluginId' => 'pl.id',
                'pluginName' => 'pl.name',
                'pluginHandle' => 'pl.handle',
                'developerName' => 'dc.field_developerName',
                'developerUrl' => 'dc.field_developerUrl',
                'u.firstName',
                'u.lastName',
                'u.username',
            ])
            ->leftJoin(['pl' => Table::PLUGINS], '[[pl.packageId]] = [[p.id]]')
            ->leftJoin(['dc' => CraftTable::CONTENT], ['and', ['not', ['pl.id' => null]], '[[dc.elementId]] = [[pl.developerId]]'])
            ->leftJoin(['u' => CraftTable::USERS], ['and', ['not', ['pl.id' => null]], '[[u.id]] = [[pl.developerId]]'])
            ->andWhere(['not', ['pv.time' => null]])
            ->andWhere([
                'or',
                ['p.name' => 'craftcms/cms'],
                ['not', ['pl.dateApproved' => null]]
            ])
            ->orderBy(['pv.time' => SORT_DESC])
            ->limit(20);

        if ($modifyQuery !== null) {
            $modifyQuery($query);
        }

        return $this->_asFeed($title, array_map(function(array $release): array {
            if (!empty($release['pluginId'])) {
                $name = $release['pluginName'];
                $developerName = $release['developerName'];
                $link = "https://plugins.craftcms.com/{$release['pluginHandle']}";
                if (!$developerName) {
                    if ($release['firstName']) {
                        $developerName = $release['firstName'] . $release['lastName'] ? " {$release['lastName']}" : '';
                    } else {
                        $developerName = $release['username'];
                    }
                }
                $developerUrl = $release['developerUrl'];
            } else {
                $name = 'Craft CMS';
                $developerName = 'Pixel & Tonic';
                $developerUrl = 'https://pixelandtonic.com';
                $link = 'https://craftcms.com';
            }

            return [
                'id' => $release['id'],
                'title' => "$name {$release['version']}" . ($release['critical'] ? ' [CRITICAL]' : ''),
                'link' => $link,
                'updated' => $release['date'] ?? $release['time'],
                'author' => [
                    'name' => $developerName,
                    'url' => $developerUrl,
                ],
                'content' => $release['notes'],
            ];
        }, $query->all()), $info);
    }

    /**
     * @param string $title The feed title
     * @param array[] $entries The feed entry data
     * @param array $info Optional link, author, & icon values
     * @return Response
     */
    private function _asFeed(string $title, array $entries, array $info = []): Response
    {
        $url = UrlHelper::url($this->request->getPathInfo());

        $dom = new DOMDocument('1.0', Craft::$app->charset);
        $feed = $this->_element($dom, 'feed', null, ['xmlns' => 'http://www.w3.org/2005/Atom']);
        $dom->appendChild($feed);

        $feed->appendChild($this->_element($dom, 'id', $url));
        $feed->appendChild($this->_element($dom, 'title', $title));
        $feed->appendChild($this->_link($dom, $url, 'self'));

        if (isset($info['link'])) {
            $feed->appendChild($this->_link($dom, $info['link']));
        }

        // figure out the latest update date
        $updated = null;
        foreach ($entries as $entryInfo) {
            $entryInfo['updated'] = DateTimeHelper::toDateTime($entryInfo['updated']);
            if ($entryInfo['updated'] && (!$updated || $entryInfo['updated'] > $updated)) {
                $updated = $entryInfo['updated'];
            }
        }
        $feed->appendChild($this->_date($dom, 'updated', $updated ?: new DateTime));

        if (isset($info['author'])) {
            $feed->appendChild($this->_author($dom, $info['author']));
        }

        if (isset($info['icon'])) {
            $feed->appendChild($this->_element($dom, 'icon', $info['icon']));
        }

        foreach ($entries as $entryInfo) {
            try {
                $feed->appendChild($this->_entry($dom, $url, $entryInfo));
            } catch (\Throwable $e) {
                Craft::warning("Couldn’t add entry to feed ($url): {$e->getMessage()}", __METHOD__);
                throw $e;
            }
        }

        $this->response->getHeaders()->set('Content-Type', 'application/atom+xml; charset=' . Craft::$app->charset);
        return $this->asRaw($dom->saveXML());
    }

    private function _element(DOMDocument $dom, string $name, ?string $value = null, array $attributes = []): DOMElement
    {
        $element = $dom->createElement($name);
        foreach ($attributes as $n => $v) {
            $element->setAttribute($n, $v);
        }
        if ($value !== null) {
            $element->appendChild(new DOMText($value));
        }
        return $element;
    }

    private function _link(DOMDocument $dom, string $href, string $rel = 'alternate'): DOMElement
    {
        return $this->_element($dom, 'link', null, compact('rel', 'href'));
    }

    /**
     * @param DOMDocument $dom
     * @param string|array $info
     * @return DOMElement
     */
    private function _author(DOMDocument $dom, $info): DOMElement
    {
        $element = $this->_element($dom, 'author');
        if (is_string($info)) {
            $info = ['name' => $info];
        }
        if (empty($info['name'])) {
            throw new InvalidArgumentException('Invalid author value');
        }
        foreach (array_filter($info) as $name => $value) {
            $element->appendChild($this->_element($dom, $name, $value));
        }
        return $element;
    }

    private function _date(DOMDocument $dom, string $name, $value): DOMElement
    {
        $date = DateTimeHelper::toDateTime($value);
        if (!$date) {
            throw new InvalidArgumentException('Invalid date value');
        }
        return $this->_element($dom, $name, $date->format(DateTime::ATOM));
    }

    private function _entry(DOMDocument $dom, string $url, array $info): DOMElement
    {
        $element = $this->_element($dom, 'entry');

        $element->appendChild($this->_element($dom, 'id', $url . '/' . $info['id']));
        $element->appendChild($this->_element($dom, 'title', $info['title']));
        $element->appendChild($this->_date($dom, 'updated', $info['updated']));

        if (isset($info['link'])) {
            $element->appendChild($this->_link($dom, $info['link']));
        }

        if (isset($info['author'])) {
            $element->appendChild($this->_author($dom, $info['author']));
        }

        if (isset($info['content'])) {
            $element->appendChild($this->_element($dom, 'content', $info['content'], ['type' => 'html']));
            if (isset($info['summary'])) {
                $element->appendChild($this->_element($dom, 'summary', $info['summary']));
            }
        } else if (isset($info['summary'])) {
            $element->appendChild($this->_element($dom, 'content', $info['summary']));
        }

        if (isset($info['published'])) {
            $element->appendChild($this->_date($dom, 'published', $info['published']));
        }

        return $element;
    }
}
