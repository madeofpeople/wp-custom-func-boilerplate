<?php

namespace abcnorio\CustomFunc\Search;

final class SearchEndpoint
{
    private const NAMESPACE = 'abcnorio/v1';
    private const ROUTE     = '/search';
    private const CACHE_GROUP = 'abcnorio_search';
    private const CACHE_TTL = 120;
    private const CACHE_VERSION_SEED = 'v1';
    private const CACHE_VERSION_OPTION = 'abcnorio_search_cache_version';

    public static function registerHooks(): void
    {
        add_action('rest_api_init', [self::class, 'register']);
        add_action('save_post', [self::class, 'bustCacheForPostChange'], 10, 3);
        add_action('deleted_post', [self::class, 'bustCacheForDeletedPost']);
        add_action('set_object_terms', [self::class, 'bustCacheForTermRelationship'], 10, 6);
        add_action('created_term', [self::class, 'bustCacheForTermChange'], 10, 3);
        add_action('edited_term', [self::class, 'bustCacheForTermChange'], 10, 3);
        add_action('delete_term', [self::class, 'bustCacheForDeletedTerm'], 10, 5);
    }

    public static function register(): void
    {
        register_rest_route(self::NAMESPACE, self::ROUTE, [
            'methods'             => \WP_REST_Server::READABLE,
            'callback'            => [self::class, 'handle'],
            'permission_callback' => '__return_true',
            'args'                => [
                'q' => [
                    'required'          => true,
                    'type'              => 'string',
                    'minLength'         => 2,
                    'sanitize_callback' => 'sanitize_text_field',
                ],
                'per_page' => [
                    'type'              => 'integer',
                    'default'           => 20,
                    'minimum'           => 1,
                    'maximum'           => 50,
                    'sanitize_callback' => 'absint',
                ],
            ],
        ]);
    }

    public static function bustCacheForPostChange(int $postId, \WP_Post $post, bool $update): void
    {
        if (wp_is_post_revision($postId) || wp_is_post_autosave($postId)) {
            return;
        }

        if (! in_array($post->post_type, self::searchablePostTypes(), true)) {
            return;
        }

        self::bustCacheVersion();
    }

    public static function bustCacheForDeletedPost(int $postId): void
    {
        $post = get_post($postId);
        if (! $post instanceof \WP_Post) {
            return;
        }

        if (! in_array($post->post_type, self::searchablePostTypes(), true)) {
            return;
        }

        self::bustCacheVersion();
    }

    public static function bustCacheForTermRelationship(int $objectId, $terms, array $termTaxonomyIds, string $taxonomy, bool $append, array $oldTermTaxonomyIds): void
    {
        if (! in_array($taxonomy, self::searchableTaxonomies(), true)) {
            return;
        }

        $post = get_post($objectId);
        if (! $post instanceof \WP_Post || ! in_array($post->post_type, self::searchablePostTypes(), true)) {
            return;
        }

        self::bustCacheVersion();
    }

    public static function bustCacheForTermChange(int $termId, int $ttId, string $taxonomy): void
    {
        if (! in_array($taxonomy, self::searchableTaxonomies(), true)) {
            return;
        }

        self::bustCacheVersion();
    }

    public static function bustCacheForDeletedTerm(int $termId, int $ttId, string $taxonomy, $deletedTerm, array $objectIds): void
    {
        if (! in_array($taxonomy, self::searchableTaxonomies(), true)) {
            return;
        }

        self::bustCacheVersion();
    }

