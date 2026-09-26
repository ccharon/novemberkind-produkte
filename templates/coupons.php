<?php

/**
 * Liste der Gutscheine.
 *
 * @var array<int, array{id: int, code: string, kind: string, percent: int, expires: string, once: bool, active: bool, expired: bool, usage: int, own: bool, edit_url: string}> $coupons
 */

declare(strict_types=1);

namespace NovemberkindProdukte;

defined('ABSPATH') || exit;

$describe = static function (array $coupon): string {
    $parts = [match ($coupon['kind']) {
        /* translators: %d: Rabatt in Prozent */
        'percent'  => sprintf(__('%d %% Rabatt, nicht auf reduzierte Produkte', 'novemberkind-produkte'), $coupon['percent']),
        'shipping' => __('Kostenloser Versand', 'novemberkind-produkte'),
        default    => __('Eigene Einstellungen in WooCommerce', 'novemberkind-produkte'),
    }];
    if ($coupon['expires'] !== '') {
        /* translators: %s: Datum */
        $parts[] = sprintf(__('gültig bis %s', 'novemberkind-produkte'), wp_date('d.m.Y', (new \DateTimeImmutable($coupon['expires'], wp_timezone()))->getTimestamp()));
    }
    if ($coupon['once']) {
        $parts[] = __('einmal pro Kunde', 'novemberkind-produkte');
    }
    $parts[] = $coupon['usage'] === 0
        ? __('noch nicht eingelöst', 'novemberkind-produkte')
        /* translators: %d: Anzahl der Einlösungen */
        : sprintf(_n('%d-mal eingelöst', '%d-mal eingelöst', $coupon['usage'], 'novemberkind-produkte'), $coupon['usage']);

    return implode(' · ', $parts);
};
?>
<header class="nkp-header">
    <h1><?php esc_html_e('Gutscheine', 'novemberkind-produkte'); ?></h1>
    <div class="nkp-header__actions">
        <a class="nkp-button nkp-button--primary" href="<?php echo esc_url(App::coupons_url('neu')); ?>">
            <?php esc_html_e('+ Neuer Gutschein', 'novemberkind-produkte'); ?>
        </a>
    </div>
</header>

<p class="nkp-note nkp-note--intro">
    <?php esc_html_e('Kundinnen und Kunden geben den Code im Warenkorb ein. Ein Prozent-Gutschein gilt nicht für Produkte, die schon reduziert sind. Ein Versand-Gutschein macht Brief und Päckchen kostenlos.', 'novemberkind-produkte'); ?>
</p>

<?php if (!Coupons::enabled()) : ?>
    <p class="nkp-note nkp-note--warning">
        <?php esc_html_e('Gutscheine sind im Shop ausgeschaltet. Einschalten unter WooCommerce > Einstellungen > Allgemein > „Gutscheincodes aktivieren“.', 'novemberkind-produkte'); ?>
    </p>
<?php endif; ?>

<?php if ($coupons === []) : ?>
    <p class="nkp-empty"><?php esc_html_e('Noch keine Gutscheine angelegt.', 'novemberkind-produkte'); ?></p>
<?php else : ?>
    <ul class="nkp-entries">
        <?php foreach ($coupons as $coupon) : ?>
            <?php
            $state = match (true) {
                !$coupon['active'] => ['draft', __('Deaktiviert', 'novemberkind-produkte')],
                $coupon['expired'] => ['draft', __('Abgelaufen', 'novemberkind-produkte')],
                default            => ['online', __('Aktiv', 'novemberkind-produkte')],
            };
            $url = $coupon['own'] ? App::coupons_url($coupon['id']) : $coupon['edit_url'];
            ?>
            <li class="nkp-entry<?php echo $state[0] === 'online' ? '' : ' nkp-entry--ended'; ?>">
                <a class="nkp-entry__link nkp-coupon__link" href="<?php echo esc_url($url); ?>">
                    <span class="nkp-coupon__code"><?php echo esc_html($coupon['code']); ?></span>
                    <span class="nkp-entry__main">
                        <span class="nkp-entry__meta"><?php echo esc_html($describe($coupon)); ?></span>
                        <?php if (!$coupon['own']) : ?>
                            <span class="nkp-entry__note"><?php esc_html_e('Bearbeiten in WooCommerce', 'novemberkind-produkte'); ?></span>
                        <?php endif; ?>
                    </span>
                    <span class="nkp-badge nkp-badge--<?php echo esc_attr($state[0]); ?>"><?php echo esc_html($state[1]); ?></span>
                </a>
            </li>
        <?php endforeach; ?>
    </ul>
<?php endif; ?>
