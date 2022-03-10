<?php

namespace craftnet\composer;

use Craft;
use craft\base\Model;
use craftnet\composer\vcs\BaseVcs;
use craftnet\composer\vcs\GitHub;
use craftnet\composer\vcs\Packagist;
use craftnet\composer\vcs\VcsInterface;
use craftnet\errors\MissingTokenException;
use craftnet\Module;
use craftnet\plugins\Plugin;
use Github\AuthMethod;
use Github\Client as GithubClient;

/**
 * @property bool $isPlugin
 * @property Plugin|null $plugin
 * @property VcsInterface $vcs
 */
class Package extends Model
{
    /**
     * @var
     */
    public $id;

    /**
     * @var int|null
     */
    public $developerId;

    /**
     * @var
     */
    public $name;

    /**
     * @var
     */
    public $type;

    /**
     * @var
     */
    public $repository;

    /**
     * @var bool
     */
    public $managed = false;

    /**
     * @var bool
     */
    public $abandoned = false;

    /**
     * @var
     */
    public $replacementPackage;

    /**
     * @var string|null
     */
    public $latestVersion;

    /**
     * @var
     */
    public $webhookId;

    /**
     * @var
     */
    public $webhookSecret;

    /**
     * @var BaseVcs|null
     */
    private $_vcs;

    /**
     * @var Plugin|false|null
     */
    private $_plugin;

    /**
     * @return VcsInterface
     * @throws MissingTokenException
     */
    public function getVcs(): VcsInterface
    {
        return $this->_vcs ?? $this->_vcs = $this->_createVcs();
    }

    /**
     * @param string|false $value The replacement package name, or false
     */
    public function setAbandoned($value)
    {
        if ((bool)$this->abandoned !== ($abandoned = (bool)$value)) {
            $this->abandoned = $abandoned;
            $this->replacementPackage = ($abandoned && is_string($value)) ? $value : null;
            Module::getInstance()->getPackageManager()->savePackage($this);
        }
    }

    /**
     * Returns whether this pcakage is for a Craft plugin.
     *
     * @return bool
     */
    public function getIsPlugin(): bool
    {
        return $this->type === 'craft-plugin';
    }

    /**
     * Returns the plugin associated with this package, if any.
     *
     * @return Plugin|null
     */
    public function getPlugin()
    {
        if (!$this->getIsPlugin()) {
            return null;
        }

        if ($this->_plugin === null) {
            $this->_plugin = Plugin::find()
                    ->packageId($this->id)
                    ->status(null)
                    ->one()
                ?? false;
        }

        return $this->_plugin ?: null;
    }

    /**
     * @return VcsInterface
     * @throws MissingTokenException
     */
    private function _createVcs(): VcsInterface
    {
        $parsed = $this->repository ? parse_url($this->repository) : null;

        if (isset($parsed['host']) && $parsed['host'] === 'github.com') {
            [$owner, $repo] = explode('/', trim($parsed['path'], '/'), 2);

            $token = null;
            if ($this->developerId) {
                Craft::info('Using package token for ' . $this->name . ': ' . substr($token, 0, 10), __METHOD__);
                $token = Module::getInstance()->getOauth()->getAuthTokenByUserId('Github', $this->developerId);

                if (!$token) {
                    if (Module::getInstance()->getPackageManager()->requirePluginVcsTokens) {
                        throw new MissingTokenException($this);
                    }
                    Craft::warning("Package \"{$this->name}\" is missing its VCS token.", __METHOD__);
                }
            }
            if (!$token) {
                // Just use a fallback token
                $token = Module::getInstance()->getPackageManager()->getRandomGitHubFallbackToken();
                Craft::info("Using fallback token for {$this->name}: " . substr($token, 0, 10), __METHOD__);
            }

            // Create an authenticated GitHub API client
            $client = new GithubClient();
            $client->authenticate($token, null, AuthMethod::ACCESS_TOKEN);

            return new GitHub($this, [
                'client' => $client,
                'owner' => $owner,
                'repo' => $repo,
            ]);
        }

        return new Packagist($this);
    }
}
