<?php /* Shared redesign header for / and /mydemo */ ?>
<header class="md-header">
  <div class="md-shell md-header-inner">
    <a class="md-logo" href="#home" aria-label="Deseo Radio demo home"><img src="/assets/img/deseoradio-logo.png" alt="Deseo Radio" width="220" height="100" fetchpriority="high"></a>
    <div class="md-header-actions">
      <div class="md-lang" aria-label="Language">
        <a <?= !$en ? 'aria-current="page"' : '' ?> href="<?= $isProductionHome ? '/?lang=el' : '/mydemo?lang=el' ?>">EL</a>
        <span>/</span>
        <a <?= $en ? 'aria-current="page"' : '' ?> href="<?= $isProductionHome ? '/?lang=en' : '/mydemo?lang=en' ?>">EN</a>
      </div>
      <button type="button" class="md-menu-trigger" id="md-menu-trigger" aria-controls="md-fs-menu" aria-expanded="false" aria-haspopup="dialog" aria-label="Open menu"><span class="md-menu-trigger-label">MENU</span><span class="md-menu-bars" aria-hidden="true"><i></i><i></i></span></button>
    </div>
  </div>
</header>
