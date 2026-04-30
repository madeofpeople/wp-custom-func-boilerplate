<?php

namespace abcnorio\CustomFunc\AdminExperience;

final class AllowedBlocks
{
    /**
     * Allowed block types per post type.
     * Use '*' as the key for a global fallback applied to all post types not listed.
     * An empty array means no blocks are allowed for that post type.
     * Omitting a post type entirely means all blocks are allowed for it.
     *
     * All core WordPress block names (as of WP 6.x) for reference:
     *
     * Text
     *   core/paragraph, core/heading, core/list, core/list-item,
     *   core/quote, core/pullquote, core/verse, core/preformatted,
     *   core/code, core/classic
     *
     * Media
     *   core/image, core/gallery, core/audio, core/video,
     *   core/cover, core/file, core/media-text
     *
     * Design / Layout
     *   core/buttons, core/button, core/group, core/columns, core/column,
     *   core/separator, core/spacer, core/stack, core/row,
     *   core/details, core/footnotes
     *
     * Embeds
     *   core/embed, core/html
     *
     * Widgets
     *   core/shortcode, core/archives, core/calendar, core/categories,
     *   core/latest-comments, core/latest-posts, core/page-list,
     *   core/rss, core/search, core/social-links, core/social-link,
     *   core/tag-cloud
     *
     * Theme
     *   core/navigation, core/navigation-link, core/navigation-submenu,
     *   core/site-logo, core/site-tagline, core/site-title,
     *   core/template-part, core/query, core/query-loop,
     *   core/query-pagination, core/query-pagination-next,
     *   core/query-pagination-numbers, core/query-pagination-previous,
     *   core/query-title, core/post-title, core/post-content,
     *   core/post-date, core/post-excerpt, core/post-featured-image,
     *   core/post-terms, core/post-author, core/post-author-biography,
     *   core/post-comments-form, core/read-more,
     *   core/home-link, core/loginout, core/term-description,
     *   core/archive-title, core/avatar
     */
    private const array ALLOWED = [
        '*' => [
            'core/paragraph',
            'core/heading',
            'core/image',
            'core/gallery',
            'core/list',
            'core/list-item',
            'core/quote',
            'core/buttons',
            'core/button',
            'core/group',
            // 'core/columns',
            // 'core/column',
            // 'core/separator',
            // 'core/spacer',
            'core/embed',
            'core/html',
        ],
    ];

    public static function registerHooks(): void
    {
        add_filter('allowed_block_types_all', [self::class, 'filterAllowedBlocks'], 10, 2);
    }

    public static function filterAllowedBlocks(bool|array $allowedBlocks, \WP_Block_Editor_Context $context): bool|array
    {
        $postType = $context->post?->post_type ?? '';

        if (isset(self::ALLOWED[$postType])) {
            return self::ALLOWED[$postType];
        }

        if (isset(self::ALLOWED['*'])) {
            return self::ALLOWED['*'];
        }

        return $allowedBlocks;
    }
}
