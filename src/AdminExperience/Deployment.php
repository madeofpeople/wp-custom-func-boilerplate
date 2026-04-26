<?php

namespace abcnorio\CustomFunc\AdminExperience;

final class Deployment
{
    public static function registerHooks(): void
    {
        add_action('admin_menu', [self::class, 'addMenuPage']);
        add_action('admin_enqueue_scripts', [self::class, 'enqueueAssets']);
        add_action('wp_ajax_abcnorio_trigger_build', [self::class, 'ajaxTriggerBuild']);
        add_action('wp_ajax_abcnorio_poll_build_status', [self::class, 'ajaxPollBuildStatus']);
        add_action('wp_ajax_abcnorio_download_backup', [self::class, 'ajaxDownloadBackup']);
        add_action('wp_ajax_abcnorio_restore_backup', [self::class, 'ajaxRestoreBackup']);
    }

    private static function backupArchiveDir(): string
    {
        $configured = trim((string) (getenv('ASTRO_BUILD_ARCHIVE_DIR') ?: ''));
        if ($configured !== '') {
            return rtrim($configured, '/');
        }

        $workspaceRoot = dirname(ABCNORIO_CUSTOM_FUNC_FILE, 4);
        return rtrim($workspaceRoot, '/') . '/astro/site/build-archives';
    }

    private static function buildRootDir(string $env): string
    {
        $envKeyMap = [
            'dev' => 'DEV_BUILD_PATH',
            'staging' => 'STAGING_BUILD_PATH',
            'production' => 'PRODUCTION_BUILD_PATH',
        ];

        $envKey = $envKeyMap[$env] ?? null;
        if ($envKey !== null) {
            $configured = trim((string) (getenv($envKey) ?: ''));
            if ($configured !== '') {
                return rtrim($configured, '/');
            }
        }

        if ($env === 'production') {
            $configured = trim((string) (getenv('ASTRO_PRODUCTION_BUILD_DIR') ?: ''));
            if ($configured !== '') {
                return rtrim($configured, '/');
            }
        }

        $staticRoot = rtrim((string) (getenv('STATIC_SERVER_SITE_DIR') ?: ''), '/');
        if ($staticRoot === '') {
            return '';
        }

        $dirNameMap = [
            'dev' => 'dev',
            'staging' => 'staging',
            'production' => 'prod',
        ];

        $dirName = $dirNameMap[$env] ?? null;
        if ($dirName === null) {
            return '';
        }

        return $staticRoot . '/' . $dirName;
    }

    private static function archiveBelongsToEnv(string $name, string $env): bool
    {
        $envPrefix = 'abcnorio-astro-' . $env . '-';
        if (strpos($name, $envPrefix) === 0) {
            return true;
        }

        // Older archives were production-only and had no env segment in the name.
        return $env === 'production' && strpos($name, 'abcnorio-astro-') === 0 && strpos($name, 'abcnorio-astro-production-') !== 0;
    }

    /**
     * @return array<int, array{name: string, path: string, mtime: int}>
     */
    private static function recentBackups(string $env, int $limit = 3): array
    {
        $archiveDir = self::backupArchiveDir();
        if (!is_dir($archiveDir)) {
            return [];
        }

        $matches = glob($archiveDir . '/*.zip');
        if (!is_array($matches) || $matches === []) {
            return [];
        }

        $entries = [];
        foreach ($matches as $path) {
            if (!is_file($path)) {
                continue;
            }

            $name = basename($path);
            if (!self::archiveBelongsToEnv($name, $env)) {
                continue;
            }

            $mtime = filemtime($path);
            $entries[] = [
                'name' => $name,
                'path' => $path,
                'mtime' => is_int($mtime) ? $mtime : 0,
            ];
        }

        usort($entries, static function (array $a, array $b): int {
            return $b['mtime'] <=> $a['mtime'];
        });

        return array_slice($entries, 0, $limit);
    }

