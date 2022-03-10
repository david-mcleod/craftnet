<?php

namespace craftnet\services;

use Craft;
use craft\db\Query;
use craftnet\db\Table;
use Github\Client as GithubClient;
use Github\Exception\RuntimeException;
use Github\ResultPager;
use League\OAuth2\Client\Provider\AbstractProvider;
use League\OAuth2\Client\Provider\Exception\GithubIdentityProviderException;
use League\OAuth2\Client\Token\AccessToken;
use yii\base\Component;
use yii\base\Exception;

/**
 * @property array $apps
 */
class Oauth extends Component
{
    const PROVIDER_GITHUB = 'github';
    const PROVIDER_BITBUCKET = 'bitbucket';

    /**
     * @var array
     */
    public $appTypes = [];

    /**
     * @var array
     * @see getAuthTokenByUserId()
     */
    private $_authTokens = [];

    /**
     * Lists the repos for a given VSC type. Not going through composer/vsc classes
     * because they expect the package/plugin to already exist.
     *
     * @return array
     */
    public function getApps(): array
    {
        $currentUser = Craft::$app->getUser()->getIdentity();
        $apps = [];

        foreach ($this->appTypes as $handle => $config) {
            $oauthProvider = $this->getAppTypeOauthProvider($handle);
            $token = $this->getOauthTokenByUserId($config['class'], $currentUser->id);

            if ($token) {
                $accessToken = $this->createAccessToken($token);
                try {
                    $resourceOwner = $oauthProvider->getResourceOwner($accessToken);
                } catch (GithubIdentityProviderException $e) {
                    // Bad credentials. Likely our oAuth app has been revoked.
                    // Remove the token locally.
                    if ($e->getCode() === 401) {
                        $this->deleteAccessToken($currentUser->id, $config['class']);
                        Craft::warning('Got a 401 bad credentials response when attempting to validate a GitHub oAuth token for user ID: ' . $currentUser->id . '. Likely our Github oAuth app has been removed or permissions revoked.', __METHOD__);
                    }
                    continue;
                }
                $account = $resourceOwner->toArray();

                $client = new GithubClient();
                $client->authenticate($accessToken->getToken(), null, GithubClient::AUTH_ACCESS_TOKEN);
                $api = $client->currentUser();
                $paginator = new ResultPager($client);
                try {
                    $repos = $paginator->fetchAll($api, 'repositories', ['public']);
                } catch (RuntimeException $e) {
                    Craft::error('Error fetching user repos: ' . $e->getMessage(), __METHOD__);
                    Craft::$app->getErrorHandler()->logException($e);
                    continue;
                }

                $repositories = [];

                foreach ($repos as $repo) {
                    // Make sure they have administrative privileges on the repo
                    if ($repo['permissions']['admin']) {
                        $repositories[] = $repo;
                    }
                }

                $apps[$handle] = [
                    'token' => $token,
                    'account' => $account,
                    'repositories' => $repositories,
                ];
            }
        }

        return $apps;
    }

    /**
     * @return AbstractProvider
     */
    public function getAppTypeOauthProvider($appTypeHandle): AbstractProvider
    {
        $craftIdConfig = Craft::$app->getConfig()->getConfigFromFile('craftid');
        $config = $this->getAppTypeConfig($appTypeHandle);

        return new $config['oauthClass']([
            'clientId' => $config['clientIdKey'],
            'clientSecret' => $config['clientSecretKey'],
            'redirectUri' => $craftIdConfig['craftIdUrl'] . '/apps/callback',
        ]);
    }

    /**
     * @param string $appTypeHandle
     *
     * @return array|null
     * @throws Exception if $appTypeHandle is invalid
     */
    public function getAppTypeConfig(string $appTypeHandle): array
    {
        if (!isset($this->appTypes[$appTypeHandle])) {
            throw new Exception('Invalid OAuth app type: ' . $appTypeHandle);
        }

        return $this->appTypes[$appTypeHandle];
    }

    /**
     * @param string $providerClass
     * @param int $userId
     *
     * @return string|null
     */
    public function getAuthTokenByUserId(string $providerClass, int $userId)
    {
        if (!isset($this->_authTokens[$providerClass][$userId])) {
            $this->_authTokens[$providerClass][$userId] = (new Query())
                ->select(['accessToken'])
                ->from([Table::VCSTOKENS])
                ->where(['userId' => $userId, 'provider' => $providerClass])
                ->scalar();
        }

        return $this->_authTokens[$providerClass][$userId] ?: null;
    }

    /**
     * @param string $providerClass
     * @param int $userId
     *
     * @return array|null
     */
    public function getOauthTokenByUserId(string $providerClass, int $userId)
    {
        return (new Query())
            ->select([
                'id',
                'userId',
                'provider',
                'accessToken',
                'tokenType',
                'expiresIn',
                'expiryDate',
                'refreshToken',
            ])
            ->from([Table::VCSTOKENS])
            ->where(['userId' => $userId, 'provider' => $providerClass])
            ->one();
    }

    /**
     * @param array $tokenInfo
     *
     * @return AccessToken
     */
    public function createAccessToken(array $tokenInfo): AccessToken
    {
        $options = [
            'access_token' => $tokenInfo['accessToken'],
        ];

        if (isset($tokenInfo['expiresIn'])) {
            $options['expires_in'] = $tokenInfo['expiresIn'];
        }

        return new AccessToken($options);
    }

    /**
     * Deletes an oAuth token from the database by the given username and provider.
     *
     * @param $userId
     * @param $provider
     *
     * @return void
     */
    public function deleteAccessToken($userId, $provider)
    {
        Craft::$app->getDb()->createCommand()
            ->delete(Table::VCSTOKENS, ['userId' => $userId, 'provider' => $provider])
            ->execute();
    }
}
