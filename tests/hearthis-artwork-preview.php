<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/hearthis-artwork-preview.php';

$tmp = tempnam(sys_get_temp_dir(), 'deseo_artwork_');
if ($tmp === false) {
    fwrite(STDERR, "FAIL: no temporary fixture path\n");
    exit(1);
}
try {
    // Valid, tiny 1x1 PNG. No dependency on GD or production DJ assets.
    $bytes = base64_decode(
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Y6G7oEAAAAASUVORK5CYII=',
        true
    );
    if (!is_string($bytes) || file_put_contents($tmp, $bytes) === false) {
        throw new RuntimeException('Unable to create PNG fixture');
    }
    $source = 'iluma/uploads/deseo_djs/3. Fri/3. ANDOR/ANDOR02.png';
    $url = '/' . implode('/', array_map('rawurlencode', explode('/', $source)));
    $cover = [
        'path' => $tmp, 'mime' => 'image/png', 'name' => 'ANDOR02.png',
        'asset_id' => null, 'source_path' => $source, 'preview_url' => $url,
    ];
    $preview = deseo_hearthis_artwork_preview(['hearthis_description' => null], $cover);
    if (!is_array($preview) || $preview['url'] !== $url
        || $preview['name'] !== 'ANDOR02.png'
        || $preview['width'] !== 1 || $preview['height'] !== 1) {
        throw new RuntimeException('Eligible current File Manager 02 must preview');
    }
    $saved = [
        'hearthis_description' => 'Saved actual upload description',
        'hearthis_cover_asset_id' => null,
        'hearthis_cover_source_path' => $source,
    ];
    if (deseo_hearthis_artwork_preview($saved, $cover)['url'] !== $url) {
        throw new RuntimeException('Matching saved File Manager source must preview');
    }
    $asset = [
        'path' => $tmp, 'mime' => 'image/png', 'name' => 'ANDOR02.png',
        'asset_id' => 17, 'source_path' => '',
        'preview_url' => '/iluma/mylive-download.php?type=asset&id=17&preview=1',
    ];
    if (deseo_hearthis_artwork_preview(['hearthis_description' => null], $asset)['url']
        !== '/iluma/mylive-download.php?type=asset&id=17&preview=1') {
        throw new RuntimeException('Authenticated private MyLive cover must preview');
    }
    $savedAsset = [
        'hearthis_description' => 'Saved actual upload description',
        'hearthis_cover_asset_id' => 17,
        'hearthis_cover_source_path' => null,
    ];
    if (deseo_hearthis_artwork_preview($savedAsset, $asset) === null) {
        throw new RuntimeException('Matching saved MyLive asset must preview');
    }
    $bad = [
        ['no cover', [], null],
        ['changed saved File Manager cover', array_replace($saved, ['hearthis_cover_source_path' => 'iluma/uploads/deseo_djs/3. Fri/other02.png']), $cover],
        ['saved without custom image', array_replace($saved, ['hearthis_cover_source_path' => null]), $cover],
        ['wrong saved private asset', array_replace($savedAsset, ['hearthis_cover_asset_id' => 18]), $asset],
        ['private asset preview ID changed', [], array_replace($asset, ['preview_url' => '/iluma/mylive-download.php?type=asset&id=18&preview=1'])],
        ['external image URL', [], array_replace($cover, ['preview_url' => 'https://bad.example/ANDOR02.png'])],
        ['spoofed File Manager URL', [], array_replace($cover, ['preview_url' => '/iluma/uploads/deseo_djs/other.png'])],
        ['WebP cannot be custom Premium multipart image', [], array_replace($cover, ['mime' => 'image/webp'])],
        ['missing local file', [], array_replace($cover, ['path' => $tmp . '.missing'])],
    ];
    foreach ($bad as [$name, $set, $candidate]) {
        if (deseo_hearthis_artwork_preview($set, $candidate) !== null) {
            throw new RuntimeException('Unsafe artwork shown: ' . $name);
        }
    }
    echo 'PASS: eligible 02 modal previews, two saved-cover snapshots, '
        . count($bad) . " fail-closed artwork cases.\n";
} catch (Throwable $error) {
    fwrite(STDERR, 'FAIL: ' . $error->getMessage() . "\n");
    exit(1);
} finally {
    @unlink($tmp);
}
