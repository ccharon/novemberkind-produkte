<?php

/**
 * Produktübersicht als Kacheln.
 *
 * @var \WC_Product[]                                            $products
 * @var array<string, array{name: string, products: \WC_Product[]}> $groups
 */

declare(strict_types=1);

namespace EasyProduct;

defined('ABSPATH') || exit;
?>
<header class="ep-header">
    <h1><?php esc_html_e('Meine Produkte', 'easy-product'); ?></h1>
    <a class="ep-button ep-button--primary" href="<?php echo esc_url(App::new_url()); ?>">
        <?php esc_html_e('+ Neues Produkt', 'easy-product'); ?>
    </a>
</header>

<?php if ($products === []) : ?>
    <p class="ep-empty"><?php esc_html_e('Noch keine Produkte vorhanden.', 'easy-product'); ?></p>
<?php else : ?>
    <?php foreach ($groups as $slug => $group) : ?>
    <details class="ep-group" open data-ep-group="<?php echo esc_attr($slug); ?>">
        <summary class="ep-group__summary">
            <h2 class="ep-group__title"><?php echo esc_html($group['name']); ?></h2>
            <span class="ep-group__count"><?php echo esc_html((string) count($group['products'])); ?></span>
        </summary>
    <ul class="ep-grid">
        <?php foreach ($group['products'] as $product) : ?>
            <?php
            $is_online = $product->get_status() === 'publish';
            $type      = ProductType::detect($product);
            $is_sold   = Originals::is_sold($product);
            $url       = $type ? App::edit_url($product->get_id()) : (string) get_edit_post_link($product->get_id(), 'raw');
            ?>
            <li class="ep-card<?php echo $is_sold ? ' ep-card--sold' : ''; ?>">
                <a class="ep-card__link" href="<?php echo esc_url($url); ?>">
                    <?php if ($product->get_image_id()) : ?>
                        <?php echo wp_get_attachment_image((int) $product->get_image_id(), 'woocommerce_thumbnail', false, ['class' => 'ep-card__image']); ?>
                    <?php else : ?>
                        <div class="ep-card__image ep-card__image--placeholder"><?php esc_html_e('Noch kein Foto', 'easy-product'); ?></div>
                    <?php endif; ?>
                    <div class="ep-card__body">
                        <h3 class="ep-card__title"><?php echo esc_html($product->get_name()); ?></h3>
                        <p class="ep-card__price"><?php echo wp_kses_post($product->get_price_html()); ?></p>
                        <p class="ep-card__meta">
                            <?php if ($is_sold) : ?>
                                <span class="ep-badge ep-badge--sold"><?php esc_html_e('Verkauft', 'easy-product'); ?></span>
                            <?php else : ?>
                                <span class="ep-badge ep-badge--<?php echo $is_online ? 'online' : 'draft'; ?>">
                                    <?php echo $is_online ? esc_html__('Online', 'easy-product') : esc_html__('Entwurf', 'easy-product'); ?>
                                </span>
                            <?php endif; ?>
                            <?php if (!$type) : ?>
                                <span class="ep-stock"><?php esc_html_e('Bearbeiten in WooCommerce', 'easy-product'); ?></span>
                            <?php elseif ($type->is_unique()) : ?>
                                <span class="ep-stock"><?php esc_html_e('Unikat', 'easy-product'); ?></span>
                            <?php elseif ($product->managing_stock()) : ?>
                                <span class="ep-stock">
                                    <?php
                                    /* translators: %d: Lagerbestand */
                                    echo esc_html(sprintf(__('%d auf Lager', 'easy-product'), (int) $product->get_stock_quantity()));
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