    public static function handle(\WP_REST_Request $request): \WP_REST_Response
    {
        $term    = $request->get_param('q');
        $perPage = $request->get_param('per_page');

        $cacheKey = self::buildCacheKey($term, (int) $perPage);
        $cachedPayload = self::cacheGet($cacheKey);
        if ($cachedPayload !== null) {
            return new \WP_REST_Response($cachedPayload, 200);
        }

        $fullConfig = require __DIR__ . '/search-config.php';
        $synonyms   = $fullConfig['synonyms'] ?? [];
        $config     = $fullConfig['post_types'] ?? [];

        $postTypes  = array_keys($config);

        // Collect all ACF field keys and taxonomy slugs across all CPTs for batching.
        $allAcfKeys   = [];
        $allTaxonomies = [];
        foreach ($config as $postType => $ptConfig) {
            foreach (array_keys($ptConfig['acf_fields'] ?? []) as $key) {
                $allAcfKeys[$key] = true;
            }
            foreach (array_keys($ptConfig['taxonomies'] ?? []) as $tax) {
                $allTaxonomies[$tax] = true;
            }
        }
        $allAcfKeys    = array_keys($allAcfKeys);
        $allTaxonomies = array_keys($allTaxonomies);

        // Expand term with synonyms for taxonomy queries.
        $taxTerms = self::expandWithSynonyms($term, $synonyms);

        // --- Query 1: title + content via WP_Query (single SQL query) ---
        $titleContentIds = self::queryTitleContent($term, $postTypes);

        // --- Query 2: taxonomy term name/slug LIKE across all relevant taxonomies ---
        $taxonomyMatches = !empty($allTaxonomies)
            ? self::queryTaxonomies($taxTerms, $allTaxonomies)
            : [];

        // --- Query 3: ACF meta fields LIKE across all configured keys ---
        $acfMatches = !empty($allAcfKeys)
            ? self::queryAcfFields($term, $allAcfKeys)
            : [];

        // --- Query 4: event_details repeater rows (event-only, targeted keys) ---
        $eventDetailsRepeaterIds = self::queryEventDetailsRepeater($term);

        // Merge all matched post IDs.
        $allIds = array_unique(array_merge(
            $titleContentIds,
            array_keys($taxonomyMatches),
            array_keys($acfMatches),
            $eventDetailsRepeaterIds
        ));

        if (empty($allIds)) {
            $payload = self::buildResponsePayload([], 0, $term);
            self::cacheSet($cacheKey, $payload);

            return new \WP_REST_Response($payload, 200);
        }

        // Fetch post objects for all matched IDs (one query).
        $posts = get_posts([
            'post__in'            => $allIds,
            'post_type'           => $postTypes,
            'posts_per_page'      => -1,
            'post_status'         => 'publish',
            'ignore_sticky_posts' => true,
            'orderby'             => 'post__in',
        ]);

        // Score and group.
        $scored  = [];
        $lowerTerm = strtolower($term);
        $titleContentIdsSet = array_fill_keys($titleContentIds, true);
        $eventDetailsRepeaterIdsSet = array_fill_keys($eventDetailsRepeaterIds, true);

        foreach ($posts as $post) {
            $ptConfig = $config[$post->post_type] ?? null;
            if (!$ptConfig) {
                continue;
            }

            $score = 0;

            // Title/content match scores.
            if (isset($titleContentIdsSet[$post->ID])) {
                $titleWeight   = $ptConfig['fields']['post_title']['relevance']   ?? 0;
                $contentWeight = $ptConfig['fields']['post_content']['relevance'] ?? 0;
                $lowerTitle    = strtolower($post->post_title);

                if (stripos($post->post_title, $term) !== false) {
                    $multiplier = str_starts_with($lowerTitle, $lowerTerm) ? 1.5 : 1.0;
                    $score += (int) round($titleWeight * $multiplier);
                }
                if (stripos($post->post_content, $term) !== false) {
                    $score += $contentWeight;
                }
            }

            // Taxonomy match scores.
            if (isset($taxonomyMatches[$post->ID])) {
                foreach ($taxonomyMatches[$post->ID] as $matchedTax) {
                    $score += $ptConfig['taxonomies'][$matchedTax]['relevance'] ?? 0;
                }
            }

            // ACF match scores.
            if (isset($acfMatches[$post->ID])) {
                foreach ($acfMatches[$post->ID] as $matchedKey) {
                    $score += $ptConfig['acf_fields'][$matchedKey]['relevance'] ?? 0;
                }
            }

            if ($post->post_type === 'event' && isset($eventDetailsRepeaterIdsSet[$post->ID])) {
                $score += $ptConfig['acf_fields']['event_details']['relevance'] ?? 0;
            }

            $scored[] = [
                'post'   => $post,
                'config' => $ptConfig,
                'score'  => $score,
            ];
        }

        // Sort by score descending.
        usort($scored, fn($a, $b) => $b['score'] <=> $a['score']);

        // Apply per_page limit after scoring so top results across all types are kept.
        $scored = array_slice($scored, 0, $perPage);

        $payload = self::buildResponsePayload($scored, count($allIds), $term);
        self::cacheSet($cacheKey, $payload);

        return new \WP_REST_Response($payload, 200);
    }

