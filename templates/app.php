<?php

/**
 * Seitenrahmen der Produktverwaltung: eigenes HTML-Dokument ohne WordPress-Oberfläche.
 *
 * @var string $view  Name des Templates für den Inhalt
 * @var string $title Seitentitel
 */

declare(strict_types=1);

namespace NovemberkindProdukte;

defined('ABSPATH') || exit;

$shop_name = get_bloginfo('name');
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo('charset'); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <meta name="theme-color" content="#f6f1ea">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-title" content="<?php esc_attr_e('Produkte', 'novemberkind-produkte'); ?>">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <link rel="manifest" href="<?php echo esc_url(App::manifest_url()); ?>">
    <link rel="apple-touch-icon" href="<?php echo esc_url(plugin_dir_url(PLUGIN_FILE) . 'assets/icons/app-icon-180.png'); ?>">
    <title><?php echo esc_html($title . ' · ' . $shop_name); ?></title>
    <link rel="icon" href="<?php echo esc_url(has_site_icon() ? get_site_icon_url(64) : plugin_dir_url(PLUGIN_FILE) . 'assets/icons/app-icon-192.png'); ?>">
    <?php wp_print_styles('novemberkind-produkte-app'); ?>
</head>
<body class="nkp-page">
    <header class="nkp-topbar">
        <a class="nkp-topbar__brand" href="<?php echo esc_url(App::url()); ?>"><?php echo esc_html($shop_name); ?></a>
        <nav class="nkp-topbar__nav">
            <button type="button" class="nkp-vine-toggle" data-nkp-vine-toggle aria-pressed="true" hidden
                    title="<?php esc_attr_e('Ranke im Hintergrund ein- oder ausblenden', 'novemberkind-produkte'); ?>">
                <svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true"><path d="M5 19c8 0 13-5 14-14-9 1-14 6-14 14Zm0 0 7-7" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
                <span class="screen-reader-text"><?php esc_html_e('Ranke', 'novemberkind-produkte'); ?></span>
            </button>
            <a href="<?php echo esc_url(App::url()); ?>" <?php echo in_array($view, ['overview', 'type-picker', 'product-form'], true) ? 'aria-current="page"' : ''; ?>><?php esc_html_e('Produkte', 'novemberkind-produkte'); ?></a>
            <a href="<?php echo esc_url(App::campaigns_url()); ?>" <?php echo str_starts_with($view, 'campaign') ? 'aria-current="page"' : ''; ?>><?php esc_html_e('Aktionen', 'novemberkind-produkte'); ?></a>
            <?php if (current_user_can('edit_shop_coupons')) : ?>
                <a href="<?php echo esc_url(App::coupons_url()); ?>" <?php echo str_starts_with($view, 'coupon') ? 'aria-current="page"' : ''; ?>><?php esc_html_e('Gutscheine', 'novemberkind-produkte'); ?></a>
            <?php endif; ?>
            <a href="<?php echo esc_url(home_url('/')); ?>" target="_blank" rel="noopener"><?php esc_html_e('Zum Shop', 'novemberkind-produkte'); ?></a>
            <a href="<?php echo esc_url(wp_logout_url(App::url())); ?>"><?php esc_html_e('Abmelden', 'novemberkind-produkte'); ?></a>
        </nav>
    </header>

    <main class="nkp-wrap">
        <?php if ($view === 'not-found') : ?>
            <a class="nkp-back" href="<?php echo esc_url(App::url()); ?>"><?php esc_html_e('← Alle Produkte', 'novemberkind-produkte'); ?></a>
            <p class="nkp-empty"><?php echo esc_html($missing); ?></p>
        <?php else : ?>
            <?php include __DIR__ . "/{$view}.php"; ?>
        <?php endif; ?>
    </main>

    <?php $simple = str_starts_with($view, 'campaign') || str_starts_with($view, 'coupon'); ?>
    <?php wp_print_scripts([$simple ? 'novemberkind-produkte-forms' : 'novemberkind-produkte-app', 'novemberkind-produkte-vine']); ?>
</body>
</html>
