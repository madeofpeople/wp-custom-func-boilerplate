<?php

namespace abcnorio\CustomFunc\RestApi;

final class EventQueryFilters
{
    public static function registerHooks(): void
    {
        add_action('init', [self::class, 'registerEventMeta']);
        add_filter('rest_event_query', [self::class, 'applyMetaFilters'], 10, 2);
        add_filter('rest_event_collection_params', [self::class, 'addOrderbyParams']);
        add_filter('rest_event_query', [self::class, 'applyOrderby'], 10, 2);
    }

    public static function registerEventMeta(): void
    {
        register_meta('post', 'event_start_date', [
            'object_subtype' => 'event',
            'show_in_rest'   => true,
            'single'         => true,
            'type'           => 'string',
        ]);

        register_meta('post', 'event_end_date', [
            'object_subtype' => 'event',
            'show_in_rest'   => true,
            'single'         => true,
            'type'           => 'string',
        ]);
    }

    public static function addOrderbyParams(array $params): array
    {
        if (isset($params['orderby']['enum'])) {
            $params['orderby']['enum'][] = 'event_start_date';
        }
        return $params;
    }

    public static function applyOrderby(array $args, \WP_REST_Request $request): array
    {
        if ($request->get_param('orderby') === 'event_start_date') {
            $args['meta_key'] = 'event_start_date';
            $args['orderby']  = 'meta_value';
            $args['order']    = strtoupper($request->get_param('order') ?? 'ASC');
        }
        return $args;
    }

    public static function applyMetaFilters(array $args, \WP_REST_Request $request): array
    {
        $after  = $request->get_param('event_start_after');
        $before = $request->get_param('event_start_before');

        $clauses = [self::buildHasStartDateClause()];

        if ($after) {
            $clauses[] = self::buildEffectiveEndClause('>=', sanitize_text_field($after));
        }

        if ($before) {
            $clauses[] = self::buildEffectiveEndClause('<=', sanitize_text_field($before));
        }

        $existingMetaQuery = isset($args['meta_query']) && is_array($args['meta_query'])
            ? $args['meta_query']
            : [];

        $existingClauses = [];
        foreach ($existingMetaQuery as $key => $value) {
            if ($key === 'relation') {
                continue;
            }
            $existingClauses[] = $value;
        }

        $args['meta_query'] = array_merge(['relation' => 'AND'], $existingClauses, $clauses);

        return $args;
    }

    private static function buildHasStartDateClause(): array
    {
        return [
            'relation' => 'AND',
            [
                'key'     => 'event_start_date',
                'compare' => 'EXISTS',
            ],
            [
                'key'     => 'event_start_date',
                'value'   => '',
                'compare' => '!=',
            ],
        ];
    }

    private static function buildEffectiveEndClause(string $compare, string $value): array
    {
        // Effective end date:
        // - use event_end_date when present
        // - otherwise implicitly treat event_start_date as end date
        return [
            'relation' => 'OR',
            [
                'key'     => 'event_end_date',
                'value'   => $value,
                'compare' => $compare,
                'type'    => 'DATETIME',
            ],
            [
                'relation' => 'AND',
                [
                    'relation' => 'OR',
                    [
                        'key'     => 'event_end_date',
                        'compare' => 'NOT EXISTS',
                    ],
                    [
                        'key'     => 'event_end_date',
                        'value'   => '',
                        'compare' => '=',
                    ],
                ],
                [
                    'key'     => 'event_start_date',
                    'value'   => $value,
                    'compare' => $compare,
                    'type'    => 'DATETIME',
                ],
            ],
        ];
    }
}