    // -------------------------------------------------------------------------
    // Query helpers
    // -------------------------------------------------------------------------

    /** @return int[] */
    private static function queryTitleContent(string $term, array $postTypes): array
    {
        $query = new \WP_Query([
            's'                   => $term,
            'post_type'           => $postTypes,
            'post_status'         => 'publish',
            'posts_per_page'      => 200,
            'fields'              => 'ids',
            'no_found_rows'       => true,
            'ignore_sticky_posts' => true,
        ]);

        return array_map('intval', $query->posts);
    }

    /**
     * Returns [ post_id => [matched_taxonomy_slug, ...], ... ]
     *
     * @param  string[] $terms      Search terms (original + synonyms)
     * @param  string[] $taxonomies Taxonomy slugs to search
     * @return array<int, string[]>
     */
    private static function queryTaxonomies(array $terms, array $taxonomies): array
    {
        global $wpdb;

        $taxPlaceholders = implode(',', array_fill(0, count($taxonomies), '%s'));

        $likeClauses = [];
        $likeValues  = [];
        foreach ($terms as $t) {
            $likeClauses[] = "(t.name LIKE %s OR t.slug LIKE %s)";
            $like = '%' . $wpdb->esc_like($t) . '%';
            $likeValues[]  = $like;
            $likeValues[]  = $like;
        }
        $likeSQL = implode(' OR ', $likeClauses);

        $sql = $wpdb->prepare(
            "SELECT tr.object_id, tt.taxonomy
             FROM {$wpdb->term_relationships} tr
             INNER JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
             INNER JOIN {$wpdb->terms} t ON tt.term_id = t.term_id
             WHERE tt.taxonomy IN ($taxPlaceholders)
               AND ($likeSQL)",
            array_merge($taxonomies, $likeValues)
        );

        $rows    = $wpdb->get_results($sql);
        $matches = [];
        foreach ($rows as $row) {
            $matches[(int) $row->object_id][] = $row->taxonomy;
        }
        return $matches;
    }

    /**
     * Returns [ post_id => [matched_meta_key, ...], ... ]
     *
     * @param  string[] $keys ACF/meta keys to search
     * @return array<int, string[]>
     */
    private static function queryAcfFields(string $term, array $keys): array
    {
        global $wpdb;

        $keyPlaceholders = implode(',', array_fill(0, count($keys), '%s'));
        $like            = '%' . $wpdb->esc_like($term) . '%';

        $sql = $wpdb->prepare(
            "SELECT post_id, meta_key
             FROM {$wpdb->postmeta}
             WHERE meta_key IN ($keyPlaceholders)
               AND meta_value LIKE %s",
            array_merge($keys, [$like])
        );

        $rows    = $wpdb->get_results($sql);
        $matches = [];
        foreach ($rows as $row) {
            $matches[(int) $row->post_id][] = $row->meta_key;
        }
        return $matches;
    }

    /** @return int[] */
    private static function queryEventDetailsRepeater(string $term): array
    {
        global $wpdb;

        $like = '%' . $wpdb->esc_like($term) . '%';

        $sql = $wpdb->prepare(
            "SELECT DISTINCT pm.post_id
             FROM {$wpdb->postmeta} pm
             INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
             WHERE p.post_type = %s
               AND p.post_status = %s
               AND (
                   pm.meta_key LIKE %s
                   OR pm.meta_key LIKE %s
               )
               AND pm.meta_value LIKE %s",
            'event',
            'publish',
            'event_details_%_detail_label',
            'event_details_%_detail_text',
            $like
        );

        return array_map('intval', $wpdb->get_col($sql));
    }

