<header class="site-header deseo-header" id="top">
    <div class="shell header-inner deseo-header-inner">
        <a class="brand deseo-brand" href="/" aria-label="<?= deseo_e(deseo_t('header.home')) ?>" data-i18n-aria="header.home">
            <img src="/assets/img/deseoradio-logo.png" alt="Deseo Radio" width="220" height="62">
        </a>

        <div class="header-actions deseo-header-actions">
            <div class="language-switcher" aria-label="Language">
                <a href="<?= deseo_e(deseo_lang_url('el')) ?>"
                   class="language-option <?= deseo_lang() === 'el' ? 'active' : '' ?>"
                   data-lang-switch="el"
                   aria-label="<?= deseo_e(deseo_t('lang.switch_to_el')) ?>">EL</a>
                <span aria-hidden="true">/</span>
                <a href="<?= deseo_e(deseo_lang_url('en')) ?>"
                   class="language-option <?= deseo_lang() === 'en' ? 'active' : '' ?>"
                   data-lang-switch="en"
                   aria-label="<?= deseo_e(deseo_t('lang.switch_to_en')) ?>">EN</a>
            </div>

            <a class="deseo-live-pill" href="#main-content" aria-label="<?= deseo_e(deseo_t('header.live_aria')) ?>" data-i18n-aria="header.live_aria">
                <span class="deseo-live-dot"></span>
                <span data-i18n="header.live"><?= deseo_e(deseo_t('header.live')) ?></span>
            </a>

            <a class="icon-link deseo-social" href="https://www.instagram.com/deseoradio/" target="_blank" rel="noopener noreferrer"
               aria-label="<?= deseo_e(deseo_t('header.instagram')) ?>" data-i18n-aria="header.instagram">
                <svg viewBox="0 0 24 24" aria-hidden="true">
                    <path d="M7 2h10a5 5 0 0 1 5 5v10a5 5 0 0 1-5 5H7a5 5 0 0 1-5-5V7a5 5 0 0 1 5-5Zm0 2a3 3 0 0 0-3 3v10a3 3 0 0 0 3 3h10a3 3 0 0 0 3-3V7a3 3 0 0 0-3-3H7Zm5 3.5A4.5 4.5 0 1 1 7.5 12 4.5 4.5 0 0 1 12 7.5Zm0 2A2.5 2.5 0 1 0 14.5 12 2.5 2.5 0 0 0 12 9.5ZM17.75 6a1.25 1.25 0 1 1-1.25 1.25A1.25 1.25 0 0 1 17.75 6Z"/>
                </svg>
            </a>

            <a class="icon-link deseo-social" href="https://www.facebook.com/deseoradiogr/" target="_blank" rel="noopener noreferrer"
               aria-label="<?= deseo_e(deseo_t('header.facebook')) ?>" data-i18n-aria="header.facebook">
                <svg viewBox="0 0 24 24" aria-hidden="true">
                    <path d="M13.5 22v-9h3l.5-3h-3.5V8.2c0-.9.3-1.7 1.8-1.7H17V3.8c-.3 0-1.5-.1-2.8-.1-2.8 0-4.7 1.7-4.7 4.8V10H7v3h2.5v9h4Z"/>
                </svg>
            </a>
        </div>
    </div>
</header>