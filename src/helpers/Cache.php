<?php

namespace craftnet\helpers;

use Craft;
use craft\helpers\StringHelper;
use craftnet\Module;
use craftnet\plugins\Plugin;
use yii\caching\FileDependency;
use yii\caching\TagDependency;

abstract class Cache
{
    public const TAG_PACKAGES = 'packages';
    public const TAG_PLUGIN_CHANGELOGS = 'pluginChangelogs';
    public const TAG_PLUGIN_ICONS = 'pluginIcons';

    /**
     * Returns a cached value, or `false` if it doesn’t exist.
     *
     * @param string $key
     * @return mixed
     */
    public static function get(string $key)
    {
        if (!self::enabled()) {
            return false;
        }

        return Craft::$app->getCache()->get("cn-{$key}");
    }

    /**
     * Sets a cached value.
     *
     * @param string $key
     * @param mixed $value
     * @param string[]|null $tags
     * @return bool
     */
    public static function set(string $key, mixed $value, ?array $tags = null): bool
    {
        if (!self::enabled()) {
            return false;
        }

        if ($tags !== null) {
            $tags = array_map(fn(string $tag) => static::tag($tag), $tags);
            $dependency = new TagDependency([
                'tags' => $tags,
            ]);
        }

        return Craft::$app->getCache()->set("cn-$key", $value, dependency: $dependency ?? null);
    }

    /**
     * Invalidates a cache tag.
     *
     * @param string|string[] $tags
     */
    public static function invalidate(string|array $tags): void
    {
        $tags = array_map(fn(string $tag) => static::tag($tag), (array)$tags);
        TagDependency::invalidate(Craft::$app->cache, $tags);
    }

    /**
     * Normalizes a cache tag name.
     *
     * @param string $tag
     * @return string
     */
    public static function tag(string $tag): string
    {
        return StringHelper::ensureLeft($tag, 'cn-tag-');
    }

    /**
     * Returns a plugin icon.
     *
     * @param Plugin $plugin
     * @return string
     */
    public static function pluginIconTag(Plugin $plugin): string
    {
        return "pluginIcon:$plugin->id";
    }

    /**
     * Returns whether the cache is enabled.
     *
     * @return bool
     */
    private static function enabled(): bool
    {
        $craftIdConfig = Craft::$app->getConfig()->getConfigFromFile('craftid');
        return !empty($craftIdConfig['enablePluginStoreCache']);
    }
}
