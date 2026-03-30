<?php

return [
    'event' => [
        'name' => 'Events',
        'singular_name' => 'Event',
        'supports' => [
            'title',
            'editor',
            'excerpt',
            'thumbnail',
            'custom-fields'
        ],
        'has_archive' => true,
        'menu_icon' => 'dashicons-calendar-alt',
        'taxonomies' => [
            'event_type',
            'collective_association'],
        'capability_type' => [
            'event',
            'events'],
        'map_meta_cap' => true,
        'capabilities' => [
            'edit_post' => 'edit_event',
            'read_post' => 'read_event',
            'delete_post' => 'delete_event',
            'edit_posts' => 'edit_events',
            'edit_others_posts' => 'edit_others_events',
            'publish_posts' => 'publish_events',
            'read_private_posts' => 'read_private_events',
            'delete_posts' => 'delete_events',
            'delete_private_posts' => 'delete_private_events',
            'delete_published_posts' => 'delete_published_events',
            'delete_others_posts' => 'delete_others_events',
            'edit_private_posts' => 'edit_private_events',
            'edit_published_posts' => 'edit_published_events',
        ],
        'fields' => [
            'event_start_date' => [
                'type' => 'string',
                'show_in_rest' => false,
            ],
            'event_end_date' => [
                'type' => 'string',
                'show_in_rest' => false,
            ],
            'event_venue_name' => [
                'type' => 'string',
                'show_in_rest' => false,
            ],
            'event_timezone' => [
                'type' => 'string',
                'show_in_rest' => false,
            ],
            'event_organizer_name' => [
                'type' => 'string',
                'show_in_rest' => false,
            ],
            'event_organizer_email' => [
                'type' => 'string',
                'show_in_rest' => false,
                'sanitize_callback' => 'sanitize_email',
            ],
        ],
    ],
    'collective' => [
        'name' => 'Collectives',
        'singular_name' => 'Collective',
        'rewrite_slug' => 'collectives',
        'supports' => [
            'title',
            'editor',
            'thumbnail',
            'custom-fields'
        ],
        'has_archive' => true,
        'menu_icon' => 'dashicons-groups',
        'taxonomies' => ['collective_association'],
        'capability_type' => [
            'collective',
            'collectives'
        ],
        'map_meta_cap' => true,
        'capabilities' => [
            'edit_post' => 'edit_collective',
            'read_post' => 'read_collective',
            'delete_post' => 'delete_collective',
            'edit_posts' => 'edit_collectives',
            'edit_others_posts' => 'edit_others_collectives',
            'publish_posts' => 'publish_collectives',
            'read_private_posts' => 'read_private_collectives',
            'delete_posts' => 'delete_collectives',
            'delete_private_posts' => 'delete_private_collectives',
            'delete_published_posts' => 'delete_published_collectives',
            'delete_others_posts' => 'delete_others_collectives',
            'edit_private_posts' => 'edit_private_collectives',
            'edit_published_posts' => 'edit_published_collectives',
        ],
        'fields' => [
            'collective_city' => [
                'type' => 'string',
            ],
            'collective_email' => [
                'type' => 'string',
                'sanitize_callback' => 'sanitize_email',
            ],
            'collective_website' => [
                'type' => 'string',
                'sanitize_callback' => 'esc_url_raw',
            ],
            'collective_since_year' => [
                'type' => 'integer',
                'default' => 0,
            ],
        ],
    ],
    'news_item' => [
        'name' => 'News',
        'singular_name' => 'News Item',
        'rest_base' => 'news_items',
        'rewrite_slug' => 'news_items',
        'supports' => [
            'title',
            'editor',
            'excerpt',
            'thumbnail',
            'custom-fields'
        ],
        'has_archive' => true,
        'menu_icon' => 'dashicons-calendar-alt',
        'taxonomies' => [
            'event_type',
            'collective_association'],
        'capability_type' => [
            'news_item',
            'news_items'],
        'map_meta_cap' => true,
        'capabilities' => [
            'edit_post' => 'edit_news_item',
            'read_post' => 'read_news_item',
            'delete_post' => 'delete_news_item',
            'edit_posts' => 'edit_news_items',
            'edit_others_posts' => 'edit_others_news_items',
            'publish_posts' => 'publish_news_items',
            'read_private_posts' => 'read_private_news_items',
            'delete_posts' => 'delete_news_items',
            'delete_private_posts' => 'delete_private_news_items',
            'delete_published_posts' => 'delete_published_news_items',
            'delete_others_posts' => 'delete_others_news_items',
            'edit_private_posts' => 'edit_private_news_items',
            'edit_published_posts' => 'edit_published_news_items',
        ],
        'fields' => [
            'event_start_date' => [
                'type' => 'string',
                'show_in_rest' => false,
            ],
            'event_end_date' => [
                'type' => 'string',
                'show_in_rest' => false,
            ],
            'event_venue_name' => [
                'type' => 'string',
                'show_in_rest' => false,
            ],
            'event_timezone' => [
                'type' => 'string',
                'show_in_rest' => false,
            ],
            'event_organizer_name' => [
                'type' => 'string',
                'show_in_rest' => false,
            ],
            'event_organizer_email' => [
                'type' => 'string',
                'show_in_rest' => false,
                'sanitize_callback' => 'sanitize_email',
            ],
        ],
    ],
];
