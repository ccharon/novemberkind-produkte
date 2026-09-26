<?php

/**
 * Kopf einer Liste: Überschrift, Knöpfe rechts daneben und einleitende Hinweise.
 *
 * @var string                                                     $list_title
 * @var array<int, array{url: string, label: string, primary: bool}> $list_buttons
 * @var string[]                                                   $list_intro Zeilen der Einleitung
 */

declare(strict_types=1);

namespace NovemberkindProdukte;

defined('ABSPATH') || exit;
?>
<header class="nkp-header">
    <h1><?php echo esc_html($list_title); ?></h1>
    <?php if ($list_buttons !== []) : ?>
        <div class="nkp-header__actions">
            <?php foreach ($list_buttons as $list_button) : ?>
                <a class="nkp-button nkp-button--<?php echo $list_button['primary'] ? 'primary' : 'secondary'; ?>" href="<?php echo esc_url($list_button['url']); ?>"><?php echo esc_html($list_button['label']); ?></a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</header>

<?php if ($list_intro !== []) : ?>
    <p class="nkp-note nkp-note--intro"><?php echo implode('<br>', array_map('esc_html', $list_intro)); // phpcs:ignore WordPress.Security.EscapeOutput -- jede Zeile mit esc_html ?></p>
<?php endif; ?>
