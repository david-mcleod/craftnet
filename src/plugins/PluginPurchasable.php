<?php

namespace craftnet\plugins;

use craftnet\base\LicenseInterface;
use craftnet\base\Purchasable;
use craftnet\Module;
use yii\base\InvalidConfigException;

/**
 * @property Plugin $plugin
 */
abstract class PluginPurchasable extends Purchasable
{
    // Properties
    // =========================================================================

    /**
     * @var int The plugin ID
     */
    public $pluginId;

    /**
     * @var Plugin|null
     */
    private $_plugin;

    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    public function extraFields()
    {
        return [
            'plugin',
        ];
    }

    /**
     * @inheritdoc
     */
    protected function defineRules(): array
    {
        $rules = parent::defineRules();
        $rules[] = [['pluginId'], 'required'];
        $rules[] = [['pluginId'], 'number', 'integerOnly' => true];
        return $rules;
    }

    /**
     * @inheritdoc
     */
    public function getLicenseByKey(string $key): LicenseInterface
    {
        return Module::getInstance()->getPluginLicenseManager()->getLicenseByKey($key, $this->getPlugin()->handle);
    }

    /**
     * @return Plugin
     * @throws InvalidConfigException
     */
    public function getPlugin(): Plugin
    {
        if ($this->_plugin !== null) {
            return $this->_plugin;
        }
        if ($this->pluginId === null) {
            throw new InvalidConfigException('Plugin edition is missing its plugin ID');
        }
        if (($plugin = Plugin::find()->id($this->pluginId)->status(null)->one()) === null) {
            throw new InvalidConfigException('Invalid plugin ID: ' . $this->pluginId);
        }
        return $this->_plugin = $plugin;
    }

    /**
     * @param Plugin|null $plugin
     */
    public function setPlugin(Plugin $plugin = null)
    {
        $this->_plugin = $plugin;
    }
}
