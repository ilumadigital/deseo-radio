<?php
declare(strict_types=1);

function admin_page_start(string $title, string $active = 'dashboard'): void {
    $nav = [
        'dashboard' => ['index.php', 'Overview'],
        'airplay' => ['airplay.php', 'Airplay'],
        'program' => ['program.php', 'Radio Program'],
        'dj-season' => ['dj-season.php', 'DJ Applications'],
        'mylive' => ['mylive.php', 'MyLive'],
        'communications' => ['communications.php', 'Communications'],
        'rewards' => ['rewards.php', 'Rewards'],
        'audience' => ['audience.php', 'Audience'],
        'playlists' => ['playlists.php', 'Playlists'],
    ];

    if (!admin_can_access('audience')) {
        unset($nav['audience']);
    }
    if (!admin_can_access('rewards')) {
        unset($nav['rewards']);
    }

    $currentCmsEmail = admin_current_email();
    $currentCmsRole = admin_current_role() === 'administrator' ? 'Administrator' : 'Manager';
    ?>
<!doctype html>
<html lang="el">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
    <meta name="theme-color" content="#090909">
    <meta name="color-scheme" content="dark">
    <meta name="robots" content="noindex,nofollow,noarchive,nosnippet,noimageindex,notranslate">
    <meta name="googlebot" content="noindex,nofollow,noarchive,nosnippet,noimageindex">
    <meta name="bingbot" content="noindex,nofollow,noarchive,nosnippet,noimageindex">
    <title><?= admin_e($title) ?> · Deseo CMS</title>
    <link rel="stylesheet" href="/iluma/admin.css?v=<?= @filemtime(__DIR__ . '/admin.css') ?: 1 ?>">
</head>
<body class="admin-body">
<div class="admin-app">
    <aside class="admin-sidebar">
        <a class="admin-logo" href="index.php"><img src="/assets/img/deseoradio-logo.png" alt="Deseo Radio"></a>
        <div class="admin-kicker">ILUMA CMS</div>
        <nav class="admin-nav" aria-label="CMS navigation">
            <?php foreach ($nav as $key => $item): ?>
                <a class="<?= $active === $key ? 'active' : '' ?>" href="<?= admin_e($item[0]) ?>">
                    <span class="nav-dot"></span><?= admin_e($item[1]) ?>
                </a>
            <?php endforeach; ?>
        </nav>
        <div class="admin-sidebar-bottom">
            <div class="admin-account">
                <span><?= admin_e($currentCmsRole) ?></span>
                <strong><?= admin_e($currentCmsEmail) ?></strong>
            </div>
            <a href="/" target="_blank" rel="noopener">View website ↗</a>
            <form method="post" action="index.php">
                <input type="hidden" name="csrf_token" value="<?= admin_e(admin_csrf_token()) ?>">
                <input type="hidden" name="action" value="logout">
                <button type="submit">Sign out</button>
            </form>
        </div>
    </aside>
    <main class="admin-main">
        <header class="admin-mobile-header">
            <img src="/assets/img/deseoradio-logo.png" alt="Deseo Radio">
            <div class="admin-mobile-nav">
                <?php foreach ($nav as $key => $item): ?>
                    <a class="<?= $active === $key ? 'active' : '' ?>" href="<?= admin_e($item[0]) ?>"><?= admin_e($item[1]) ?></a>
                <?php endforeach; ?>
            </div>
        </header>
        <div class="admin-content">
    <?php
}

function admin_page_end(): void {
    ?>
        </div>
    </main>
</div>
<script>
(function(){
  var messages=document.querySelectorAll('.notice');
  if(!messages.length)return;
  window.setTimeout(function(){
    for(var i=0;i<messages.length;i++)messages[i].className+=' notice-soft';
  },4500);
}());
</script>
<script src="/assets/js/deseo-lockdown.js?v=<?= @filemtime(dirname(__DIR__) . '/assets/js/deseo-lockdown.js') ?: 1 ?>"></script>
<script src="/assets/js/deseo-dialogs.js?v=<?= @filemtime(dirname(__DIR__) . '/assets/js/deseo-dialogs.js') ?: 1 ?>"></script>
</body>
</html>
    <?php
}
