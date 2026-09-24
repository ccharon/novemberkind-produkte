<?php

/**
 * Produktübersicht als Kacheln.
 *
 * @var \WC_Product[]                                            $products
 * @var array<string, array{name: string, products: \WC_Product[]}> $groups
 */

declare(strict_types=1);

namespace NovemberkindProdukte;

defined('ABSPATH') || exit;
?>
<header class="nkp-header">
    <h1><?php esc_html_e('Meine Produkte', 'novemberkind-produkte'); ?></h1>
    <a class="nkp-button nkp-button--primary" href="<?php echo esc_url(App::new_url()); ?>">
        <?php esc_html_e('+ Neues Produkt', 'novemberkind-produkte'); ?>
    </a>
</header>

<?php if ($products === []) : ?>
    <p class="nkp-empty"><?php esc_html_e('Noch keine Produkte vorhanden.', 'novemberkind-produkte'); ?></p>
<?php else : ?>
    <?php foreach ($groups as $slug => $group) : ?>
    <details class="nkp-group" open data-nkp-group="<?php echo esc_attr($slug); ?>">
        <summary class="nkp-group__summary">
            <h2 class="nkp-group__title"><?php echo esc_html($group['name']); ?></h2>
            <span class="nkp-group__count"><?php echo esc_html((string) count($group['products'])); ?></span>
        </summary>
    <ul class="nkp-grid">
        <?php foreach ($group['products'] as $product) : ?>
            <?php
            $is_online = $product->get_status() === 'publish';
            $type      = ProductType::detect($product);
            $is_sold   = Originals::is_sold($product);
            $url       = $type ? App::edit_url($product->get_id()) : (string) get_edit_post_link($product->get_id(), 'raw');
            ?>
            <li class="nkp-card<?php echo $is_sold ? ' nkp-card--sold' : ''; ?>">
                <a class="nkp-card__link" href="<?php echo esc_url($url); ?>">
                    <?php if ($product->get_image_id()) : ?>
                        <?php echo wp_get_attachment_image((int) $product->get_image_id(), 'woocommerce_thumbnail', false, ['class' => 'nkp-card__image']); ?>
                    <?php else : ?>
                        <div class="nkp-card__image nkp-card__image--placeholder"><?php esc_html_e('Noch kein Foto', 'novemberkind-produkte'); ?></div>
                    <?php endif; ?>
                    <div class="nkp-card__body">
                        <h3 class="nkp-card__title"><?php echo esc_html($product->get_name()); ?></h3>
                        <p class="nkp-card__price"><?php echo wp_kses_post($product->get_price_html()); ?></p>
                        <p class="nkp-card__meta">
                            <?php if ($is_sold) : ?>
                                <span class="nkp-badge nkp-badge--sold"><?php esc_html_e('Verkauft', 'novemberkind-produkte'); ?></span>
                            <?php else : ?>
                                <span class="nkp-badge nkp-badge--<?php echo $is_online ? 'online' : 'draft'; ?>">
                                    <?php echo $is_online ? esc_html__('Online', 'novemberkind-produkte') : esc_html__('Entwurf', 'novemberkind-produkte'); ?>
                                </span>
                            <?php endif; ?>
                            <?php if (!$type) : ?>
                                <span class="nkp-stock"><?php esc_html_e('Bearbeiten in WooCommerce', 'novemberkind-produkte'); ?></span>
                            <?php elseif ($type->is_unique()) : ?>
                                <span class="nkp-stock"><?php esc_html_e('Unikat', 'novemberkind-produkte'); ?></span>
                            <?php elseif ($product->managing_stock()) : ?>
                                <span class="nkp-stock">
                                    <?php
                                    /* translators: %d: Lagerbestand */
                                    echo esc_html(sprintf(__('%d auf Lager', 'novemberkind-produkte'), (int) $product->get_stock_quantity()));
                                    ?>
                                </span>
                            <?php endif; ?>
                        </p>
                    </div>
                </a>
            </li>
        <?php endforeach; ?>
    </ul>
    </details>
    <?php endforeach; ?>
<?php endif; ?>