    private static function resolveBackupFile(string $requested, string $env): string
    {
        if ($requested === '') {
            wp_die(__('Missing backup file.', 'abcnorio-func'), 400);
        }

        if (!self::archiveBelongsToEnv($requested, $env)) {
            wp_die(__('Backup file not found.', 'abcnorio-func'), 404);
        }

        $archiveDir = self::backupArchiveDir();
        $archiveRealDir = realpath($archiveDir);
        if ($archiveRealDir === false) {
            wp_die(__('Backup directory not found.', 'abcnorio-func'), 404);
        }

        $candidatePath = $archiveRealDir . '/' . $requested;
        $realFile = realpath($candidatePath);
        if ($realFile === false || strpos($realFile, $archiveRealDir . '/') !== 0 || !is_file($realFile)) {
            wp_die(__('Backup file not found.', 'abcnorio-func'), 404);
        }

        return $realFile;
    }

    private static function rrmdirContents(string $dir): void
    {
        $entries = scandir($dir);
        if (!is_array($entries)) {
            return;
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $dir . '/' . $entry;
            if (is_dir($path) && !is_link($path)) {
                self::rrmdirContents($path);
                @rmdir($path);
                continue;
            }

            @unlink($path);
        }
    }

    private static function copyDirRecursive(string $source, string $destination): bool
    {
        if (!is_dir($source) || !is_dir($destination)) {
            return false;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $item) {
            $subPath = ltrim(substr((string) $item->getPathname(), strlen($source)), '/');
            $destPath = $destination . '/' . $subPath;

            if ($item->isDir()) {
                if (!is_dir($destPath) && !mkdir($destPath, 0775, true) && !is_dir($destPath)) {
                    return false;
                }

                @chmod($destPath, 0777);
                continue;
            }

            $parent = dirname($destPath);
            if (!is_dir($parent) && !mkdir($parent, 0775, true) && !is_dir($parent)) {
                return false;
            }

            if (!copy((string) $item->getPathname(), $destPath)) {
                return false;
            }

            @chmod($destPath, 0666);
        }

        return true;
    }

    private static function resolveExtractedRestoreSource(string $tempDir, string $targetDir): string
    {
        $expectedDirName = basename($targetDir);
        $expectedPath = $tempDir . '/' . $expectedDirName;
        if (is_dir($expectedPath)) {
            return $expectedPath;
        }

        $entries = scandir($tempDir);
        if (!is_array($entries)) {
            return '';
        }

        $topLevelDirs = [];
        $hasTopLevelFiles = false;

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $tempDir . '/' . $entry;
            if (is_dir($path) && !is_link($path)) {
                $topLevelDirs[] = $path;
                continue;
            }

            if (is_file($path)) {
                $hasTopLevelFiles = true;
            }
        }

        // Archive already contains production files at root.
        if ($hasTopLevelFiles) {
            return $tempDir;
        }

        // Common archive shape: exactly one top-level folder.
        if (count($topLevelDirs) === 1) {
            return $topLevelDirs[0];
        }

        // Fallback for older archives with nested paths.
        $matches = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($tempDir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $item) {
            if (!$item->isDir()) {
                continue;
            }

            if ($item->getBasename() === $expectedDirName) {
                $matches[] = (string) $item->getPathname();
            }
        }

