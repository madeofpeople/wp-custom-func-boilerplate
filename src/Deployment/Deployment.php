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
        add_action('wp_ajax_abcnorio_download_backup', [DeploymentActions::class, 'downloadBackup']);
        add_action('wp_ajax_abcnorio_restore_backup', [DeploymentActions::class, 'restoreBackup']);
        add_action('wp_ajax_abcnorio_delete_backup', [DeploymentActions::class, 'deleteBackup']);
    }
}
