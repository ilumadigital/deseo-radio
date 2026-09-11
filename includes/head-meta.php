<?php
// Προκαθορισμένα SEO Data αν δεν περαστούν από τη σελίδα
$meta_title = $meta_title ?? "Deseo Radio | Το Soundtrack της ζωής σου! | House Music";
$meta_desc = $meta_desc ?? "Άκου live το δεσεο radio. Το κορυφαίο ραδιόφωνο για House music, Afro House και Organic Tech. Ζωντανά από το Αιγάλεω σε όλο τον κόσμο.";
$meta_keywords = $meta_keywords ?? "ραδιόφωνο, δεσεο, deseo, radio, house music";
?>

<!DOCTYPE html>
<html lang="el">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $meta_title; ?></title>
    <meta name="description" content="<?php echo $meta_desc; ?>">
    <meta name="keywords" content="<?php echo $meta_keywords; ?>">
    
    <link rel="icon" type="image/png" href="/assets/img/favicon.png">
    <link rel="apple-touch-icon" href="/assets/img/favicon.png">
    
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;700&display=swap" rel="stylesheet">
    
    <link rel="manifest" href="/manifest.json">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="Deseo Radio">

    <link rel="stylesheet" href="/assets/css/style.css">
    
    <script src="https://cdnjs.cloudflare.com/ajax/libs/gsap/3.12.2/gsap.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/gsap/3.12.2/ScrollTrigger.min.js"></script>
    
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

    <script type="application/ld+json">
    [
        {
            "@context": "https://schema.org",
            "@type": "RadioStation",
            "name": "Deseo Radio",
            "url": "https://deseoradio.com",
            "image": "https://deseoradio.com/assets/img/favicon.png",
            "description": "Το κορυφαίο ραδιόφωνο για house music, deep house και organic tech."
        },
        {
            "@context": "https://schema.org",
            "@type": "LocalBusiness",
            "name": "Deseo Radio Broadcast Studio",
            "image": "https://deseoradio.com/assets/img/favicon.png",
            "telephone": "2103000825",
            "email": "radio@iluma.gr",
            "address": {
                "@type": "PostalAddress",
                "streetAddress": "1st Moschonision st.",
                "addressLocality": "Egaleo",
                "postalCode": "12242",
                "addressCountry": "GR"
            }
        }
    ]
    </script>
    
    <script>
    if ('serviceWorker' in navigator) {
        window.addEventListener('load', () => {
            navigator.serviceWorker.register('/sw.js');
        });
    }
    </script>
</head>
<body class="bg-black text-white font-sans overflow-x-hidden antialiased selection:bg-[#ccff00] selection:text-black">