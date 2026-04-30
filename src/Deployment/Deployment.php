<?php

namespace abcnorio\CustomFunc\Deployment;

final class Deployment
{
    public static function envKeys(): array
    {
        return ['dev', 'staging', 'production', 'preview'];
    }

    public static function isValidEnv(string $env): bool
    {
        return in_array($env, self::envKeys(), true);
    }

    public static function orchestratorBaseUrl(): string
    {
        return rtrim(getenv('ASTRO_BUILD_TRIGGER_URL') ?: 'http://deploy-orchestrator:4011', '/');
    }

    public static function orchestratorSecret(): string
    {
        return (string) (getenv('ASTRO_BUILD_TRIGGER_SECRET') ?: '');
    }

    public static function registerHooks(): void
    {
        SaveTrigger::registerHooks();
        add_action('admin_menu', [DeploymentAdminPage::class, 'addMenuPage']);
        add_action('admin_notices', [DeploymentAdminPage::class, 'renderAdminNoticeIfDeploymentPage']);
        add_action('admin_enqueue_scripts', [DeploymentAdminPage::class, 'enqueueAssets']);
        add_action('wp_ajax_abcnorio_trigger_build', [DeploymentActions::class, 'triggerBuild']);
        add_action('wp_ajax_abcnorio_poll_build_status', [DeploymentActions::class, 'pollBuildStatus']);
        add_action('wp_ajax_abcnorio_push_to_staging', [DeploymentActions::class, 'pushToStaging']);
        add_action('wp_ajax_abcnorio_poll_push_status', [DeploymentActions::class, 'pollPushStatus']);
        add_action('wp_ajax_abcnorio_copy_media_to_dev', [DeploymentActions::class, 'copyMediaToDev']);
        add_action('wp_ajax_abcnorio_poll_copy_media_status', [DeploymentActions::class, 'pollCopyMediaStatus']);
        add_action('wp_ajax_abcnorio_pull_from_staging', [DeploymentActions::class, 'pullFromStaging']);
        add_action('wp_ajax_abcnorio_poll_pull_from_staging_status', [DeploymentActions::class, 'pollPullFromStagingStatus']);
        add_action('wp_ajax_abcnorio_copy_media_to_staging', [DeploymentActions::class, 'copyMediaToStaging']);
        add_action('wp_ajax_abcnorio_poll_copy_media_to_staging_status', [DeploymentActions::class, 'pollCopyMediaToStagingStatus']);
        add_action('wp_ajax_abcnorio_pull_from_dev', [DeploymentActions::class, 'pullFromDev']);
        add_action('wp_ajax_abcnorio_poll_pull_from_dev_status', [DeploymentActions::class, 'pollPullFromDevStatus']);
        add_action('wp_ajax_abcnorio_download_backup', [DeploymentActions::class, 'downloadBackup']);
        add_action('wp_ajax_abcnorio_restore_backup', [DeploymentActions::class, 'restoreBackup']);
        add_action('wp_ajax_abcnorio_delete_backup', [DeploymentActions::class, 'deleteBackup']);
    }
}
