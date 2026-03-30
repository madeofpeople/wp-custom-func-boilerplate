<?php

namespace abcnorio\CustomFunc;

use abcnorio\CustomFunc\ContentModel\PostTypeRegistrar;
use abcnorio\CustomFunc\ContentModel\TaxonomyRegistrar;

final class Plugin
{
    public static function activate(): void
    {
        self::registerContentModels();
    }

    public static function enqueueEditorAssets(): void
    {
        $buildDir = plugin_dir_path(ABCNORIO_CUSTOM_FUNC_FILE) . 'build/';
        $assetFile = $buildDir . 'index.asset.php';

        if (! file_exists($assetFile)) {
            return;
        }

        $asset = require $assetFile;

        wp_enqueue_script(
            'abcnorio-custom-func-editor',
            plugin_dir_url(ABCNORIO_CUSTOM_FUNC_FILE) . 'build/index.js',
            $asset['dependencies'],
            $asset['version'],
            true
        );
    }

    public static function boot(): void
    {
        BlockEditorFields::registerHooks();
        add_action('init', [self::class, 'registerContentModels']);
    }

    public static function registerContentModels(): void
    {
        $postTypes = require __DIR__ . '/ContentModel/post-types.php';
        $taxonomies = require __DIR__ . '/ContentModel/taxonomies.php';
        PostTypeRegistrar::registerMany($postTypes);
        TaxonomyRegistrar::registerMany($taxonomies);
    }
}