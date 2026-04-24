<?php

namespace abcnorio\CustomFunc\ContentModel;

final class ACFFieldGroups
{
    public static function registerHooks(): void
    {
        add_action('acf/init', [self::class, 'register']);
        add_filter('acf/validate_value', [self::class, 'validateFieldMessage'], 10, 4);
    }

    public static function register(): void
    {
        self::registerEventFields();
        self::registerNewsItemFields();
        self::registerCollectiveFields();
        self::registerPressItemFields();
    }

    private static function registerEventFields(): void
    {
        acf_add_local_field_group([
            'key'      => 'group_event_details',
            'title'    => 'Event Details',
            'show_in_rest' => true,
            'position' => 'side',
            'location' => [
                [
                    [
                        'param'    => 'post_type',
                        'operator' => '==',
                        'value'    => 'event',
                    ],
                ],
            ],
            'fields' => [
                [
                    'key'           => 'field_event_timezone',
                    'name'          => 'event_timezone',
                    'label'         => __('Time Zone', 'abcnorio-func'),
                    'type'          => 'select',
                    'choices'       => self::timezones(),
                    'default_value' => 'America/New_York',
                    'allow_null'    => 0,
                    'return_format' => 'value',
                ],
                [
                    'key'             => 'field_event_start',
                    'name'            => 'event_start_date',
                    'label'           => __('Start', 'abcnorio-func'),
                    'type'            => 'date_time_picker',
                    'display_format'  => 'd/m/Y g:i a',
                    'return_format'   => 'Y-m-d H:i:s',
                    'first_day'       => 1,
                    'required'        => 1,
                ],
                [
                    'key'             => 'field_event_end',
                    'name'            => 'event_end_date',
                    'label'           => __('End', 'abcnorio-func'),
                    'type'            => 'date_time_picker',
                    'display_format'  => 'd/m/Y g:i a',
                    'return_format'   => 'Y-m-d H:i:s',
                    'first_day'       => 1,
                ],
                [
                    'key'          => 'field_event_details',
                    'name'         => 'event_details',
                    'label'        => __('Event details', 'abcnorio-func'),
                    'type'         => 'repeater',
                    'layout'       => 'row',
                    'button_label' => __('Add detail', 'abcnorio-func'),
                    'min'          => 0,
                    'show_in_rest' => 1,
                    'sub_fields'   => [
                        [
                            'key'   => 'field_event_detail_label',
                            'name'  => 'detail_label',
                            'label' => __('Label', 'abcnorio-func'),
                            'type'  => 'text',
                        ],
                        [
                            'key'   => 'field_event_detail_text',
                            'name'  => 'detail_text',
                            'label' => __('Details', 'abcnorio-func'),
                            'type'  => 'textarea',
                            'rows'  => 3,
                        ],
                    ],
                ],
                [
                    'key'           => 'field_event_location',
                    'name'          => 'event_venue_name',
                    'label'         => __('Venue / Location', 'abcnorio-func'),
                    'type'          => 'text',
                    'default_value' => 'ABC No Rio',
                ],
                [
                    'key'           => 'field_event_venue_address',
                    'name'          => 'event_venue_address',
                    'label'         => __('Venue Details', 'abcnorio-func'),
                    'type'          => 'text',
                    'default_value' => '156 Rivington Street, New York, NY 10002',
                ],
                [
                    'key'           => 'field_event_tags',
                    'name'          => 'event_tags',
                    'label'         => __('Tags', 'abcnorio-func'),
                    'type'          => 'taxonomy',
                    'taxonomy'      => 'event_tag',
                    'field_type'    => 'multi_select',
                    'add_term'      => 1,
                    'save_terms'    => 1,
                    'load_terms'    => 1,
                    'return_format' => 'id',
                ],
                [
                    'key'           => 'field_event_tickets_url',
                    'name'          => 'event_tickets_url',
                    'label'         => __('Tickets URL', 'abcnorio-func'),
                    'type'          => 'text',
                    'default_value' => '',
                    'placeholder'   => 'https://example.com/tickets',
                ],
            ],
        ]);
    }

