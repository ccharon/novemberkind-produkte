<?php

/**
 * Seitenrahmen der Produktverwaltung: eigenes HTML-Dokument ohne WordPress-Oberfläche.
 *
 * @var string $view  Name des Templates für den Inhalt
 * @var string $title Seitentitel
 */

declare(strict_types=1);

namespace EasyProduct;

defined('ABSPATH') || exit;

$shop_name = get_bloginfo('name');
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo('charset'); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title><?php echo esc_html($title . ' · ' . $shop_name); ?></title>
    <?php if (has_site_icon()) : ?>
        <link rel="icon" href="<?php echo esc_url(get_site_icon_url(64)); ?>">
    <?php endif; ?>
    <?php wp_print_styles('easy-product-app'); ?>
</head>
<body class="ep-page">
    <header class="ep-topbar">
        <a class="ep-topbar__brand" href="<?php echo esc_url(App::url()); ?>"><?php echo esc_html($shop_name); ?></a>
        <nav class="ep-topbar__nav">
            <a href="<?php echo esc_url(home_url('/')); ?>" target="_blank" rel="noopener"><?php esc_html_e('Zum Shop', 'easy-product'); ?></a>
            <a href="<?php echo esc_url(wp_logout_url(App::url())); ?>"><?php esc_html_e('Abmelden', 'easy-product'); ?></a>
        </nav>
    </header>

    <main class="ep-wrap">
        <?php if ($view === 'not-found') : ?>
            <a class="ep-back" href="<?php echo esc_url(App::url()); ?>"><?php esc_html_e('← Alle Produkte', 'easy-product'); ?></a>
            <p class="ep-empty"><?php esc_html_e('Dieses Produkt gibt es nicht mehr.', 'easy-product'); ?></p>
        <?php else : ?>
            <?php include __DIR__ . "/{$view}.php"; ?>
        <?php endif; ?>
    </main>

    <?php wp_print_scripts('easy-product-app'); ?>
</body>
</html>
