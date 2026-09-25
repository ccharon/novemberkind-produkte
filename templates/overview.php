<?php

/**
 * Produktübersicht als Liste oder Kacheln, gruppiert nach Kategorie.
 *
 * @var \WC_Product[]                                            $products
 * @var array<string, array{name: string, products: \WC_Product[]}> $groups
 */

declare(strict_types=1);

namespace NovemberkindProdukte;

defined('ABSPATH') || exit;
?>
<div class="nkp-overview nkp-overview--list" data-nkp-overview>
<?php // Gewählte Ansicht vor dem ersten Zeichnen setzen, damit die Seite nicht umspringt ?>
<script>try { if (localStorage.getItem('nkpOverviewView') === 'grid') { document.currentScript.parentElement.classList.replace('nkp-overview--list', 'nkp-overview--grid'); } } catch (e) {}</script>
<header class="nkp-header">
    <h1><?php esc_html_e('Meine Produkte', 'novemberkind-produkte'); ?></h1>
    <div class="nkp-header__actions">
        <?php if ($products !== []) : ?>
        <div class="nkp-view-switch" role="group" aria-label="<?php esc_attr_e('Ansicht', 'novemberkind-produkte'); ?>">
            <button type="button" class="nkp-view-switch__button" data-nkp-view="list" aria-pressed="true" title="<?php esc_attr_e('Liste', 'novemberkind-produkte'); ?>">
                <svg viewBox="0 0 20 20" width="20" height="20" aria-hidden="true" focusable="false"><g fill="currentColor"><rect x="2" y="3.5" width="3" height="3" rx="0.8"/><rect x="7" y="4.25" width="11" height="1.5" rx="0.75"/><rect x="2" y="8.5" width="3" height="3" rx="0.8"/><rect x="7" y="9.25" width="11" height="1.5" rx="0.75"/><rect x="2" y="13.5" width="3" height="3" rx="0.8"/><rect x="7" y="14.25" width="11" height="1.5" rx="0.75"/></g></svg>
                <span class="nkp-sr-only"><?php esc_html_e('Liste', 'novemberkind-produkte'); ?></span>
            </button>
            <button type="button" class="nkp-view-switch__button" data-nkp-view="grid" aria-pressed="false" title="<?php esc_attr_e('Kacheln', 'novemberkind-produkte'); ?>">
                <svg viewBox="0 0 20 20" width="20" height="20" aria-hidden="true" focusable="false"><g fill="currentColor"><rect x="2.5" y="2.5" width="6.5" height="6.5" rx="1.5"/><rect x="11" y="2.5" width="6.5" height="6.5" rx="1.5"/><rect x="2.5" y="11" width="6.5" height="6.5" rx="1.5"/><rect x="11" y="11" width="6.5" height="6.5" rx="1.5"/></g></svg>
                <span class="nkp-sr-only"><?php esc_html_e('Kacheln', 'novemberkind-produkte'); ?></span>
            </button>
        </div>
        <?php endif; ?>
        <a class="nkp-button nkp-button--primary" href="<?php echo esc_url(App::new_url()); ?>">
            <?php esc_html_e('+ Neues Produkt', 'novemberkind-produkte'); ?>
        </a>
    </div>
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
        <li class="nkp-list-head" aria-hidden="true">
            <span class="nkp-list-head__name"><?php esc_html_e('Produkt', 'novemberkind-produkte'); ?></span>
            <span class="nkp-list-head__sku"><?php esc_html_e('Artikelnummer', 'novemberkind-produkte'); ?></span>
            <span class="nkp-list-head__price"><?php esc_html_e('Preis', 'novemberkind-produkte'); ?></span>
            <span class="nkp-list-head__status"><?php esc_html_e('Status', 'novemberkind-produkte'); ?></span>
            <span class="nkp-list-head__stock"><?php esc_html_e('Bestand', 'novemberkind-produkte'); ?></span>
        </li>
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
                        <p class="nkp-card__sku"><?php echo esc_html($product->get_sku()); ?></p>
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
</div>