    private static function registerNewsItemFields(): void
    {
        acf_add_local_field_group([
            'key'      => 'group_news_item_details',
            'title'    => 'News Details',
            'show_in_rest' => true,
            'position' => 'side',
            'location' => [
                [
                    [
                        'param'    => 'post_type',
                        'operator' => '==',
                        'value'    => 'news_item',
                    ],
                ],
            ],
            'fields' => [
                [
                    'key'             => 'field_news_item_date',
                    'name'            => 'news_item_date',
                    'label'           => __('Date', 'abcnorio-func'),
                    'type'            => 'date_picker',
                    'display_format'  => 'd/m/Y',
                    'return_format'   => 'Y-m-d',
                    'first_day'       => 1,
                    'required'        => 1,
                    'validation_message' => __('Please update News Details -> Date (news_item_date).', 'abcnorio-func'),
                ],
            ],
        ]);
    }

    private static function registerCollectiveFields(): void
    {
        acf_add_local_field_group([
            'key' => 'group_collective_details',
            'title' => 'Collective Details',
            'show_in_rest' => true,
            'position' => 'side',
            'location' => [
                [
                    [
                        'param' => 'post_type',
                        'operator' => '==',
                        'value' => 'collective',
                    ],
                ],
            ],
            'fields' => [
                [
                    'key' => 'field_collective_city',
                    'name' => 'collective_city',
                    'label' => __('City', 'abcnorio-func'),
                    'type' => 'text',
                ],
                [
                    'key' => 'field_collective_email',
                    'name' => 'collective_email',
                    'label' => __('Email', 'abcnorio-func'),
                    'type' => 'email',
                ],
                [
                    'key' => 'field_collective_website',
                    'name' => 'collective_website',
                    'label' => __('Website', 'abcnorio-func'),
                    'type' => 'url',
                ],
                [
                    'key' => 'field_collective_since_year',
                    'name' => 'collective_since_year',
                    'label' => __('Active since (year)', 'abcnorio-func'),
                    'type' => 'number',
                    'default_value' => 0,
                    'min' => 0,
                    'step' => 1,
                ],
            ],
        ]);
    }

    private static function registerPressItemFields(): void
    {
        acf_add_local_field_group([
            'key'          => 'group_press_item_details',
            'title'        => 'Press Item Details',
            'show_in_rest' => true,
            'position'     => 'side',
            'location'     => [
                [
                    [
                        'param'    => 'post_type',
                        'operator' => '==',
                        'value'    => 'press_item',
                    ],
                ],
            ],
            'fields' => [
                [
                    'key'   => 'field_press_item_url',
                    'name'  => 'press_item_url',
                    'label' => __('URL', 'abcnorio-func'),
                    'type'  => 'url',
                ],
                [
                    'key'   => 'field_press_item_source',
                    'name'  => 'press_item_source',
                    'label' => __('Source', 'abcnorio-func'),
                    'type'  => 'text',
                ],
                [
                    'key'            => 'field_press_item_date',
                    'name'           => 'press_item_date',
                    'label'          => __('Date', 'abcnorio-func'),
                    'type'           => 'date_picker',
                    'display_format' => 'd/m/Y',
                    'return_format'  => 'Y-m-d',
                    'first_day'      => 1,
                ],
                [
                    'key'   => 'field_press_item_fallback_date_string',
                    'name'  => 'press_item_fallback_date_string',
                    'label' => __('Fallback Date String', 'abcnorio-func'),
                    'type'  => 'text',
                ],
            ],
        ]);
    }

    /**
     * @return array<string, string>
     */
    private static function timezones(): array
    {
        $identifiers = \DateTimeZone::listIdentifiers();

        return array_combine($identifiers, $identifiers);
    }

    /**
     * @param true|string $valid
     * @param mixed $value
     * @param array<string, mixed> $field
     * @param string $input
     * @return true|string
     */
    public static function validateFieldMessage($valid, $value, array $field, string $input)
    {
        unset($input);

        if ($valid !== true) {
            return $valid;
        }

        if (empty($field['required']) || empty($field['validation_message'])) {
            return $valid;
        }

        $isEmpty = $value === null
            || (is_string($value) && trim($value) === '')
            || (is_array($value) && $value === []);

        if (! $isEmpty) {
            return $valid;
        }

        return (string) $field['validation_message'];
    }
}