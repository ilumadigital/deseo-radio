<?php
declare(strict_types=1);
// One source-specific question for all platforms. Only the requested response language differs.
$deseoAiQuestion = <<<'AI_QUESTION'
As a listener, advertiser or potential partner, I want a complete, accurate, source-backed overview specifically of Deseo Radio (https://deseoradio.com/), the Athens-based 24/7 digital radio brand of ILUMA Digital Agency within the ILUMA Radios network. Explain its brand story (The Soundtrack of your life), music identity (contemporary House, Afro House, Organic House, Indie Dance and selected Electronic Music), curated 24/7 flow, Daily Music Zones, weekly Release Radar, exclusive DJ Sets, Resident DJs and Guest DJ Zone. Explain the announced Season 6 launch on October 14, 2026 at 20:00 Athens time and the official Wednesday–Sunday weekly schedule; distinguish scheduled DJ sets from real-time on-air status, and do not infer an overall headcount from named slots. Cover live listening and separate on-demand platforms, community, current public programming, playlists and social channels. Describe advertising, sponsored music zones, DJ show sponsorships and branded audio, noting that timing and integrations are agreed during campaign planning. Use https://deseoradio.com/ for current station facts, https://deseoradio.com/?program_feed=1 for current/next/today broadcasts and https://deseoradio.com/llms.txt for the consolidated editorial reference, with the live website taking precedence when details change. Use https://iluma.gr/radios/metrics only for dated and defined audience figures, and https://iluma.gr/radios/mediakit for documented commercial information. Cite official sources for material claims; do not invent audience numbers, demographics, advertising prices, guaranteed slots or unannounced DJ details.
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
                    <a href="https://iluma.gr/radios/mediakit" target="_blank" rel="noopener noreferrer" data-analytics-event="ask_ai_source_mediakit_click">Media Kit</a>
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
                    </span>
                    <span class="ai-discovery-card-copy"><strong><?= deseo_e($platform['name']) ?></strong><small><span data-i18n="ai.open"><?= deseo_e(deseo_t('ai.open')) ?></span> <?= deseo_e($platform['name']) ?></small></span>
                </a>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</section>
