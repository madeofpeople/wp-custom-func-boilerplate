<?php

namespace abcnorio\CustomFunc\Deployment;

final class DeploymentAdminPage
{
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

    public static function renderAdminNoticeIfDeploymentPage(): void
    {
        $page = sanitize_key((string) ($_GET['page'] ?? ''));
        if ($page !== 'abcnorio-deployment') {
            return;
        }

        $noticeType = sanitize_key((string) ($_GET['deployment_notice'] ?? ''));
        $noticeMessage = sanitize_text_field((string) ($_GET['deployment_message'] ?? ''));

        if ($noticeType !== '' && $noticeMessage !== '') {
            $class = $noticeType === 'error' ? 'notice notice-error is-dismissible' : 'notice notice-success is-dismissible';
            printf(
                '<div class="%1$s"><p>%2$s</p></div>',
                esc_attr($class),
                esc_html($noticeMessage)
            );
        }

        $restored = sanitize_file_name((string) ($_GET['restored'] ?? ''));
        if ($restored !== '') {
            printf(
                '<div class="notice notice-success is-dismissible"><p>%s</p></div>',
                esc_html(sprintf(__('Restore completed: %s', 'abcnorio-func'), $restored))
            );
        }

        $deployed = sanitize_key((string) ($_GET['deployed'] ?? ''));
        if ($deployed !== '' && $noticeMessage === '') {
            $message = $deployed === 'production'
                ? __('Backup and deployment complete.', 'abcnorio-func')
                : sprintf(
                    __('Deployment complete for %s.', 'abcnorio-func'),
                    ucfirst($deployed)
                );

            printf(
                '<div class="notice notice-success is-dismissible"><p>%s</p></div>',
                esc_html($message)
            );
        }
    }

    /**
     * @return array{statusOk: bool, statusMessage: string, targets: array<string, array{previewUrl: string, hasBuild: bool, backups: array<int, array{name: string, mtime: int}>}>}
     */
    private static function deploymentTargets(): array
    {
        $status = DeploymentStatus::read();
        $targets = [];
        $previewEnvMap = [
            'dev' => 'DEV_FRONTEND_URL',
            'staging' => 'STAGING_FRONTEND_URL',
            'production' => 'PRODUCTION_FRONTEND_URL',
            'preview' => 'PREVIEW_FRONTEND_URL',
        ];

        foreach (Deployment::envKeys() as $env) {
            $envStatus = $status['ok'] ? ($status['status']['envs'][$env] ?? []) : [];
            $currentBuild = is_array($envStatus) ? ($envStatus['currentBuild'] ?? []) : [];
            $hasBuild = is_array($currentBuild) && isset($currentBuild['hasBuild']) && is_bool($currentBuild['hasBuild'])
                ? $currentBuild['hasBuild']
                : false;

            $targets[$env] = [
                'previewUrl' => (string) (getenv($previewEnvMap[$env]) ?: ''),
                'hasBuild' => $hasBuild,
                'backups' => in_array($env, ['dev', 'staging'], true) ? [] : DeploymentStatus::normalizeBackups($envStatus),
            ];
        }

        return [
            'statusOk' => $status['ok'],
            'statusMessage' => $status['message'],
            'targets' => $targets,
        ];
    }

