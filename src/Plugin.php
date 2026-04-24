<?php

namespace abcnorio\CustomFunc;
use abcnorio\CustomFunc\ContentModel\ACFFieldGroups;
use abcnorio\CustomFunc\ContentModel\PostTypeRegistrar;
use abcnorio\CustomFunc\ContentModel\TaxonomyRegistrar;
use abcnorio\CustomFunc\ContentModel\CollectivePostSeeder;
use abcnorio\CustomFunc\ContentModel\TaxonomyTermSeeder;
use abcnorio\CustomFunc\AdminExperience\AdminExperience;
use abcnorio\CustomFunc\ImageStyles\BlockImageAttributeEnricher;
use abcnorio\CustomFunc\ImageStyles\ImageStyleRegistrar;
use abcnorio\CustomFunc\Navigation\MenuRegistrar;
use abcnorio\CustomFunc\RestApi\EventQueryFilters;
use abcnorio\CustomFunc\Search\SearchEndpoint;
use abcnorio\CustomFunc\Security\CapabilityManager;

final class Plugin
{
    public static function activate(): void
    {
        self::registerContentModels();
        CapabilityManager::forceMigrateCapabilities();
        TaxonomyTermSeeder::forceSeedDefaults();
        CollectivePostSeeder::forceSeedDefaults();
    }

    public static function boot(): void
    {
        AdminExperience::registerHooks();
        EventQueryFilters::registerHooks();
        SearchEndpoint::registerHooks();
        ACFFieldGroups::registerHooks();
        ImageStyleRegistrar::registerHooks();
        BlockImageAttributeEnricher::registerHooks();
        MenuRegistrar::registerHooks();
        add_action('after_setup_theme', [self::class, 'enableFeaturedImages']);
        add_action('enqueue_block_editor_assets', [self::class, 'enqueueEditorAssets']);
        add_action('admin_init', [CapabilityManager::class, 'maybeMigrateCapabilities'], 1);
        add_action('init', [self::class, 'registerContentModels']);
        add_action('init', [self::class, 'disableComments'], 15);
        add_action('init', [TaxonomyTermSeeder::class, 'maybeSeedDefaults'], 20);
        add_action('init', [CollectivePostSeeder::class, 'maybeSeedDefaults'], 30);
        add_action('init', [self::class, 'unregisterSeedPostType'], 999);
    }

    public static function registerContentModels(): void
    {
        $postTypes = require __DIR__ . '/ContentModel/post-types.php';
        $taxonomies = require __DIR__ . '/ContentModel/taxonomies.php';
        PostTypeRegistrar::registerMany($postTypes);
        TaxonomyRegistrar::registerMany($taxonomies);
    }

    public static function enableFeaturedImages(): void
    {
        add_theme_support('post-thumbnails', ['post', 'page', 'event', 'collective', 'news_item']);
    }

    public static function unregisterSeedPostType(): void
    {
        if (post_type_exists('seed')) {
            unregister_post_type('seed');
        }
    }

    public static function disableComments (): void
    {
        add_action('admin_init', function () {
            // Redirect any user trying to access comments page
            global $pagenow;

            if ($pagenow === 'edit-comments.php') {
                wp_redirect(admin_url());
                exit;
            }

            // Remove comments metabox from dashboard
            remove_meta_box('dashboard_recent_comments', 'dashboard', 'normal');

            // Disable support for comments and trackbacks in post types
            foreach (get_post_types() as $post_type) {
                if (post_type_supports($post_type, 'comments')) {
                    remove_post_type_support($post_type, 'comments');
                    remove_post_type_support($post_type, 'trackbacks');
                }
            }
        });
        add_filter('comments_open', '__return_false', 20, 2);
        add_filter('pings_open', '__return_false', 20, 2);
        add_filter('comments_array', '__return_empty_array', 10, 2);
        add_action('admin_menu', function () {
            remove_menu_page('edit-comments.php');
        });
        add_action('init', function () {
            if (is_admin_bar_showing()) {
                remove_action('admin_bar_menu', 'wp_admin_bar_comments_menu', 60);
            }
        });        
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

}