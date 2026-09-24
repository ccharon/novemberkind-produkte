<?php

/**
 * Auswahl der Produktart für ein neues Produkt.
 *
 * @var \NovemberkindProdukte\ProductType[] $types
 */

declare(strict_types=1);

namespace NovemberkindProdukte;

defined('ABSPATH') || exit;
?>
<a class="nkp-back" href="<?php echo esc_url(App::url()); ?>"><?php esc_html_e('← Alle Produkte', 'novemberkind-produkte'); ?></a>

<header class="nkp-header">
    <h1><?php esc_html_e('Was möchtest du anlegen?', 'novemberkind-produkte'); ?></h1>
</header>

<ul class="nkp-types">
    <?php foreach ($types as $type) : ?>
        <li>
            <a class="nkp-type" href="<?php echo esc_url(App::new_url($type->key())); ?>">
                <span class="nkp-type__label"><?php echo esc_html($type->label()); ?></span>
                <span class="nkp-type__hint"><?php echo esc_html($type->hint()); ?></span>
            </a>
        </li>
    <?php endforeach; ?>
</ul>

<p class="nkp-note">
    <?php esc_html_e('Tassen, Sets und digitale Produkte legst du wie bisher in WooCommerce an.', 'novemberkind-produkte'); ?>
    <a href="<?php echo esc_url(admin_url('post-new.php?post_type=product')); ?>"><?php esc_html_e('Zu WooCommerce', 'novemberkind-produkte'); ?></a>
</p>