        return count($matches) === 1 ? $matches[0] : '';
    }

    public static function addMenuPage(): void
    {
        add_menu_page(
            __('Deployment', 'abcnorio-func'),
            __('Deployment', 'abcnorio-func'),
            'manage_options',
            'abcnorio-deployment',
            [self::class, 'renderPage'],
            'dashicons-controls-repeat',
            3
        );
    }

    public static function enqueueAssets(string $hook): void
    {
        if ($hook !== 'toplevel_page_abcnorio-deployment') {
            return;
        }

        $jsUrl = plugins_url('resources/js/deployment.js', ABCNORIO_CUSTOM_FUNC_FILE);
        wp_enqueue_script('abcnorio-deployment', $jsUrl, [], '1.0.0', true);
        wp_localize_script('abcnorio-deployment', 'abcnorioDeployment', [
            'ajaxUrl'      => admin_url('admin-ajax.php'),
            'triggerNonce' => wp_create_nonce('abcnorio_trigger_build'),
            'pollNonce'    => wp_create_nonce('abcnorio_poll_build_status'),
            'previewUrls'  => [
                'dev'        => getenv('DEV_FRONTEND_URL') ?: '',
                'staging'    => getenv('STAGING_FRONTEND_URL') ?: '',
                'production' => getenv('PRODUCTION_FRONTEND_URL') ?: '',
            ],
            'buildExists'  => [
                'dev'        => self::buildExists('dev'),
                'staging'    => self::buildExists('staging'),
                'production' => self::buildExists('production'),
            ],
        ]);
    }

    private static function buildExists(string $env): bool
    {
        $buildPath = rtrim(getenv('STATIC_SERVER_SITE_DIR') ?: '', '/') . '/' . $env . '/client';
        if (!is_dir($buildPath)) {
            return false;
        }

        $entries = scandir($buildPath);
        return is_array($entries) && count(array_diff($entries, ['.', '..'])) > 0;
    }

    public static function renderPage(): void
    {
        $previewUrls = [
            'dev'        => getenv('DEV_FRONTEND_URL') ?: '',
            'staging'    => getenv('STAGING_FRONTEND_URL') ?: '',
            'production' => getenv('PRODUCTION_FRONTEND_URL') ?: '',
        ];
        $recentBackups = [
            'dev' => self::recentBackups('dev', 3),
            'staging' => self::recentBackups('staging', 3),
            'production' => self::recentBackups('production', 3),
        ];
        $backupDownloadNonce = wp_create_nonce('abcnorio_download_backup');
        $backupRestoreNonce = wp_create_nonce('abcnorio_restore_backup');
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Deployment', 'abcnorio-func'); ?></h1>

            <nav class="nav-tab-wrapper" id="deployment-tabs">
                <a href="#tab-dev" class="nav-tab nav-tab-active" data-tab="dev">
                    <?php esc_html_e('Dev', 'abcnorio-func'); ?>
                </a>
                <a href="#tab-staging" class="nav-tab" data-tab="staging">
                    <?php esc_html_e('Staging', 'abcnorio-func'); ?>
                </a>
                <a href="#tab-production" class="nav-tab" data-tab="production">
                    <?php esc_html_e('Production', 'abcnorio-func'); ?>
                </a>
            </nav>

            <?php foreach (['dev', 'staging'] as $env) : ?>
            <div
                id="tab-<?php echo esc_attr($env); ?>"
                class="deployment-tab<?php echo $env !== 'dev' ? ' hidden' : ''; ?>"
            >
                <div style="margin-top: 1.5rem; display: flex; gap: 1rem; align-items: center; flex-wrap: wrap;">
                    <button
                        class="button button-primary js-trigger-build"
                        data-target="<?php echo esc_attr($env); ?>"
                        data-label="<?php esc_attr_e('Run Static Build', 'abcnorio-func'); ?>"
                    >
                        <?php esc_html_e('Run Static Build', 'abcnorio-func'); ?>
                    </button>
                    <a
                        href="<?php echo esc_url($previewUrls[$env] ?: '#'); ?>"
                        class="button js-preview-link<?php echo (empty($previewUrls[$env]) || !self::buildExists($env)) ? ' hidden' : ''; ?>"
                        data-env="<?php echo esc_attr($env); ?>"
                        target="_blank"
                        rel="noopener"
                    >
                        <?php esc_html_e('Preview', 'abcnorio-func'); ?>
                    </a>
                    <?php if ($env === 'dev') : ?>
                    <a
                        href="<?php echo esc_url($previewUrls['dev'] ?: '#'); ?>"
                        class="button js-live-preview-link<?php echo empty($previewUrls['dev']) ? ' hidden' : ''; ?>"
                        target="_blank"
                        rel="noopener"
                    >
                        <?php esc_html_e('Development Preview', 'abcnorio-func'); ?>
                    </a>
                    <?php endif; ?>
                    <span class="js-build-status" style="color: #666;"></span>
                </div>

                <div style="margin-top: 1rem;">
                    <strong>
                        <?php
                        printf(
                            /* translators: %s: Environment label. */
                            esc_html__('%s Backups', 'abcnorio-func'),
                            esc_html(ucfirst($env))
                        );
                        ?>
                    </strong>
                    <?php if ($recentBackups[$env] === []) : ?>
                        <p style="margin: 0.5rem 0 0; color: #666;">
                            <?php esc_html_e('No backup archives found yet.', 'abcnorio-func'); ?>
                        </p>
                    <?php else : ?>
                        <ul style="margin: 0.5rem 0 0 1.25rem;">
                            <?php foreach ($recentBackups[$env] as $backup) : ?>
                                <li>
                                    <span><?php echo esc_html($backup['name']); ?></span>
                                    <span style="margin-left: 0.5rem; display: inline-flex; gap: 0.5rem;">
                                    <a
                                        href="<?php echo esc_url(add_query_arg([
                                            'action' => 'abcnorio_download_backup',
                                            'env' => rawurlencode($env),
                                            'nonce' => $backupDownloadNonce,
                                            'file' => rawurlencode($backup['name']),
                                        ], admin_url('admin-ajax.php'))); ?>"
                                        class="button button-secondary button-small"
                                    >
                                        <?php esc_html_e('Download', 'abcnorio-func'); ?>
                                    </a>
                                    <a
                                        href="<?php echo esc_url(add_query_arg([
                                            'action' => 'abcnorio_restore_backup',
                                            'env' => rawurlencode($env),
                                            'nonce' => $backupRestoreNonce,
                                            'file' => rawurlencode($backup['name']),
                                        ], admin_url('admin-ajax.php'))); ?>"
                                        class="button button-small"
                                        onclick="return confirm('<?php echo esc_js(sprintf(__('Restore this backup to %s? This replaces current files for that environment.', 'abcnorio-func'), ucfirst($env))); ?>');"
                                    >
                                        <?php esc_html_e('Restore', 'abcnorio-func'); ?>
                                    </a>
                                    </span>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>

            <div id="tab-production" class="deployment-tab hidden">
                <div style="margin-top: 1.5rem;">
                    <p style="color: #666; margin-bottom: 1rem;">
                        <?php esc_html_e('Backs up the current production build, then deploys the latest build to production.', 'abcnorio-func'); ?>
                    </p>
                    <div style="display: flex; gap: 1rem; align-items: center; flex-wrap: wrap;">
                        <button
                            class="button button-primary js-trigger-build"
                            data-target="production"
                            data-label="<?php esc_attr_e('Deploy to Production', 'abcnorio-func'); ?>"
                            data-confirm="<?php esc_attr_e('Deploy to production? This will replace the live site. Continue?', 'abcnorio-func'); ?>"
                        >
                            <?php esc_html_e('Deploy to Production', 'abcnorio-func'); ?>
                        </button>
                        <span class="js-build-status" style="color: #666;"></span>
                        <a
                            href="<?php echo esc_url($previewUrls['production'] ?: '#'); ?>"
                            class="button js-preview-link<?php echo (empty($previewUrls['production']) || !self::buildExists('production')) ? ' hidden' : ''; ?>"
                            data-env="production"
                            target="_blank"
                            rel="noopener"
                        >
                            <?php esc_html_e('View Site', 'abcnorio-func'); ?>
                        </a>
                    </div>

                    <div style="margin-top: 1rem;">
                        <strong><?php esc_html_e('Recent Production Backups', 'abcnorio-func'); ?></strong>
                        <?php if ($recentBackups['production'] === []) : ?>
                            <p style="margin: 0.5rem 0 0; color: #666;">
                                <?php esc_html_e('No backup archives found yet.', 'abcnorio-func'); ?>
                            </p>
                        <?php else : ?>
                            <ul style="margin: 0.5rem 0 0 1.25rem;">
                                <?php foreach ($recentBackups['production'] as $backup) : ?>
                                    <li>
                                        <span><?php echo esc_html($backup['name']); ?></span>
                                        <span style="margin-left: 0.5rem; display: inline-flex; gap: 0.5rem;">
                                        <a
                                            href="<?php echo esc_url(add_query_arg([
                                                'action' => 'abcnorio_download_backup',
                                                'env' => 'production',
                                                'nonce' => $backupDownloadNonce,
                                                'file' => rawurlencode($backup['name']),
                                            ], admin_url('admin-ajax.php'))); ?>"
                                            class="button button-secondary button-small"
                                        >
                                            <?php esc_html_e('Download', 'abcnorio-func'); ?>
                                        </a>
                                        <a
                                            href="<?php echo esc_url(add_query_arg([
                                                'action' => 'abcnorio_restore_backup',
                                                'env' => 'production',
                                                'nonce' => $backupRestoreNonce,
                                                'file' => rawurlencode($backup['name']),
                                            ], admin_url('admin-ajax.php'))); ?>"
                                            class="button button-small"
                                            onclick="return confirm('<?php echo esc_js(__('Restore this backup to production? This replaces current production files.', 'abcnorio-func')); ?>');"
                                        >
                                            <?php esc_html_e('Restore', 'abcnorio-func'); ?>
                                        </a>
                                        </span>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }

    public static function ajaxTriggerBuild(): void
    {
        check_ajax_referer('abcnorio_trigger_build', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Insufficient permissions'], 403);
        }

        $target = sanitize_key($_POST['target'] ?? '');
        if (!in_array($target, ['dev', 'staging', 'production'], true)) {
            wp_send_json_error(['message' => 'Invalid target'], 400);
        }

        $triggerUrl = rtrim(getenv('ASTRO_BUILD_TRIGGER_URL') ?: 'http://astro:3034', '/');
        $secret     = getenv('ASTRO_BUILD_TRIGGER_SECRET') ?: '';

        $response = wp_remote_post("{$triggerUrl}/trigger", [
            'headers' => [
                'Content-Type'  => 'application/json',
                'Authorization' => "Bearer {$secret}",
            ],
            'body'    => wp_json_encode(['target' => $target]),
            'timeout' => 10,
        ]);

        if (is_wp_error($response)) {
            wp_send_json_error(['message' => $response->get_error_message()], 502);
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if ($code === 409) {
            wp_send_json_error(['message' => 'A build is already running'], 409);
        }

        if ($code !== 202) {
            wp_send_json_error(['message' => 'Trigger failed', 'detail' => $body], $code);
        }

        wp_send_json_success($body);
    }

    public static function ajaxPollBuildStatus(): void
    {
        check_ajax_referer('abcnorio_poll_build_status', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Insufficient permissions'], 403);
        }

        $triggerUrl = rtrim(getenv('ASTRO_BUILD_TRIGGER_URL') ?: 'http://astro:3034', '/');
        $secret     = getenv('ASTRO_BUILD_TRIGGER_SECRET') ?: '';

        $response = wp_remote_get("{$triggerUrl}/status", [
            'headers' => ['Authorization' => "Bearer {$secret}"],
            'timeout' => 5,
        ]);

        if (is_wp_error($response)) {
            wp_send_json_error(['message' => $response->get_error_message()], 502);
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        wp_send_json_success($body);
    }

    public static function ajaxDownloadBackup(): void
    {
        $nonce = sanitize_text_field((string) ($_GET['nonce'] ?? ''));
        if (!wp_verify_nonce($nonce, 'abcnorio_download_backup')) {
            wp_die(__('Invalid request.', 'abcnorio-func'), 403);
        }

        if (!current_user_can('manage_options')) {
            wp_die(__('Insufficient permissions.', 'abcnorio-func'), 403);
        }

        $env = sanitize_key((string) ($_GET['env'] ?? ''));
        if (!in_array($env, ['dev', 'staging', 'production'], true)) {
            wp_die(__('Invalid backup environment.', 'abcnorio-func'), 400);
        }

        $requested = sanitize_file_name((string) ($_GET['file'] ?? ''));
        $realFile = self::resolveBackupFile($requested, $env);

        nocache_headers();
        header('Content-Description: File Transfer');
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . basename($realFile) . '"');
        header('Content-Length: ' . (string) filesize($realFile));
        readfile($realFile);
        exit;
    }

    public static function ajaxRestoreBackup(): void
    {
        $nonce = sanitize_text_field((string) ($_GET['nonce'] ?? ''));
        if (!wp_verify_nonce($nonce, 'abcnorio_restore_backup')) {
            wp_die(__('Invalid request.', 'abcnorio-func'), 403);
        }

        if (!current_user_can('manage_options')) {
            wp_die(__('Insufficient permissions.', 'abcnorio-func'), 403);
        }

        $env = sanitize_key((string) ($_GET['env'] ?? ''));
        if (!in_array($env, ['dev', 'staging', 'production'], true)) {
            wp_die(__('Invalid backup environment.', 'abcnorio-func'), 400);
        }

        $requested = sanitize_file_name((string) ($_GET['file'] ?? ''));
        $realFile = self::resolveBackupFile($requested, $env);

        $targetDir = self::buildRootDir($env);
        if ($targetDir === '' || !is_dir($targetDir)) {
            wp_die(__('Build directory not found for requested environment.', 'abcnorio-func'), 500);
        }

        if (!class_exists('\ZipArchive')) {
            wp_die(__('Zip extension is not available.', 'abcnorio-func'), 500);
        }

        $zip = new \ZipArchive();
        if ($zip->open($realFile) !== true) {
            wp_die(__('Could not open backup archive.', 'abcnorio-func'), 500);
        }

        $tempRoot = rtrim((string) sys_get_temp_dir(), '/');
        $tempDir = $tempRoot . '/abcnorio-restore-' . wp_generate_password(12, false, false);
        if (!mkdir($tempDir, 0775, true) && !is_dir($tempDir)) {
            $zip->close();
            wp_die(__('Could not prepare restore workspace.', 'abcnorio-func'), 500);
        }

        $extractOk = $zip->extractTo($tempDir);
        $zip->close();
        if (!$extractOk) {
            self::rrmdirContents($tempDir);
            @rmdir($tempDir);
            wp_die(__('Could not extract backup archive.', 'abcnorio-func'), 500);
        }

        $extractedSource = self::resolveExtractedRestoreSource($tempDir, $targetDir);
        if ($extractedSource === '') {
            self::rrmdirContents($tempDir);
            @rmdir($tempDir);
            wp_die(__('Archive structure is invalid for restore.', 'abcnorio-func'), 500);
        }

        @chmod($targetDir, 0777);
        self::rrmdirContents($targetDir);
        if (!self::copyDirRecursive($extractedSource, $targetDir)) {
            self::rrmdirContents($tempDir);
            @rmdir($tempDir);
            wp_die(__('Restore failed while copying files.', 'abcnorio-func'), 500);
        }

        @chmod($targetDir, 0777);

        self::rrmdirContents($tempDir);
        @rmdir($tempDir);

        $redirect = add_query_arg(
            [
                'page' => 'abcnorio-deployment',
                'tab' => $env,
                'restored' => rawurlencode($requested),
            ],
            admin_url('admin.php')
        );
        wp_safe_redirect($redirect);
        exit;
    }
}
