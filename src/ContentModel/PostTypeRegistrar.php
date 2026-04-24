<?php

namespace abcnorio\CustomFunc\ContentModel;

final class PostTypeRegistrar
{
    public static function registerMany(array $definitions): void
    {
        foreach ($definitions as $slug => $definition) {
            self::registerOne((string) $slug, (array) $definition);
        }
    }

    public static function registerOne(string $slug, array $definition): void
    {
        $pluralLabel = $definition['name'] ?? ucwords(str_replace('-', ' ', $slug));
        $singularLabel = $definition['singular_name'] ?? ucwords(str_replace('-', ' ', $slug));

        $labels = [
            'name' => $pluralLabel,
            'singular_name' => $singularLabel,
            'menu_name' => $pluralLabel,
            'add_new' => 'Add ' . $singularLabel,
            'add_new_item' => 'Add ' . $singularLabel,
            'new_item' => 'New ' . $singularLabel,
            'edit_item' => 'Edit ' . $singularLabel,
            'view_item' => 'View ' . $singularLabel,
            'all_items' => 'All ' . $pluralLabel,
            'search_items' => 'Search ' . $pluralLabel,
            'not_found' => 'No ' . strtolower($pluralLabel) . ' found',
            'not_found_in_trash' => 'No ' . strtolower($pluralLabel) . ' found in Trash',
        ];

        $args = [
            'label' => $pluralLabel,
            'labels' => $labels,
            'public' => $definition['public'] ?? true,
            'show_ui' => $definition['show_ui'] ?? true,
            'show_in_nav_menus' => $definition['show_in_nav_menus'] ?? false,
            'show_in_rest' => $definition['show_in_rest'] ?? true,
            'rest_base' => $definition['rest_base'] ?? self::defaultRestBase($slug),
            'rewrite' => [
                'slug' => $definition['rewrite_slug'] ?? $slug,
                'with_front' => $definition['with_front'] ?? false,
            ],
            'supports' => $definition['supports'] ?? ['title', 'editor'],
            'has_archive' => $definition['has_archive'] ?? false,
            'menu_icon' => $definition['menu_icon'] ?? 'dashicons-admin-post',
            'taxonomies' => $definition['taxonomies'] ?? [],
            'capability_type' => $definition['capability_type'] ?? 'post',
            'capabilities' => $definition['capabilities'] ?? [],
            'map_meta_cap' => $definition['map_meta_cap'] ?? false,
            'template' => $definition['template'] ?? [],
            'template_lock' => $definition['template_lock'] ?? false,
        ];

        register_post_type($slug, $args);

        self::registerFields($slug, $definition['fields'] ?? []);
    }

    private static function registerFields(string $postType, array $fields): void
    {
        foreach ($fields as $metaKey => $field) {
            if (! is_array($field)) {
                continue;
            }

            $metaArgs = [
                'type' => $field['type'] ?? 'string',
                'single' => $field['single'] ?? true,
                'show_in_rest' => $field['show_in_rest'] ?? true,
                'default' => $field['default'] ?? null,
                'sanitize_callback' => $field['sanitize_callback'] ?? null,
                'auth_callback' => $field['auth_callback'] ?? null,
            ];

            register_post_meta(
                $postType,
                (string) $metaKey,
                array_filter($metaArgs, static fn($value) => $value !== null)
            );
        }
    }

    private static function defaultRestBase(string $slug): string
    {
        $base = str_replace('_', '-', $slug);

        if (str_ends_with($base, 's')) {
            return $base;
        }

        return $base . 's';
    }
}
