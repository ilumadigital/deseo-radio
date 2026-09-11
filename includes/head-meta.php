<?php
require_once __DIR__ . '/i18n.php';

$meta_title = $meta_title ?? deseo_t('meta.title');
$meta_desc = $meta_desc ?? deseo_t('meta.description');
$meta_keywords = $meta_keywords ?? deseo_t('meta.keywords');

$assetFiles = [
    __DIR__ . '/../assets/css/style.css',
    __DIR__ . '/../sw.js',
    __DIR__ . '/../manifest.json',
];
$assetVersion = 1;
foreach ($assetFiles as $assetFile) {
    if (is_file($assetFile)) {
        $assetVersion = max($assetVersion, (int) filemtime($assetFile));
    }
}

if (!headers_sent()) {
    header('Cache-Control: no-cache, must-revalidate');
    header('Pragma: no-cache');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: strict-origin-when-cross-origin');
}
?>
<!doctype html>
<html lang="<?= deseo_e(deseo_lang()) ?>" class="no-js">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
    <meta name="theme-color" content="#090909">
    <meta name="color-scheme" content="dark">
    <meta name="description" content="<?= deseo_e($meta_desc) ?>">
    <meta name="keywords" content="<?= deseo_e($meta_keywords) ?>">
    <meta name="robots" content="index,follow,max-image-preview:large">
    <meta name="application-name" content="Deseo Radio">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="Deseo Radio">

    <title><?= deseo_e($meta_title) ?></title>

    <meta property="og:type" content="website">
    <meta property="og:site_name" content="Deseo Radio">
    <meta property="og:title" content="<?= deseo_e($meta_title) ?>">
    <meta property="og:description" content="<?= deseo_e($meta_desc) ?>">
    <meta property="og:url" content="<?= deseo_e(deseo_canonical_url()) ?>">
    <meta property="og:image" content="https://deseoradio.com/assets/img/bg.png">
    <meta name="twitter:card" content="summary_large_image">

    <link rel="canonical" href="<?= deseo_e(deseo_canonical_url()) ?>">
    <link rel="alternate" hreflang="el" href="https://deseoradio.com/">
    <link rel="alternate" hreflang="en" href="https://deseoradio.com/?lang=en">
    <link rel="alternate" hreflang="x-default" href="https://deseoradio.com/">

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css?family=Google+Sans:400,500,700&display=swap">

    <link rel="icon" type="image/png" href="/assets/img/favicon.png?v=<?= $assetVersion ?>">
    <link rel="apple-touch-icon" href="/assets/img/favicon.png?v=<?= $assetVersion ?>">
    <link rel="manifest" href="/manifest.json?v=<?= $assetVersion ?>">

    <link rel="preload" href="/assets/img/bg.png" as="image">
    <link rel="preload" href="/assets/css/style.css?v=<?= $assetVersion ?>" as="style">
    <link rel="stylesheet" href="/assets/css/style.css?v=<?= $assetVersion ?>">

    <script>document.documentElement.className=document.documentElement.className.replace('no-js','js');</script>

    <script type="application/ld+json">
    {
      "@context":"https://schema.org",
      "@type":"RadioStation",
      "name":"Deseo Radio",
      "url":"https://deseoradio.com/",
      "logo":"https://deseoradio.com/assets/img/deseoradio-logo.png",
      "image":"https://deseoradio.com/assets/img/bg.png",
      "description":<?= json_encode(deseo_t('schema.description'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
      "email":"radio@iluma.gr",
      "telephone":"+302103000825",
      "address":{
        "@type":"PostalAddress",
        "streetAddress":"1st Moschonision st.",
        "addressLocality":"Egaleo",
        "postalCode":"12242",
        "addressCountry":"GR"
      },
      "sameAs":[
        "https://www.instagram.com/deseoradio/",
        "https://www.facebook.com/deseoradiogr/"
      ]
    }
    </script>
</head>
<body>
<a class="skip-link" href="#main-content" data-i18n="skip.content"><?= deseo_e(deseo_t('skip.content')) ?></a>