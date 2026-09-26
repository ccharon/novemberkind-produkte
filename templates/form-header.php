<?php

/**
 * Kopf eines Formulars: Link zurück zur Liste, Art des Eintrags, Titel und bei bestehenden Einträgen die Plakette.
 *
 * @var string                                   $form_back_url
 * @var string                                   $form_back_label
 * @var string                                   $form_eyebrow
 * @var string                                   $form_title
 * @var array{badge: string, label: string}|null $form_badge
 */

declare(strict_types=1);

namespace NovemberkindProdukte;

defined('ABSPATH') || exit;
?>
<a class="nkp-back" href="<?php echo esc_url($form_back_url); ?>"><?php echo esc_html($form_back_label); ?></a>

<header class="nkp-header">
    <div>
        <p class="nkp-eyebrow"><?php echo esc_html($form_eyebrow); ?></p>
        <h1><?php echo esc_html($form_title); ?></h1>
    </div>
    <?php if ($form_badge !== null) : ?>
        <?php Html::badge($form_badge); ?>
    <?php endif; ?>
</header>
