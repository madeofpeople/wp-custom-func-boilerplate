<?php

namespace abcnorio\CustomFunc\Deployment;

final class DeploymentActions
{
    private static function resolveBackupFile(string $requested, string $env): string
    {
        if ($requested === '') {
            wp_die(__('Missing backup file.', 'abcnorio-func'), 400);
        }

        $statusBackups = DeploymentStatus::backupNamesForEnv($env);
        if (!$statusBackups['ok']) {
            wp_die(__('Backup list unavailable from deployment status. Check status file health and retry.', 'abcnorio-func'), 503);
        }

        if (!in_array($requested, $statusBackups['names'], true)) {
            wp_die(__('Backup file not found.', 'abcnorio-func'), 404);
        }

        $archiveDir = DeploymentStatus::backupArchiveDir();
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

    public static function triggerBuild(): void
    {
        check_ajax_referer('abcnorio_trigger_build', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Insufficient permissions'], 403);
        }

        $target = sanitize_key($_POST['target'] ?? '');
        if (!Deployment::isValidEnv($target)) {
            wp_send_json_error(['message' => 'Invalid target'], 400);
        }

        $response = wp_remote_post(Deployment::orchestratorBaseUrl() . '/trigger', [
            'headers' => [
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . Deployment::orchestratorSecret(),
            ],
            'body' => wp_json_encode([
                'target' => $target,
                'scope' => 'full',
            ]),
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

    public static function pollBuildStatus(): void
    {
        check_ajax_referer('abcnorio_poll_build_status', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Insufficient permissions'], 403);
        }

        $response = wp_remote_get(Deployment::orchestratorBaseUrl() . '/status', [
            'headers' => ['Authorization' => 'Bearer ' . Deployment::orchestratorSecret()],
            'timeout' => 5,
        ]);

        if (is_wp_error($response)) {
            wp_send_json_error(['message' => $response->get_error_message()], 502);
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        wp_send_json_success($body);
    }

    public static function downloadBackup(): void
    {
        $nonce = sanitize_text_field((string) ($_GET['nonce'] ?? ''));
        if (!wp_verify_nonce($nonce, 'abcnorio_download_backup')) {
            wp_die(__('Invalid request.', 'abcnorio-func'), 403);
        }

        if (!current_user_can('manage_options')) {
            wp_die(__('Insufficient permissions.', 'abcnorio-func'), 403);
        }

        $env = sanitize_key((string) ($_GET['env'] ?? ''));
        if (!Deployment::isValidEnv($env)) {
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

    public static function restoreBackup(): void
    {
        $nonce = sanitize_text_field((string) ($_GET['nonce'] ?? ''));
        if (!wp_verify_nonce($nonce, 'abcnorio_restore_backup')) {
            wp_die(__('Invalid request.', 'abcnorio-func'), 403);
        }

        if (!current_user_can('manage_options')) {
            wp_die(__('Insufficient permissions.', 'abcnorio-func'), 403);
        }

        $env = sanitize_key((string) ($_GET['env'] ?? ''));
        if (!Deployment::isValidEnv($env)) {
            wp_die(__('Invalid backup environment.', 'abcnorio-func'), 400);
        }

        $requested = sanitize_file_name((string) ($_GET['file'] ?? ''));
        self::resolveBackupFile($requested, $env);

        $triggerUrl = Deployment::orchestratorBaseUrl();
        $secret = Deployment::orchestratorSecret();

        $response = wp_remote_post("{$triggerUrl}/restore", [
            'headers' => [
                'Content-Type'  => 'application/json',
                'Authorization' => "Bearer {$secret}",
            ],
            'body' => wp_json_encode([
                'target' => $env,
                'file' => $requested,
            ]),
            'timeout' => 120,
        ]);

        if (is_wp_error($response)) {
            wp_die(
                sprintf(
                    /* translators: %s: Network error details. */
                    __('Restore request failed: %s', 'abcnorio-func'),
                    $response->get_error_message()
                ),
                502
            );
        }

        $statusCode = wp_remote_retrieve_response_code($response);
        if ($statusCode < 200 || $statusCode >= 300) {
            $body = json_decode((string) wp_remote_retrieve_body($response), true);
            $message = is_array($body) ? (string) ($body['message'] ?? $body['error'] ?? '') : '';
            wp_die(
                $message !== ''
                    ? sprintf(
                        /* translators: %s: Restore failure message from orchestrator. */
                        __('Restore failed: %s', 'abcnorio-func'),
                        $message
                    )
                    : __('Restore failed.', 'abcnorio-func'),
                502
            );
        }

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
