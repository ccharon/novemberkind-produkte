<?php

/**
 * Öffentliche Seite zum Bestätigen oder Abmelden, im Stil der Produktverwaltung.
 *
 * @var string $heading Überschrift
 * @var string $text    Erklärung
 * @var string $button  Beschriftung des Knopfs, leer ohne Knopf
 * @var string $shop    Name des Shops
 */

declare(strict_types=1);

namespace NovemberkindProdukte;

defined('ABSPATH') || exit;
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo('charset'); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title><?php echo esc_html($heading . ' · ' . $shop); ?></title>
    <?php if (has_site_icon()) : ?>
        <link rel="icon" href="<?php echo esc_url(get_site_icon_url(64)); ?>">
    <?php endif; ?>
    <?php wp_print_styles('novemberkind-produkte-app'); ?>
</head>
<body class="nkp-page">
    <header class="nkp-topbar">
        <a class="nkp-topbar__brand" href="<?php echo esc_url(home_url('/')); ?>"><?php echo esc_html($shop); ?></a>
    </header>
    <main class="nkp-wrap nkp-public">
        <section class="nkp-panel">
            <h1><?php echo esc_html($heading); ?></h1>
            <p><?php echo esc_html($text); ?></p>
            <?php if ($button !== '') : ?>
                <form method="post">
                    <button type="submit" class="nkp-button nkp-button--primary"><?php echo esc_html($button); ?></button>
                </form>
            <?php else : ?>
                <p><a href="<?php echo esc_url(home_url('/')); ?>"><?php esc_html_e('Zum Shop', 'novemberkind-produkte'); ?></a></p>
            <?php endif; ?>
        </section>
    </main>
</body>
</html>
