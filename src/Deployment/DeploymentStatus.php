<?php

namespace abcnorio\CustomFunc\Deployment;

final class DeploymentStatus
{
    public static function backupArchiveDir(): string
    {
        $configured = trim((string) (getenv('ASTRO_BUILD_ARCHIVE_DIR') ?: ''));
        if ($configured !== '') {
            return rtrim($configured, '/');
        }

        $workspaceRoot = dirname(ABCNORIO_CUSTOM_FUNC_FILE, 4);
        return rtrim($workspaceRoot, '/') . '/astro/site/build-archives';
    }

    public static function deploymentStatusPath(): string
    {
        $configured = trim((string) (getenv('ASTRO_DEPLOYMENT_STATUS_FILE') ?: ''));
        return $configured !== '' ? $configured : self::backupArchiveDir() . '/deployment-status.json';
    }

    public static function buildRootDir(string $env): string
    {
        $envKeyMap = [
            'dev'        => 'DEV_BUILD_PATH',
            'staging'    => 'STAGING_BUILD_PATH',
            'production' => 'PRODUCTION_BUILD_PATH',
            'preview'    => 'PREVIEW_BUILD_PATH',
        ];

        $envKey = $envKeyMap[$env] ?? null;
        if ($envKey !== null) {
            $configured = trim((string) (getenv($envKey) ?: ''));
            if ($configured !== '') {
                return rtrim($configured, '/');
            }
        }

        $staticRoot = rtrim((string) (getenv('STATIC_SERVER_SITE_DIR') ?: ''), '/');
        if ($staticRoot === '') {
            return '';
        }

        $dirNameMap = ['dev' => 'dev', 'staging' => 'staging', 'production' => 'prod', 'preview' => 'preview'];
        $dirName = $dirNameMap[$env] ?? null;
        return $dirName !== null ? $staticRoot . '/' . $dirName : '';
    }

    /**
     * @return array{ok: bool, message: string, status: array<string, mixed>}
     */
    public static function read(): array
    {
        $path = self::deploymentStatusPath();

        if (!is_file($path)) {
            return [
                'ok'      => false,
                'message' => sprintf(__('Deployment status file not found: %s', 'abcnorio-func'), $path),
                'status'  => [],
            ];
        }

        if (!is_readable($path)) {
            return [
                'ok'      => false,
                'message' => sprintf(__('Deployment status file is not readable: %s', 'abcnorio-func'), $path),
                'status'  => [],
            ];
        }

        $raw = file_get_contents($path);
        if (!is_string($raw) || trim($raw) === '') {
            return [
                'ok'      => false,
                'message' => __('Deployment status file is empty.', 'abcnorio-func'),
                'status'  => [],
            ];
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded) || !isset($decoded['envs']) || !is_array($decoded['envs'])) {
            return [
                'ok'      => false,
                'message' => __('Deployment status file is invalid or missing envs.', 'abcnorio-func'),
                'status'  => [],
            ];
        }

        return ['ok' => true, 'message' => '', 'status' => $decoded];
    }

    /**
     * @return array{ok: bool, message: string, names: array<int, string>}
     */
    public static function backupNamesForEnv(string $env): array
    {
        $status = self::read();
        if (!$status['ok']) {
            return ['ok' => false, 'message' => $status['message'], 'names' => []];
        }

        $envStatus = $status['status']['envs'][$env] ?? null;
        if (!is_array($envStatus) || !isset($envStatus['backups']) || !is_array($envStatus['backups'])) {
            return [
                'ok'      => false,
                'message' => __('Backup metadata is missing for selected environment.', 'abcnorio-func'),
                'names'   => [],
            ];
        }

        $names = [];
        foreach ($envStatus['backups'] as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $name = trim((string) ($entry['name'] ?? ''));
            if ($name !== '') {
                $names[] = $name;
            }
        }

        return ['ok' => true, 'message' => '', 'names' => array_values(array_unique($names))];
    }

    /**
     * @param mixed $value
     */
    private static function normalizeBackupMtime($value): int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && is_numeric($value)) {
            return (int) $value;
        }

        return 0;
    }

    /**
     * @param mixed $envStatus
     * @return array<int, array{name: string, mtime: int}>
     */
    public static function normalizeBackups($envStatus): array
    {
        if (!is_array($envStatus) || !isset($envStatus['backups']) || !is_array($envStatus['backups'])) {
            return [];
        }

        $out = [];
        foreach ($envStatus['backups'] as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $name = trim((string) ($entry['name'] ?? ''));
            if ($name === '') {
                continue;
            }

            $mtime = self::normalizeBackupMtime($entry['mtime'] ?? null);
            if ($mtime === 0 && isset($entry['createdAt']) && is_string($entry['createdAt'])) {
                $parsed = strtotime($entry['createdAt']);
                $mtime = is_int($parsed) ? $parsed : 0;
            }

            $out[] = ['name' => $name, 'mtime' => $mtime];
        }

        usort($out, static fn(array $a, array $b): int => $b['mtime'] <=> $a['mtime']);
        return array_slice($out, 0, 3);
    }
}
