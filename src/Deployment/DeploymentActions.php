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

        if (in_array($target, ['dev', 'staging'], true)) {
            wp_send_json_error(['message' => 'Build triggers are not available for dev and staging'], 403);
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
            $orchestratorMessage = is_array($body) ? (string) ($body['error'] ?? $body['message'] ?? '') : '';
            $errorMessage = $orchestratorMessage !== '' ? 'Trigger failed: ' . $orchestratorMessage : 'Trigger failed (HTTP ' . $code . ')';
            wp_send_json_error(['message' => $errorMessage, 'detail' => $body], $code);
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

    public static function deleteBackup(): void
    {
        $nonce = sanitize_text_field((string) ($_POST['nonce'] ?? ''));
        if (!wp_verify_nonce($nonce, 'abcnorio_delete_backup')) {
            wp_die(__('Invalid request.', 'abcnorio-func'), 403);
        }

        if (!current_user_can('manage_options')) {
            wp_die(__('Insufficient permissions.', 'abcnorio-func'), 403);
        }

        $env = sanitize_key((string) ($_POST['env'] ?? ''));
        if (!Deployment::isValidEnv($env)) {
            wp_die(__('Invalid backup environment.', 'abcnorio-func'), 400);
        }

        $requested = sanitize_file_name((string) ($_POST['file'] ?? ''));
        // Validate the file exists in the backup list before asking the orchestrator.
        self::resolveBackupFile($requested, $env);

        $response = wp_remote_post(Deployment::orchestratorBaseUrl() . '/delete-backup', [
            'headers' => [
                'Content-Type'  => 'application/json',
                'Authorization' => 'Bearer ' . Deployment::orchestratorSecret(),
            ],
            'body'    => wp_json_encode(['target' => $env, 'file' => $requested]),
            'timeout' => 15,
        ]);

        if (is_wp_error($response)) {
            wp_die(
                sprintf(
                    /* translators: %s: Network error details. */
                    __('Delete request failed: %s', 'abcnorio-func'),
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
                        /* translators: %s: Delete failure message from orchestrator. */
                        __('Delete failed: %s', 'abcnorio-func'),
                        $message
                    )
                    : __('Delete failed.', 'abcnorio-func'),
                502
            );
        }

        $redirect = add_query_arg(
            [
                'page' => 'abcnorio-deployment',
                'tab'  => $env,
            ],
            admin_url('admin.php')
        );
        wp_safe_redirect($redirect);
        exit;
    }

    private static function devToolPost(string $nonce, string $endpoint, string $verb): void
    {
        check_ajax_referer($nonce, 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Insufficient permissions'], 403);
        }

        $response = wp_remote_post(Deployment::orchestratorBaseUrl() . $endpoint, [
            'headers' => ['Authorization' => 'Bearer ' . Deployment::orchestratorSecret()],
            'timeout' => 10,
        ]);

        if (is_wp_error($response)) {
            wp_send_json_error(['message' => $response->get_error_message()], 502);
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if ($code === 409) {
            wp_send_json_error(['message' => ucfirst($verb) . ' already in progress'], 409);
        }

        if ($code !== 202) {
            $msg = is_array($body) ? (string) ($body['error'] ?? $body['message'] ?? '') : '';
            $errorMessage = $msg !== '' ? ucfirst($verb) . ' failed: ' . $msg : ucfirst($verb) . ' failed (HTTP ' . $code . ')';
            wp_send_json_error(['message' => $errorMessage], $code);
        }

        wp_send_json_success($body);
    }

    private static function devToolPoll(string $nonce, string $statusKey): void
    {
        check_ajax_referer($nonce, 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Insufficient permissions'], 403);
        }

        $response = wp_remote_get(Deployment::orchestratorBaseUrl() . '/dev-tools/status', [
            'headers' => ['Authorization' => 'Bearer ' . Deployment::orchestratorSecret()],
            'timeout' => 5,
        ]);

        if (is_wp_error($response)) {
            wp_send_json_error(['message' => $response->get_error_message()], 502);
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        wp_send_json_success(is_array($body) ? ($body[$statusKey] ?? []) : []);
    }

    public static function pushToStaging(): void
    {
        self::devToolPost('abcnorio_push_to_staging', '/dev-tools/push-to-staging', 'push');
    }

    public static function pollPushStatus(): void
    {
        self::devToolPoll('abcnorio_poll_push_status', 'push');
    }

    public static function copyMediaToDev(): void
    {
        self::devToolPost('abcnorio_copy_media_to_dev', '/dev-tools/copy-media-to-dev', 'copy');
    }

    public static function pollCopyMediaStatus(): void
    {
        self::devToolPoll('abcnorio_poll_copy_media_status', 'copyMedia');
    }

    public static function pullFromStaging(): void
    {
        self::devToolPost('abcnorio_pull_from_staging', '/dev-tools/pull-from-staging', 'push');
    }

    public static function pollPullFromStagingStatus(): void
    {
        self::devToolPoll('abcnorio_poll_pull_from_staging_status', 'pullFromStaging');
    }

    public static function copyMediaToStaging(): void
    {
        self::devToolPost('abcnorio_copy_media_to_staging', '/dev-tools/copy-media-to-staging', 'copy');
    }

    public static function pollCopyMediaToStagingStatus(): void
    {
        self::devToolPoll('abcnorio_poll_copy_media_to_staging_status', 'copyMediaToStaging');
    }

    public static function pullFromDev(): void
    {
        self::devToolPost('abcnorio_pull_from_dev', '/dev-tools/pull-from-dev', 'push');
    }

    public static function pollPullFromDevStatus(): void
    {
        self::devToolPoll('abcnorio_poll_pull_from_dev_status', 'pullFromDev');
    }
}
