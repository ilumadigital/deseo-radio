<?php
declare(strict_types=1);
// One source-specific question for all platforms. Only the requested response language differs.
$deseoAiQuestion = <<<'AI_QUESTION'
As a listener, advertiser or potential partner, I want a complete, accurate, source-backed overview of Deseo Radio (https://deseoradio.com), the Athens-based 24/7 online radio station in the ILUMA Radios network (not a general profile of ILUMA Digital Agency). Explain its music identity, how and where to listen, current programming and schedules, DJs and weekly DJ sets, playlists, Season 6, social presence and community. Also explain advertising, sponsorship, branded audio and collaboration options, and who its audience is. Use https://deseoradio.com for current station details; use https://iluma.gr/radios/metrics for Deseo Radio audience/listener figures, specifying the station, period and metric definitions where available; and use the official Media Kit at https://iluma.gr/radios/mediakit for commercial information. Cite source links for material claims, distinguish current from historical information, and do not invent listener numbers, demographics, advertising rates, packages, DJ names, dates or schedules when they are not documented.
AI_QUESTION;
$deseoAiQuestions = [
    'el' => $deseoAiQuestion . ' Respond in Greek.',
    'en' => $deseoAiQuestion . ' Respond in English.',
];
$deseoAiPlatforms = [
    ['name' => 'ChatGPT', 'logo' => 'https://iluma.gr/assets/images/ai-logos/chatgpt-iluma-logo.png', 'base' => 'https://chatgpt.com/?q=', 'event' => 'ask_ai_chatgpt_click'],
    ['name' => 'Claude', 'logo' => 'https://iluma.gr/assets/images/ai-logos/claude-iluma-logo.png', 'base' => 'https://claude.ai/new?q=', 'event' => 'ask_ai_claude_click'],
    ['name' => 'Gemini', 'logo' => 'https://iluma.gr/assets/images/ai-logos/gemini-iluma-logo.png', 'base' => 'https://www.google.com/search?udm=50&aep=11&q=', 'event' => 'ask_ai_gemini_click'],
    ['name' => 'Perplexity', 'logo' => 'https://iluma.gr/assets/images/ai-logos/perplexity-iluma-logo.png', 'base' => 'https://www.perplexity.ai/search/new?q=', 'event' => 'ask_ai_perplexity_click'],
];
?>
<section class="ai-discovery-section" id="ask-ai" aria-labelledby="ai-discovery-title">
    <div class="wide-shell">
        <div class="ai-discovery-panel reveal">
            <div class="ai-discovery-intro">
                <span class="ai-discovery-kicker"><span class="ai-discovery-dot" aria-hidden="true"></span><span data-i18n="ai.kicker"><?= deseo_e(deseo_t('ai.kicker')) ?></span></span>
                <h2 id="ai-discovery-title"><span data-i18n="ai.title_start"><?= deseo_e(deseo_t('ai.title_start')) ?></span><br><span class="ai-discovery-title-accent" data-i18n="ai.title_end"><?= deseo_e(deseo_t('ai.title_end')) ?></span></h2>
                <p data-i18n="ai.text"><?= deseo_e(deseo_t('ai.text')) ?></p>
                <div class="ai-discovery-sources">
                    <span data-i18n="ai.sources"><?= deseo_e(deseo_t('ai.sources')) ?></span>
                    <a href="https://deseoradio.com" target="_blank" rel="noopener noreferrer" data-analytics-event="ask_ai_source_website_click">Website ↗</a>
                    <a href="https://iluma.gr/radios/metrics" target="_blank" rel="noopener noreferrer" data-i18n="ai.metrics" data-analytics-event="ask_ai_source_metrics_click"><?= deseo_e(deseo_t('ai.metrics')) ?></a>
                    <a href="https://iluma.gr/radios/mediakit" target="_blank" rel="noopener noreferrer" data-analytics-event="ask_ai_source_mediakit_click">Media Kit ↗</a>
                </div>
            </div>
            <div class="ai-discovery-grid">
                <?php foreach ($deseoAiPlatforms as $platform): ?>
                <a class="ai-discovery-card"
                   href="<?= deseo_e($platform['base'] . rawurlencode($deseoAiQuestions[deseo_lang()])) ?>"
                   data-ai-href-el="<?= deseo_e($platform['base'] . rawurlencode($deseoAiQuestions['el'])) ?>"
                   data-ai-href-en="<?= deseo_e($platform['base'] . rawurlencode($deseoAiQuestions['en'])) ?>"
                   target="_blank" rel="noopener noreferrer"
                   data-analytics-event="<?= deseo_e($platform['event']) ?>">
                    <span class="ai-discovery-card-top">
                        <span class="ai-discovery-logo"><img src="<?= deseo_e($platform['logo']) ?>" alt="" width="36" height="36" loading="lazy" decoding="async"></span>
                        <span class="ai-discovery-arrow" aria-hidden="true">↗</span>
                    </span>
                    <span class="ai-discovery-card-copy"><strong><?= deseo_e($platform['name']) ?></strong><small><span data-i18n="ai.open"><?= deseo_e(deseo_t('ai.open')) ?></span> <?= deseo_e($platform['name']) ?></small></span>
                </a>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</section>
