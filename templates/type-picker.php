<?php

/**
 * Auswahl der Produktart für ein neues Produkt.
 *
 * @var \EasyProduct\ProductType[] $types
 */

declare(strict_types=1);

namespace EasyProduct;

defined('ABSPATH') || exit;
?>
<a class="ep-back" href="<?php echo esc_url(App::url()); ?>"><?php esc_html_e('← Alle Produkte', 'easy-product'); ?></a>

<header class="ep-header">
    <h1><?php esc_html_e('Was möchtest du anlegen?', 'easy-product'); ?></h1>
</header>

<ul class="ep-types">
    <?php foreach ($types as $type) : ?>
        <li>
            <a class="ep-type" href="<?php echo esc_url(App::new_url($type->key())); ?>">
                <span class="ep-type__label"><?php echo esc_html($type->label()); ?></span>
                <span class="ep-type__hint"><?php echo esc_html($type->hint()); ?></span>
            </a>
        </li>
    <?php endforeach; ?>
</ul>

<p class="ep-note">
    <?php esc_html_e('Tassen, Sets und digitale Produkte legst du wie bisher in WooCommerce an.', 'easy-product'); ?>
    <a href="<?php echo esc_url(admin_url('post-new.php?post_type=product')); ?>"><?php esc_html_e('Zu WooCommerce', 'easy-product'); ?></a>
</p>
