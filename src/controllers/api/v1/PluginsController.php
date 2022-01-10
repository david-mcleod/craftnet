<?php

namespace craftnet\controllers\api\v1;

use craftnet\controllers\api\BaseApiController;
use craftnet\db\Table;
use craftnet\plugins\Plugin;
use yii\web\Response;

/**
 * Class PluginsController
 */
class PluginsController extends BaseApiController
{
    /**
     * Handles /v1/plugins requests.
     *
     * @return Response
     * @throws \craftnet\errors\MissingTokenException
     * @throws \yii\base\InvalidConfigException
     */
    public function actionIndex(): Response
    {
        $pluginQuery = $this->_getPluginQuery();

        $ids = $this->request->getParam('ids');
        $ids = explode(',', $ids);

        if ($ids) {
            $pluginQuery->andWhere([Table::PLUGINS . '.id' => $ids]);
        }

        $plugins = $pluginQuery->all();

        $data = $this->transformPlugins($plugins);

        return $this->asJson($data);
    }

    /**
     * @return \craft\elements\db\ElementQueryInterface|\craftnet\plugins\PluginQuery
     */
    private function _getPluginQuery()
    {
        return Plugin::find()
            ->withLatestReleaseInfo(true, $this->cmsVersionForPluginQueries())
            ->with(['developer', 'categories', 'icon'])
            ->indexBy('id');
    }
}
