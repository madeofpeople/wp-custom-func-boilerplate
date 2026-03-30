<?php

namespace abcnorio\CustomFunc;

final class Plugin
{
    public static function activate(): void
    {
        self::registerContentModels();
    }

    public static function boot(): void
    {
        BlockEditorFields::registerHooks();
        add_action('init', [self::class, 'registerContentModels']);
    }

    public static function registerContentModels(): void
    {
        $postTypes = require __DIR__ . '/ContentModel/post-types.php';
        PostTypeRegistrar::registerMany($postTypes);
    }
}