<?php
declare(strict_types=1);

require_once __DIR__ . '/env.php';

function deseo_upload_allowed_scopes(): array {
    return ['program', 'djs'];
}

function deseo_upload_root(): string {
    static $root = null;
    if (is_string($root)) return $root;

    $custom = getenv('DESEO_UPLOAD_DIR');
    if ($custom !== false && trim($custom) !== '') {
        $root = rtrim(trim($custom), '/\\');
    } else {
        // Hostinger-safe default:
        // domains/example.com/deseo-uploads (outside public_html / Git checkout)
        $projectRoot = dirname(__DIR__);
        $domainRoot = dirname($projectRoot);
        $root = $domainRoot . '/deseo-uploads';
    }

    return $root;
}

function deseo_upload_scope_dir(string $scope): string {
    if (!in_array($scope, deseo_upload_allowed_scopes(), true)) {
        throw new InvalidArgumentException('Invalid upload scope.');
    }

    return deseo_upload_root() . DIRECTORY_SEPARATOR . $scope;
}

function deseo_upload_ensure_scope(string $scope): string {
    $dir = deseo_upload_scope_dir($scope);

    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException('Persistent upload directory is not writable.');
    }

    return $dir;
}

function deseo_upload_safe_filename(string $filename): ?string {
    $filename = trim($filename);
    if ($filename === '' || basename($filename) !== $filename) return null;
    if (!preg_match('/^[A-Za-z0-9._-]+$/', $filename)) return null;
    return $filename;
}

function deseo_upload_public_url(string $scope, string $filename): string {
    $safe = deseo_upload_safe_filename($filename);
    if ($safe === null || !in_array($scope, deseo_upload_allowed_scopes(), true)) {
        throw new InvalidArgumentException('Invalid upload reference.');
    }

    return '/media.php?scope=' . rawurlencode($scope) . '&file=' . rawurlencode($safe);
}

function deseo_upload_persistent_path(string $scope, string $filename, bool $createDir = false): ?string {
    $safe = deseo_upload_safe_filename($filename);
    if ($safe === null || !in_array($scope, deseo_upload_allowed_scopes(), true)) return null;

    $dir = $createDir ? deseo_upload_ensure_scope($scope) : deseo_upload_scope_dir($scope);
    return $dir . DIRECTORY_SEPARATOR . $safe;
}

function deseo_upload_legacy_reference(string $storedPath): ?array {
    if (str_starts_with($storedPath, '/iluma/uploads/djs/')) {
        $filename = deseo_upload_safe_filename(basename($storedPath));
        return $filename ? ['scope' => 'djs', 'file' => $filename] : null;
    }

    if (str_starts_with($storedPath, '/iluma/uploads/')) {
        $filename = deseo_upload_safe_filename(basename($storedPath));
        return $filename ? ['scope' => 'program', 'file' => $filename] : null;
    }

    return null;
}

function deseo_upload_media_reference(string $storedPath): ?array {
    $parts = parse_url($storedPath);
    if (!is_array($parts) || ($parts['path'] ?? '') !== '/media.php') return null;

    $query = [];
    parse_str((string)($parts['query'] ?? ''), $query);

    $scope = (string)($query['scope'] ?? '');
    $filename = deseo_upload_safe_filename((string)($query['file'] ?? ''));

    if ($filename === null || !in_array($scope, deseo_upload_allowed_scopes(), true)) return null;

    return ['scope' => $scope, 'file' => $filename];
}

function deseo_upload_reference(string $storedPath): ?array {
    return deseo_upload_media_reference($storedPath)
        ?? deseo_upload_legacy_reference($storedPath);
}

function deseo_upload_legacy_absolute(string $storedPath): ?string {
    $reference = deseo_upload_legacy_reference($storedPath);
    if ($reference === null) return null;

    $projectRoot = dirname(__DIR__);
    $absolute = $projectRoot . $storedPath;

    return is_file($absolute) ? $absolute : null;
}

function deseo_upload_migrate_legacy(string $storedPath): ?string {
    $reference = deseo_upload_legacy_reference($storedPath);
    if ($reference === null) return null;

    $persistent = deseo_upload_persistent_path($reference['scope'], $reference['file'], true);
    if ($persistent === null) return null;

    if (is_file($persistent)) return $persistent;

    $legacy = deseo_upload_legacy_absolute($storedPath);
    if ($legacy !== null && @copy($legacy, $persistent)) {
        @chmod($persistent, 0664);
        return $persistent;
    }

    return null;
}

function deseo_upload_url_from_stored(?string $storedPath): string {
    $storedPath = trim((string)$storedPath);
    if ($storedPath === '') return '';

    $media = deseo_upload_media_reference($storedPath);
    if ($media !== null) {
        return deseo_upload_public_url($media['scope'], $media['file']);
    }

    $legacy = deseo_upload_legacy_reference($storedPath);
    if ($legacy !== null) {
        $persistent = deseo_upload_persistent_path($legacy['scope'], $legacy['file']);
        if (($persistent !== null && is_file($persistent)) || deseo_upload_migrate_legacy($storedPath) !== null) {
            return deseo_upload_public_url($legacy['scope'], $legacy['file']);
        }
    }

    return $storedPath;
}

function deseo_upload_absolute_from_stored(?string $storedPath): ?string {
    $storedPath = trim((string)$storedPath);
    if ($storedPath === '') return null;

    $reference = deseo_upload_reference($storedPath);
    if ($reference === null) return null;

    $persistent = deseo_upload_persistent_path($reference['scope'], $reference['file']);
    if ($persistent !== null && is_file($persistent)) return $persistent;

    return deseo_upload_migrate_legacy($storedPath)
        ?? deseo_upload_legacy_absolute($storedPath);
}

function deseo_upload_delete_stored(?string $storedPath): void {
    $storedPath = trim((string)$storedPath);
    if ($storedPath === '') return;

    $reference = deseo_upload_reference($storedPath);
    if ($reference !== null) {
        $persistent = deseo_upload_persistent_path($reference['scope'], $reference['file']);
        if ($persistent !== null && is_file($persistent)) @unlink($persistent);
    }

    $legacy = deseo_upload_legacy_absolute($storedPath);
    if ($legacy !== null && is_file($legacy)) @unlink($legacy);
}
