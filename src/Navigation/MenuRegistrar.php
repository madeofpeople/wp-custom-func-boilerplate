<?php

namespace abcnorio\CustomFunc\Navigation;

use abcnorio\CustomFunc\AdminExperience\AdminExperience;

final class MenuRegistrar
{
    public static function registerHooks(): void
    {
        add_action('after_setup_theme', [self::class, 'registerMenuLocations']);
        add_action('rest_api_init', [self::class, 'registerRestRoutes']);
        add_filter('default_hidden_meta_boxes', [self::class, 'showCptMetaBoxesByDefault'], 10, 2);
    }

    public static function showCptMetaBoxesByDefault(array $hidden, \WP_Screen $screen): array
    {
        if ($screen->id === 'nav-menus') {
            $show = [
                'add-post-type-collective',
                'add-post-type-event',
                'add-post-type-news_item',
                'add-post-type-press_item',
                'add-taxonomy-event_type',
                'add-taxonomy-collective_association',
            ];
            $hide = [
                'add-taxonomy-event_tag',
                'add-taxonomy-post_tag',
            ];
            $hidden = array_diff($hidden, $show);
            $hidden = array_unique(array_merge($hidden, $hide));
        }
        return $hidden;
    }

    public static function registerMenuLocations(): void
    {
        register_nav_menus([
            'primary' => __('Primary Navigation', 'custom-func'),
            'footer' => __('Footer Navigation', 'custom-func'),
        ]);
    }

    public static function registerRestRoutes(): void
    {
        register_rest_route('abcnorio/v1', '/menus', [
            'methods' => \WP_REST_Server::READABLE,
            'callback' => [self::class, 'getMenus'],
            'permission_callback' => '__return_true',
        ]);
    }

    public static function getMenus(\WP_REST_Request $request)
    {
        $requestedLocation = $request->get_param('location');
        $registeredLocations = get_registered_nav_menus();
        $assignedMenus = get_nav_menu_locations();
        $menus = [];

        foreach ($registeredLocations as $location => $label) {
            if (is_string($requestedLocation) && $requestedLocation !== '' && $requestedLocation !== $location) {
                continue;
            }

            $menuId = isset($assignedMenus[$location]) ? (int) $assignedMenus[$location] : 0;
            $items = $menuId > 0 ? wp_get_nav_menu_items($menuId) : [];

            $menus[$location] = [
                'location' => $location,
                'label' => $label,
                'items' => array_values(array_map(
                    static fn ($item) => [
                        'id' => (int) $item->ID,
                        'parent' => (int) $item->menu_item_parent,
                        'title' => $item->title,
                        'url' => AdminExperience::rewriteLinkToFrontend($item->url),
                        'target' => $item->target,
                    ],
                    is_array($items) ? $items : []
                )),
            ];
        }

        if (is_string($requestedLocation) && $requestedLocation !== '') {
            return rest_ensure_response($menus[$requestedLocation] ?? []);
        }

        return rest_ensure_response($menus);
    }

}
