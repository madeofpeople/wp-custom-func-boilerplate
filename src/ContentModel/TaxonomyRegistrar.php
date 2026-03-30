<?php

namespace abcnorio\CustomFunc\ContentModel;

final class TaxonomyRegistrar
{
    public static function registerMany(array $definitions): void
    {
        foreach ($definitions as $slug => $definition) {
            self::registerOne((string) $slug, (array) $definition);
        }
    }

    public static function registerOne(string $slug, array $definition): void
    {
        $objectTypes = $definition['object_types'] ?? [];
        $pluralLabel = $definition['name'] ?? ucwords(str_replace('-', ' ', $slug));
        $singularLabel = $definition['singular_name'] ?? ucwords(str_replace('-', ' ', $slug));

        $labels = [
            'name' => $pluralLabel,
            'singular_name' => $singularLabel,
            'menu_name' => $pluralLabel,
            'all_items' => 'All ' . $pluralLabel,
            'edit_item' => 'Edit ' . $singularLabel,
            'view_item' => 'View ' . $singularLabel,
            'update_item' => 'Update ' . $singularLabel,
            'add_new_item' => 'Add ' . $singularLabel,
            'new_item_name' => 'New ' . $singularLabel,
            'search_items' => 'Search ' . $pluralLabel,
            'not_found' => 'No ' . strtolower($pluralLabel) . ' found',
        ];

        $args = [
            'labels' => $labels,
            'public' => $definition['public'] ?? true,
            'show_ui' => $definition['show_ui'] ?? true,
            'show_in_rest' => $definition['show_in_rest'] ?? true,
            'rest_base' => $definition['rest_base'] ?? self::defaultRestBase($slug),
            'hierarchical' => $definition['hierarchical'] ?? false,
            'rewrite' => [
                'slug' => $definition['rewrite_slug'] ?? $slug,
                'with_front' => $definition['with_front'] ?? false,
            ],
            'capabilities' => $definition['capabilities'] ?? [],
        ];

        register_taxonomy($slug, $objectTypes, $args);

        self::registerFields($slug, $definition['fields'] ?? []);
    }

    private static function registerFields(string $taxonomy, array $fields): void
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

            register_term_meta(
                $taxonomy,
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