    public static function enqueueAssets(string $hook): void
    {
        if ($hook !== 'toplevel_page_abcnorio-deployment') {
            return;
        }

        $view = self::deploymentTargets();
        $jsUrl = plugins_url('resources/js/deployment.js', ABCNORIO_CUSTOM_FUNC_FILE);
        wp_enqueue_script('abcnorio-deployment', $jsUrl, [], '1.0.2', true);
        wp_localize_script('abcnorio-deployment', 'abcnorioDeployment', [
            'ajaxUrl'             => admin_url('admin-ajax.php'),
            'triggerNonce'        => wp_create_nonce('abcnorio_trigger_build'),
            'pollNonce'           => wp_create_nonce('abcnorio_poll_build_status'),
            'pushToStagingNonce'  => wp_create_nonce('abcnorio_push_to_staging'),
            'pollPushNonce'       => wp_create_nonce('abcnorio_poll_push_status'),
            'copyMediaNonce'           => wp_create_nonce('abcnorio_copy_media_to_dev'),
            'pollCopyMediaNonce'        => wp_create_nonce('abcnorio_poll_copy_media_status'),
            'pullFromStagingNonce'               => wp_create_nonce('abcnorio_pull_from_staging'),
            'pollPullFromStagingNonce'             => wp_create_nonce('abcnorio_poll_pull_from_staging_status'),
            'copyMediaToStagingNonce'              => wp_create_nonce('abcnorio_copy_media_to_staging'),
            'pollCopyMediaToStagingNonce'          => wp_create_nonce('abcnorio_poll_copy_media_to_staging_status'),
            'pullFromDevNonce'                     => wp_create_nonce('abcnorio_pull_from_dev'),
            'pollPullFromDevNonce'                 => wp_create_nonce('abcnorio_poll_pull_from_dev_status'),
            'targets'                  => $view['targets'],
            'statusOk'            => $view['statusOk'],
        ]);
    }

