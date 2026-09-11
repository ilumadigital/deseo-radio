<?php
$meta_title = $meta_title ?? 'Deseo Radio | House Music, Live DJs & Non-Stop Vibes';
$meta_desc = $meta_desc ?? 'Άκου live το Deseo Radio: House, Afro House, Deep House και electronic music, 24/7. Live DJs, πρόγραμμα και weekly airplay.';
$meta_keywords = $meta_keywords ?? 'Deseo Radio, house music, afro house, deep house, live radio, Greece';

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
<html lang="el" class="no-js">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
    <meta name="theme-color" content="#090909">
    <meta name="color-scheme" content="dark">
    <meta name="description" content="<?= htmlspecialchars($meta_desc, ENT_QUOTES, 'UTF-8') ?>">
    <meta name="keywords" content="<?= htmlspecialchars($meta_keywords, ENT_QUOTES, 'UTF-8') ?>">
    <meta name="robots" content="index,follow,max-image-preview:large">
    <meta name="application-name" content="Deseo Radio">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="Deseo Radio">

    <title><?= htmlspecialchars($meta_title, ENT_QUOTES, 'UTF-8') ?></title>

    <meta property="og:type" content="website">
    <meta property="og:site_name" content="Deseo Radio">
    <meta property="og:title" content="<?= htmlspecialchars($meta_title, ENT_QUOTES, 'UTF-8') ?>">
    <meta property="og:description" content="<?= htmlspecialchars($meta_desc, ENT_QUOTES, 'UTF-8') ?>">
    <meta property="og:url" content="https://deseoradio.com/">
    <meta property="og:image" content="https://deseoradio.com/assets/img/bg.png">
    <meta name="twitter:card" content="summary_large_image">

    <link rel="canonical" href="https://deseoradio.com/">
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
      "description":"House, Afro House and electronic music radio station broadcasting 24/7.",
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
<a class="skip-link" href="#main-content">Μετάβαση στο περιεχόμενο</a>