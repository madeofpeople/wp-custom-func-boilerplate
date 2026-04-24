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
            'event_tag',
            'collective_association'
        ],
        'capability_type' => [
            'event',
            'events'
        ],
        'map_meta_cap' => true,

        // ACFFieldGroups.php is canonical for editor-managed fields.
        // Only add a fields array here when explicit core meta registration
        // is needed outside ACF.
        'template' => array(
            array('core/image'),
            array('core/paragraph', array(
                'placeholder' => 'Add blurb about the event here.',
            )),
            array('core/list', array(
                'placeholder' => 'Participant Links',
            )),
        ),


    ],
    'collective' => [
        'name' => 'Collectives',
        'singular_name' => 'Collective',
        'rewrite_slug' => 'collectives',
        'show_in_nav_menus' => true,
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
            'collective_association'
        ],
        'capability_type' => [
            'news_item',
            'news_items'
        ],
        'map_meta_cap' => true,
    ],
    'press_item' => [
        'name' => 'Press',
        'singular_name' => 'Press Item',
        'rest_base' => 'press_items',
        'rewrite_slug' => 'press',
        'supports' => [
            'title',
            'editor',
            'custom-fields',
        ],
        'has_archive' => true,
        'show_in_rest' => true,
        'menu_icon' => 'dashicons-pressthis',
        'capability_type' => [
            'press_item',
            'press_items',
        ],
        'map_meta_cap' => true,
        'taxonomies'   => ['press_flag'],
    ],
];
