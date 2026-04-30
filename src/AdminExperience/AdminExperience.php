<?php

namespace abcnorio\CustomFunc\AdminExperience;

final class AdminExperience
{
    public static function registerHooks(): void
    {
        AllowedBlocks::registerHooks();
        TaxonomyColumnSorter::registerHooks();
        ListTableTermEditor::registerHooks();
        add_action('admin_menu', [self::class, 'customizeAdminMenu']);
        add_action('admin_menu', [self::class,'customizeACFMenu']);
        add_action('rest_api_init', [self::class, 'registerRestLinkRewrites']);
        add_action('admin_enqueue_scripts', [self::class, 'enqueueAdminStyles']);
        add_action('enqueue_block_editor_assets', [self::class, 'enqueueEditorTokens']);
        add_action('admin_menu', [self::class, 'remove_default_post_type']);
        add_action('admin_bar_menu', [self::class, 'remove_default_post_type_menu_bar'], 999);
        add_action('wp_dashboard_setup', [self::class, 'remove_draft_widget'], 999);
        add_filter('post_link', [self::class, 'rewriteLinkToFrontend'], 10, 2);
        add_filter('page_link', [self::class, 'rewriteLinkToFrontend'], 10, 2);
        add_filter('post_type_link', [self::class, 'rewriteLinkToFrontend'], 10, 2);
        add_filter('preview_post_link', [self::class, 'rewriteLinkToFrontend'], 10, 2);
    }

    public static function remove_draft_widget(): void
    {
        remove_meta_box( 'dashboard_quick_press', 'dashboard', 'side' );
    }

    public static function remove_default_post_type_menu_bar( \WP_Admin_Bar $wp_admin_bar ): void
    {
        $wp_admin_bar->remove_node( 'new-post' );
        $wp_admin_bar->remove_node( 'new-content' );
    }

    public static function remove_default_post_type(): void
    {
        remove_menu_page( 'edit.php' );
    }
    public static function customizeACFMenu(): void
    {
        remove_menu_page('edit.php?post_type=posts');
        remove_menu_page('edit.php?post_type=acf');
        remove_menu_page('edit.php?post_type=acf-field-group');
    }

    public static function customizeAdminMenu(): void
    {
        remove_menu_page('themes.php');

        add_menu_page(
            __('Menus', 'abcnorio-func'),
            __('Menus', 'abcnorio-func'),
            'edit_pages',
            'nav-menus.php',
            '',
            'dashicons-menu',
            60
        );
    }

    public static function registerRestLinkRewrites(): void
    {
        foreach (get_post_types(['show_in_rest' => true], 'names') as $postType) {
            add_filter("rest_prepare_{$postType}", [self::class, 'rewriteRestPreparedEntity'], 10, 3);
        }
    }


    public static function rewriteLinkToFrontend(string $link): string
    {
        return self::rewriteToFrontend($link);
    }

    public static function rewriteRestPreparedEntity($response, \WP_Post $post, \WP_REST_Request $request)
    {
        if (! $response instanceof \WP_REST_Response) {
            return $response;
        }

        $data = $response->get_data();

        if (! is_array($data)) {
            return $response;
        }

        foreach (['link', 'preview_link', 'permalink_template'] as $key) {
            if (isset($data[$key]) && is_string($data[$key])) {
                $data[$key] = self::rewriteToFrontend($data[$key]);
            }
        }

        $response->set_data($data);

        return $response;
    }

    public static function enqueueEditorTokens(): void
    {
        $relativePath = 'resources/css/admin-styles.css';
        $absolutePath = plugin_dir_path(ABCNORIO_CUSTOM_FUNC_FILE) . $relativePath;

        if (! file_exists($absolutePath)) {
            return;
        }

        wp_enqueue_style(
            'abcnorio-editor-tokens',
            plugin_dir_url(ABCNORIO_CUSTOM_FUNC_FILE) . $relativePath,
            [],
            (string) filemtime($absolutePath)
        );
    }

    public static function enqueueAdminStyles(): void
    {
        $relativePath = 'resources/css/admin.css';
        $absolutePath = plugin_dir_path(ABCNORIO_CUSTOM_FUNC_FILE) . $relativePath;

        if (! file_exists($absolutePath)) {
            return;
        }

        wp_enqueue_style(
            'abcnorio-custom-func-admin',
            plugin_dir_url(ABCNORIO_CUSTOM_FUNC_FILE) . $relativePath,
            [],
            (string) filemtime($absolutePath)
        );
    }

    private static function rewriteToFrontend(string $url): string
    {
        $baseUrl = self::frontendBaseUrl();

        if ($baseUrl === '') {
            return $url;
        }

        $targetParts = wp_parse_url($baseUrl);
        $sourceParts = wp_parse_url($url);

        if (! is_array($targetParts) || ! is_array($sourceParts)) {
            return $url;
        }

        if (empty($targetParts['scheme']) || empty($targetParts['host'])) {
            return $url;
        }

        $scheme = $targetParts['scheme'];
        $host = $targetParts['host'];
        $port = isset($targetParts['port']) ? ':' . (string) $targetParts['port'] : '';

        $targetBasePath = isset($targetParts['path']) ? rtrim($targetParts['path'], '/') : '';
        $sourcePath = self::normalizeFrontendPath($sourceParts['path'] ?? '/');

        if (! self::isFrontendPathSupported($sourcePath)) {
            return $url;
        }

        $finalPath = $targetBasePath . $sourcePath;

        if ($finalPath === '') {
            $finalPath = '/';
        }

        $query = isset($sourceParts['query']) ? '?' . $sourceParts['query'] : '';
        $fragment = isset($sourceParts['fragment']) ? '#' . $sourceParts['fragment'] : '';

        return $scheme . '://' . $host . $port . $finalPath . $query . $fragment;
    }

    private static function normalizeFrontendPath(string $path): string
    {
        if (str_starts_with($path, '/event/')) {
            return '/events/' . ltrim(substr($path, strlen('/event/')), '/');
        }

        return $path;
    }

    private static function isFrontendPathSupported(string $path): bool
    {
        foreach (['/press/', '/news_items/'] as $unsupportedPrefix) {
            if (str_starts_with($path, $unsupportedPrefix)) {
                return false;
            }
        }

        return true;
    }

    private static function frontendBaseUrl(): string
    {
        $env = getenv('HEADLESS_FRONTEND_URL');

        return is_string($env) ? rtrim($env, '/') : '';
    }

}
