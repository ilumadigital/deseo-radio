<?php
declare(strict_types=1);

/**
 * Resolve an exact, already validated local HearThis 02 cover to a safe CMS
 * preview. Mirrors the real Premium upload constraint (square PNG/JPEG <=10MB).
 * Saved upload choices must still match the current resolved image: never show
 * a different, subsequently replaced cover as though it was uploaded.
 *
 * $cover comes ONLY from deseo_hearthis_cover() for the current account.
 * The returned relative image src cannot be an arbitrary remote URL.
 */
function deseo_hearthis_artwork_preview(array $set, ?array $cover): ?array {
    if ($cover === null) return null;

    $path = (string)($cover['path'] ?? '');
    $mime = (string)($cover['mime'] ?? '');
    $name = basename((string)($cover['name'] ?? ''));
    $assetId = (int)($cover['asset_id'] ?? 0);
    $source = (string)($cover['source_path'] ?? '');
    $url = (string)($cover['preview_url'] ?? '');

    if ($path === '' || !is_file($path) || is_link($path) || $name === ''
        || !in_array($mime, ['image/png', 'image/jpeg'], true)) return null;
    $bytes = filesize($path);
    $image = @getimagesize($path);
    if ($bytes === false || $bytes < 1 || $bytes > 10 * 1024 * 1024
        || !is_array($image) || (int)($image[0] ?? 0) < 1
        || (int)$image[0] !== (int)($image[1] ?? 0)
        || (string)($image['mime'] ?? '') !== $mime) return null;

    if ($assetId > 0) {
        // This endpoint verifies CMS login and serves the specific private asset.
        $expected = '/iluma/mylive-download.php?type=asset&id=' . $assetId . '&preview=1';
    } else {
        // File Manager source was already uniquely resolved by the cover picker.
        if (!str_starts_with($source, 'iluma/uploads/deseo_djs/')
            || str_contains($source, '../') || str_contains($source, '/..')
            || str_contains($source, '?') || str_contains($source, '#')) return null;
        $expected = '/' . implode('/', array_map('rawurlencode', explode('/', $source)));
    }
    if ($url !== $expected) return null;

    // Once the uploader saved an exact payload, its cover is a historical
    // snapshot, not the live account's possibly later changed selection.
    if (trim((string)($set['hearthis_description'] ?? '')) !== '') {
        $savedId = (int)($set['hearthis_cover_asset_id'] ?? 0);
        $savedSource = (string)($set['hearthis_cover_source_path'] ?? '');
        if ($savedId > 0) {
            if ($assetId !== $savedId) return null;
        } elseif ($savedSource !== '') {
            if ($source !== $savedSource) return null;
        } else {
            return null; // The upload had no custom image.
        }
    }

    return [
        'url' => $expected,
        'name' => $name,
        'width' => (int)$image[0],
        'height' => (int)$image[1],
    ];
}