    // -------------------------------------------------------------------------
    // Synonym expansion
    // -------------------------------------------------------------------------

    /** @return string[] Original term plus any configured synonyms */
    private static function expandWithSynonyms(string $term, array $synonyms): array
    {
        $terms     = [$term];
        $lowerTerm = strtolower($term);

        foreach ($synonyms as $key => $extras) {
            if (strtolower($key) === $lowerTerm) {
                $terms = array_unique(array_merge($terms, $extras));
                break;
            }
        }

        return $terms;
    }

    // -------------------------------------------------------------------------
    // Response builder
    // -------------------------------------------------------------------------

    private static function buildResponsePayload(array $scored, int $total, string $term): array
    {
        $frontendUrl = rtrim(getenv('HEADLESS_FRONTEND_URL') ?: '', '/');
        $groups      = [];

        foreach ($scored as $item) {
            $post    = $item['post'];
            $ptConfig = $item['config'];
            $group   = $ptConfig['group'];
            $pattern = $ptConfig['url_pattern'];

            $result = [
                'id'    => $post->ID,
                'title' => $post->post_title,
                'type'  => $post->post_type,
                'score' => $item['score'],
            ];

            if ($post->post_type === 'event') {
                $startDate = get_post_meta($post->ID, 'event_start_date', true);
                if ($startDate) {
                    $result['event_start_date'] = $startDate;
                }
            }

            if ($pattern !== null) {
                $result['url'] = $frontendUrl . str_replace('{slug}', $post->post_name, $pattern);
            }

            $groups[$group][] = $result;
        }

        return [
            'query'   => $term,
            'total'   => $total,
            'results' => $groups,
        ];
    }

    private static function buildCacheKey(string $term, int $perPage): string
    {
        $normalizedTerm = strtolower(trim($term));
        $version = self::currentCacheVersion();

        // Versioned keys let us invalidate search results immediately on content changes
        // without having to enumerate and delete every cached query variant.
        return 'search:' . md5($version . '|' . $normalizedTerm . '|' . $perPage);
    }

    private static function cacheGet(string $cacheKey): ?array
    {
        $found = false;
        $cached = wp_cache_get($cacheKey, self::CACHE_GROUP, false, $found);

        if ($found && is_array($cached)) {
            return $cached;
        }

        $transient = get_transient($cacheKey);

        return is_array($transient) ? $transient : null;
    }

    private static function cacheSet(string $cacheKey, array $payload): void
    {
        wp_cache_set($cacheKey, $payload, self::CACHE_GROUP, self::CACHE_TTL);
        set_transient($cacheKey, $payload, self::CACHE_TTL);
    }

    /** @return array<string, mixed> */
    private static function searchConfig(): array
    {
        return require __DIR__ . '/search-config.php';
    }

    /** @return string[] */
    private static function searchablePostTypes(): array
    {
        $config = self::searchConfig();

        return array_keys($config['post_types'] ?? []);
    }

    /** @return string[] */
    private static function searchableTaxonomies(): array
    {
        $config = self::searchConfig();
        $taxonomies = [];

        foreach ($config['post_types'] ?? [] as $postTypeConfig) {
            foreach (array_keys($postTypeConfig['taxonomies'] ?? []) as $taxonomy) {
                $taxonomies[$taxonomy] = true;
            }
        }

        return array_keys($taxonomies);
    }

    private static function currentCacheVersion(): string
    {
        $version = get_option(self::CACHE_VERSION_OPTION);

        return is_string($version) && $version !== ''
            ? $version
            : self::CACHE_VERSION_SEED;
    }

    private static function bustCacheVersion(): void
    {
        update_option(self::CACHE_VERSION_OPTION, self::CACHE_VERSION_SEED . ':' . microtime(true), false);
    }
}
