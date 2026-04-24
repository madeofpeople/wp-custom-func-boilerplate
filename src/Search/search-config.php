<?php

/**
 * Declarative search configuration.
 *
 * Filterable via the `abcnorio_search_config` filter for per-site overrides:
 *
 *   add_filter('abcnorio_search_config', function(array $config): array {
 *       unset($config['press_item']);
 *       $config['event']['url_pattern'] = '/programme/{slug}';
 *       return $config;
 *   });
 *
 * Relevance weights are summed across all match sources per post.
 * A title-start match receives a ×1.5 multiplier applied in SearchEndpoint.
 *
 * ACF fields using serialized storage (repeaters, groups, flexible content):
 * matched via LIKE on raw serialized blob — substring match works but cannot
 * target subfields. No DB index on meta_value; acceptable at this site's scale.
 */

return apply_filters('abcnorio_search_config', [

    'synonyms' => [
        'gig'      => ['concert', 'performance'],
        'workshop' => ['class', 'course'],
        'show'     => ['performance', 'concert'],
    ],

    'post_types' => [

        'event' => [
            'group'       => 'events',
            'url_pattern' => '/events/{slug}',
            'fields'      => [
                'post_title'   => ['relevance' => 10],
                'post_content' => ['relevance' => 3],
            ],
            'acf_fields' => [
                'event_subtitle' => ['relevance' => 7],
                'event_details'  => ['relevance' => 3],
            ],
            'taxonomies' => [
                'event_type' => ['relevance' => 5],
                'event_tag'  => ['relevance' => 4],
            ],
        ],

        'collective' => [
            'group'       => 'pages',
            'url_pattern' => '/collectives/{slug}',
            'fields'      => [
                'post_title'   => ['relevance' => 10],
                'post_content' => ['relevance' => 3],
            ],
            'acf_fields' => [],
            'taxonomies' => [
                'collective_association' => ['relevance' => 5],
            ],
        ],

        'news_item' => [
            'group'       => 'other',
            'url_pattern' => '/news/{slug}',
            'fields'      => [
                'post_title'   => ['relevance' => 10],
                'post_content' => ['relevance' => 3],
            ],
            'acf_fields' => [],
            'taxonomies' => [
                'collective_association' => ['relevance' => 4],
            ],
        ],

        'press_item' => [
            'group'       => 'other',
            'url_pattern' => null, // no public Astro route; `url` omitted from response
            'fields'      => [
                'post_title'   => ['relevance' => 10],
                'post_content' => ['relevance' => 3],
            ],
            'acf_fields' => [],
            'taxonomies' => [
                'press_flag' => ['relevance' => 3],
            ],
        ],

        'page' => [
            'group'       => 'pages',
            'url_pattern' => '/{slug}',
            'fields'      => [
                'post_title'   => ['relevance' => 10],
                'post_content' => ['relevance' => 2],
            ],
            'acf_fields' => [],
            'taxonomies' => [],
        ],

    ],

]);