    public static function renderPage(): void
    {
        $activeTab = sanitize_key((string) ($_GET['tab'] ?? 'dev'));
        if (!Deployment::isValidEnv($activeTab)) {
            $activeTab = 'dev';
        }

        $view = self::deploymentTargets();
        $targets = $view['targets'];
        $statusOk = $view['statusOk'];
        $statusMessage = $view['statusMessage'];
        $backupDownloadNonce = wp_create_nonce('abcnorio_download_backup');
        $backupRestoreNonce = wp_create_nonce('abcnorio_restore_backup');
        $backupDeleteNonce = wp_create_nonce('abcnorio_delete_backup');
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Deployment', 'abcnorio-func'); ?></h1>
            <?php if (!$statusOk) : ?>
                <div class="notice notice-error" style="margin: 1rem 0;">
                    <p><strong><?php esc_html_e('Deployment actions are currently blocked.', 'abcnorio-func'); ?></strong></p>
                    <p><?php echo esc_html($statusMessage); ?></p>
                    <p style="margin-bottom: 0;">
                        <?php esc_html_e('Troubleshooting: verify orchestrator is running, run one successful deploy to regenerate status, then refresh this page.', 'abcnorio-func'); ?>
                    </p>
                </div>
            <?php endif; ?>

            <nav class="nav-tab-wrapper" id="deployment-tabs">
                <a href="#tab-dev" class="nav-tab<?php echo $activeTab === 'dev' ? ' nav-tab-active' : ''; ?>" data-tab="dev">
                    <?php esc_html_e('Dev', 'abcnorio-func'); ?>
                </a>
                <a href="#tab-staging" class="nav-tab<?php echo $activeTab === 'staging' ? ' nav-tab-active' : ''; ?>" data-tab="staging">
                    <?php esc_html_e('Staging', 'abcnorio-func'); ?>
                </a>
                <a href="#tab-production" class="nav-tab<?php echo $activeTab === 'production' ? ' nav-tab-active' : ''; ?>" data-tab="production">
                    <?php esc_html_e('Production', 'abcnorio-func'); ?>
                </a>
            </nav>

            <?php foreach (['dev', 'staging'] as $env) : ?>
            <div
                id="tab-<?php echo esc_attr($env); ?>"
                class="deployment-tab<?php echo $activeTab !== $env ? ' hidden' : ''; ?>"
            >
                <div style="margin-top: 1.5rem;">
                    <a
                        href="<?php echo esc_url($targets[$env]['previewUrl'] ?: '#'); ?>"
                        class="button button-primary"
                        rel="noopener"
                        <?php echo empty($targets[$env]['previewUrl']) ? 'hidden' : ''; ?>
                    >
                        <?php echo esc_html(sprintf(__('View %s Site', 'abcnorio-func'), ucfirst($env))); ?>
                    </a>
                </div>
                <p style="margin: 1rem 0 0; color: #666;">
                    <em><?php esc_html_e('Dev and staging frontends update live from the CMS. Build and restore actions are production-only.', 'abcnorio-func'); ?></em>
                </p>
                <?php if ($env === 'dev') : ?>
                <div style="margin-top: 1.5rem;">
                    <span class="js-push-status" style="color: #666; display: block; margin-bottom: 0.5rem;"></span>
                    <button
                        class="button button-primary js-push-to-staging"
                        data-label="<?php esc_attr_e('Push Code to Staging', 'abcnorio-func'); ?>"
                    >
                        <?php esc_html_e('Push Code to Staging', 'abcnorio-func'); ?>
                    </button>
                    <p style="margin: 0.75rem 0 0; color: #666; font-size: 0.875em;">
                        <em><?php esc_html_e('Copies dev source to staging. Staging frontend picks up changes via HMR. Restart astro-staging if package.json changed.', 'abcnorio-func'); ?></em>
                    </p>
                </div>
                <div style="margin-top: 1.5rem;">
                    <span class="js-pull-from-dev-status" style="color: #666; display: block; margin-bottom: 0.5rem;"></span>
                    <button
                        class="button button-primary js-pull-from-dev"
                        data-label="<?php esc_attr_e('Push Data to Staging', 'abcnorio-func'); ?>"
                    >
                        <?php esc_html_e('Push Data to Staging', 'abcnorio-func'); ?>
                    </button>
                    <p style="margin: 0.75rem 0 0; color: #666; font-size: 0.875em;">
                        <em><?php esc_html_e('Copies dev database and uploads to staging. Overwrites all staging content.', 'abcnorio-func'); ?></em>
                    </p>
                </div>
                <div style="margin-top: 1.5rem;">
                    <span class="js-copy-media-to-staging-status" style="color: #666; display: block; margin-bottom: 0.5rem;"></span>
                    <button
                        class="button button-primary js-copy-media-to-staging"
                        data-label="<?php esc_attr_e('Push Media to Staging', 'abcnorio-func'); ?>"
                    >
                        <?php esc_html_e('Push Media to Staging', 'abcnorio-func'); ?>
                    </button>
                    <p style="margin: 0.75rem 0 0; color: #666; font-size: 0.875em;">
                        <em><?php esc_html_e('Syncs dev WordPress uploads to staging. Existing staging media is overwritten.', 'abcnorio-func'); ?></em>
                    </p>
                </div>
                <?php endif; ?>
                <?php if ($env === 'staging') : ?>
                <div style="margin-top: 1.5rem;">
                    <span class="js-pull-from-staging-status" style="color: #666; display: block; margin-bottom: 0.5rem;"></span>
                    <button
                        class="button button-primary js-pull-from-staging"
                        data-label="<?php esc_attr_e('Push Data to Dev', 'abcnorio-func'); ?>"
                    >
                        <?php esc_html_e('Push Data to Dev', 'abcnorio-func'); ?>
                    </button>
                    <p style="margin: 0.75rem 0 0; color: #666; font-size: 0.875em;">
                        <em><?php esc_html_e('Copies staging database and uploads to dev. Overwrites all dev content.', 'abcnorio-func'); ?></em>
                    </p>
                </div>
                <div style="margin-top: 1.5rem;">
                    <span class="js-copy-media-status" style="color: #666; display: block; margin-bottom: 0.5rem;"></span>
                    <button
                        class="button button-primary js-copy-media-to-dev"
                        data-label="<?php esc_attr_e('Push Media to Dev', 'abcnorio-func'); ?>"
                    >
                        <?php esc_html_e('Push Media to Dev', 'abcnorio-func'); ?>
                    </button>
                    <p style="margin: 0.75rem 0 0; color: #666; font-size: 0.875em;">
                        <em><?php esc_html_e('Syncs staging WordPress uploads to dev. Existing dev media is overwritten.', 'abcnorio-func'); ?></em>
                    </p>
                </div>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>

            <div id="tab-production" class="deployment-tab<?php echo $activeTab !== 'production' ? ' hidden' : ''; ?>">
                <span class="js-build-status" style="color: #666; display: block; margin-top: 1.5rem;"></span>
                <div style="margin-top: 0.75rem;">
                    <div style="display: flex; gap: 1rem; align-items: center; flex-wrap: wrap;">
                        <button
                            class="button button-primary js-trigger-build"
                            data-target="preview"
                            data-label="<?php esc_attr_e('Generate Production Preview', 'abcnorio-func'); ?>"
                            <?php disabled(!$statusOk); ?>
                        >
                            <?php esc_html_e('Generate Production Preview', 'abcnorio-func'); ?>
                        </button>
                        <a
                            href="<?php echo esc_url($targets['preview']['previewUrl'] ?: '#'); ?>"
                            class="button button-primary js-preview-link<?php echo (empty($targets['preview']['previewUrl']) || !$targets['preview']['hasBuild']) ? ' hidden' : ''; ?>"
                            data-env="preview"
                            target="_blank"
                            rel="noopener"
                        >
                            <?php esc_html_e('Preview Build', 'abcnorio-func'); ?>
                        </a>
                    </div>

                    <p style="color: #666; margin: 1rem 0 1rem;">
                        <?php esc_html_e('Backs up the current production build, then deploys the latest build to production.', 'abcnorio-func'); ?>
                    </p>

                    <div style="display: flex; gap: 1rem; align-items: center; flex-wrap: wrap;">
                        <button
                            class="button button-primary js-trigger-build"
                            data-target="production"
                            data-label="<?php esc_attr_e('Deploy Staging to Production', 'abcnorio-func'); ?>"
                            data-confirm="<?php esc_attr_e('Deploy Staging to production? This will replace the live site. Continue?', 'abcnorio-func'); ?>"
                            <?php disabled(!$statusOk); ?>
                        >
                            <?php esc_html_e('Deploy Staging to Production', 'abcnorio-func'); ?>
                        </button>
                        <a
                            href="<?php echo esc_url($targets['production']['previewUrl'] ?: '#'); ?>"
                            class="button<?php echo empty($targets['production']['previewUrl']) ? ' hidden' : ''; ?>"
                            target="_blank"
                            rel="noopener"
                        >
                            <?php esc_html_e('View Live Site', 'abcnorio-func'); ?>
                        </a>
                    </div>

                    <div style="margin-top: 1rem; padding-top: 2rem;">
                        <strong><?php esc_html_e('Recent Production Backups', 'abcnorio-func'); ?></strong>
                        <?php if ($targets['production']['backups'] === []) : ?>
                            <p style="margin: 0.5rem 0 0; color: #666;">
                                <?php esc_html_e('No backup archives found yet.', 'abcnorio-func'); ?>
                            </p>
                        <?php else : ?>
                            <ul class="js-backup-list">
                                <?php foreach ($targets['production']['backups'] as $backup) : ?>
                                    <li class="backup-item">
                                        <span class="backup-item-name"><?php echo esc_html($backup['name']); ?></span>
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
                                            class="button button-small<?php echo !$statusOk ? ' disabled' : ''; ?>"
                                            <?php if (!$statusOk) : ?>aria-disabled="true" onclick="return false;"<?php else : ?>
                                            onclick="return confirm('<?php echo esc_js(__('Restore this backup to production? This replaces current production files.', 'abcnorio-func')); ?>');"
                                            <?php endif; ?>
                                        >
                                            <?php esc_html_e('Restore', 'abcnorio-func'); ?>
                                        </a>
                                        <form method="post" action="<?php echo esc_url(admin_url('admin-ajax.php')); ?>" style="display:inline;" onsubmit="return confirm('<?php echo esc_js(__('Delete this backup? This cannot be undone.', 'abcnorio-func')); ?>');">
                                            <input type="hidden" name="action" value="abcnorio_delete_backup">
                                            <input type="hidden" name="nonce" value="<?php echo esc_attr($backupDeleteNonce); ?>">
                                            <input type="hidden" name="env" value="production">
                                            <input type="hidden" name="file" value="<?php echo esc_attr($backup['name']); ?>">
                                            <button type="submit" class="button button-small" style="color:#b32d2e;"><?php esc_html_e('Delete', 'abcnorio-func'); ?></button>
                                        </form>
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
}
