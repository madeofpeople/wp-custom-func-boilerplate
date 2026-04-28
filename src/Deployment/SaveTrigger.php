<?php

namespace abcnorio\CustomFunc\Deployment;

final class SaveTrigger
{
    /** @var array<string, bool> */
    private static array $queuedTargets = [];

    /** @var array<string, string>|null */
    private static ?array $scopeMapCache = null;

    /** @var array<int, string> */
    private const SCOPED_SECTIONS = ['events', 'collectives', 'about', 'programming'];

    public static function registerHooks(): void
    {
        add_action('save_post', [self::class, 'queueForEligiblePostSave'], 20, 3);
        add_action('before_delete_post', [self::class, 'queueForEligiblePostDelete'], 20, 1);
    }

    private static function orchestratorBaseUrl(): string
    {
        return rtrim(getenv('ASTRO_BUILD_TRIGGER_URL') ?: 'http://deploy-orchestrator:4011', '/');
    }

    private static function orchestratorSecret(): string
    {
        return (string) (getenv('ASTRO_BUILD_TRIGGER_SECRET') ?: '');
    }

    private static function enabled(): bool
    {
        return (string) (getenv('WP_SAVE_TRIGGER_QUEUE_ENABLED') ?: '0') === '1';
    }

    private static function target(): string
    {
        $configured = sanitize_key((string) (getenv('WP_SAVE_TRIGGER_TARGET') ?: ''));
        if (in_array($configured, ['dev', 'staging'], true)) {
            return $configured;
        }

        $wpEnv = sanitize_key((string) (defined('WP_ENV') ? WP_ENV : ''));
        if (in_array($wpEnv, ['development', 'dev'], true)) {
            return 'dev';
        }

        if ($wpEnv === 'staging') {
            return 'staging';
        }

        return 'dev';
    }

    /** @return array<string, string> */
    private static function scopeMap(): array
    {
        if (self::$scopeMapCache !== null) {
            return self::$scopeMapCache;
        }

        $definitionsPath = dirname(__DIR__) . '/ContentModel/post-types.php';
        $definitions = file_exists($definitionsPath) ? include $definitionsPath : [];
        if (!is_array($definitions)) {
            self::$scopeMapCache = [];
            return self::$scopeMapCache;
        }

        $map = [];
        foreach ($definitions as $postType => $definition) {
            if (!is_string($postType) || !is_array($definition)) {
                continue;
            }

            $rewriteSlug = sanitize_key((string) ($definition['rewrite_slug'] ?? ''));
            if ($rewriteSlug === '') {
                $base = str_replace('_', '-', $postType);
                $rewriteSlug = str_ends_with($base, 's') ? $base : ($base . 's');
            }

            if ($rewriteSlug !== '') {
                $map[$postType] = $rewriteSlug;
            }
        }

        self::$scopeMapCache = $map;
        return self::$scopeMapCache;
    }

    private static function normalizeScope(string $scope): string
    {
        $scope = sanitize_key($scope);
        return ($scope === '' || $scope === 'full') ? 'full' : $scope;
    }

    private static function scopeForPagePost(\WP_Post $post): string
    {
        $candidates = [];

        $ancestors = get_post_ancestors($post);
        if (is_array($ancestors) && $ancestors !== []) {
            $topId = (int) end($ancestors);
            if ($topId > 0) {
                $top = get_post($topId);
                if ($top instanceof \WP_Post) {
                    $candidates[] = sanitize_key((string) ($top->post_name ?? ''));
                }
            }
        }

        $candidates[] = sanitize_key((string) ($post->post_name ?? ''));

        foreach ($candidates as $candidate) {
            if (in_array($candidate, ['about', 'programming'], true)) {
                return $candidate;
            }
        }

        return 'full';
    }

    private static function scopeForPostType(string $postType, ?\WP_Post $post = null): string
    {
        $postType = sanitize_key($postType);
        if ($postType === '') {
            return 'full';
        }

        if ($postType === 'page' && $post instanceof \WP_Post) {
            return self::scopeForPagePost($post);
        }

        $map = self::scopeMap();
        if (!isset($map[$postType])) {
            return 'full';
        }

        $scope = self::normalizeScope($map[$postType]);
        return in_array($scope, self::SCOPED_SECTIONS, true) ? $scope : 'full';
    }

    private static function resolveEligiblePost(int $postId, ?\WP_Post $post = null): ?\WP_Post
    {
        if (!self::enabled()) {
            return null;
        }

        if (wp_is_post_revision($postId) || wp_is_post_autosave($postId)) {
            return null;
        }

        $resolvedPost = $post instanceof \WP_Post ? $post : get_post($postId);
        if (!$resolvedPost instanceof \WP_Post) {
            return null;
        }

        if ($resolvedPost->post_status === 'auto-draft') {
            return null;
        }

        if (in_array($resolvedPost->post_type, ['attachment', 'revision', 'nav_menu_item'], true)) {
            return null;
        }

        return $resolvedPost;
    }

    private static function enqueue(string $reason, string $scope): void
    {
        $target = self::target();
        if ($target === '' || $target === 'production') {
            return;
        }

        if (isset(self::$queuedTargets[$target])) {
            return;
        }

        self::$queuedTargets[$target] = true;

        $payload = [
            'target' => $target,
            'source' => 'save',
            'scope'  => self::normalizeScope($scope),
        ];

        if ($reason !== '') {
            $payload['reason'] = $reason;
        }

        $response = wp_remote_post(self::orchestratorBaseUrl() . '/trigger', [
            'headers' => [
                'Content-Type'  => 'application/json',
                'Authorization' => 'Bearer ' . self::orchestratorSecret(),
            ],
            'body'    => wp_json_encode($payload),
            'timeout' => 5,
        ]);

        if (is_wp_error($response)) {
            error_log('[abcnorio][deploy] save-trigger enqueue failed: ' . $response->get_error_message());
            return;
        }

        $code = wp_remote_retrieve_response_code($response);
        if ($code < 200 || $code >= 300) {
            error_log('[abcnorio][deploy] save-trigger enqueue failed: HTTP ' . (string) $code);
        }
    }

    public static function queueForEligiblePostSave(int $postId, \WP_Post $post, bool $update): void
    {
        $resolvedPost = self::resolveEligiblePost($postId, $post);
        if (!$resolvedPost instanceof \WP_Post) {
            return;
        }

        wp_cache_flush();

        $scope = self::scopeForPostType((string) $resolvedPost->post_type, $resolvedPost);
        self::enqueue('save_post', $scope);
    }

    public static function queueForEligiblePostDelete(int $postId): void
    {
        $resolvedPost = self::resolveEligiblePost($postId);
        if (!$resolvedPost instanceof \WP_Post) {
            return;
        }

        wp_cache_flush();

        $scope = self::scopeForPostType((string) $resolvedPost->post_type, $resolvedPost);
        self::enqueue('before_delete_post', $scope);
    }
}
