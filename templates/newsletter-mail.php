<?php

/**
 * HTML-Rahmen einer Newsletter-Mail. Tabellen und Inline-Stile, weil Mailprogramme kaum CSS verstehen.
 *
 * @var string   $subject         Betreff, auch als Titel
 * @var string   $preheader       Vorschauzeile im Posteingang
 * @var string   $content         fertiges HTML des Inhalts
 * @var string   $unsubscribe_url Abmeldelink, leer bei der Bestätigungsmail
 * @var string   $shop            Name des Shops
 * @var string   $home            Startseite des Shops
 * @var string[] $footer          Name und Adresse
 * @var string   $imprint         Impressum, falls bekannt
 * @var string   $privacy         Datenschutzerklärung, falls bekannt
 */

declare(strict_types=1);

namespace NovemberkindProdukte;

defined('ABSPATH') || exit;

$link_style = 'color:#6e645a;text-decoration:underline;';
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light">
    <title><?php echo esc_html($subject); ?></title>
    <style>
        @media (max-width: 520px) {
            .nkp-mail-card { display: block !important; width: 100% !important; }
            .nkp-mail-card img { max-width: 100% !important; }
            .nkp-mail-body { padding: 24px 20px !important; }
        }
    </style>
</head>
<body style="margin:0;padding:0;background:#f6f1ea;color:#2e2823;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;">
    <?php if ($preheader !== '') : ?>
        <span class="nkp-mail-preheader" style="display:none;max-height:0;overflow:hidden;opacity:0;"><?php echo esc_html($preheader); ?></span>
    <?php endif; ?>
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#f6f1ea;">
        <tr>
            <td align="center" style="padding:32px 12px;">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="max-width:600px;">
                    <tr>
                        <td align="center" style="padding:0 0 20px;">
                            <a href="<?php echo esc_url($home); ?>" style="font-family:Georgia,'Times New Roman',serif;font-size:24px;color:#2e2823;text-decoration:none;"><?php echo esc_html($shop); ?></a>
                        </td>
                    </tr>
                    <tr>
                        <td class="nkp-mail-body" style="background:#fffdf9;border:1px solid #e8ddd0;border-radius:16px;padding:32px 36px;">
                            <?php echo $content; // phpcs:ignore WordPress.Security.EscapeOutput -- beim Speichern mit wp_kses_post bereinigt, Karten und Knöpfe escapen selbst ?>
                        </td>
                    </tr>
                    <tr>
                        <td align="center" style="padding:24px 16px 0;font-size:13px;line-height:1.6;color:#6e645a;">
                            <?php echo esc_html(implode(' · ', $footer)); ?>
                            <?php if ($imprint !== '' || $privacy !== '') : ?>
                                <br>
                            <?php endif; ?>
                            <?php if ($imprint !== '') : ?>
                                <a href="<?php echo esc_url($imprint); ?>" style="<?php echo esc_attr($link_style); ?>"><?php esc_html_e('Impressum', 'novemberkind-produkte'); ?></a>
                            <?php endif; ?>
                            <?php if ($privacy !== '') : ?>
                                <?php echo $imprint !== '' ? ' · ' : ''; ?>
                                <a href="<?php echo esc_url($privacy); ?>" style="<?php echo esc_attr($link_style); ?>"><?php esc_html_e('Datenschutz', 'novemberkind-produkte'); ?></a>
                            <?php endif; ?>
                            <?php if ($unsubscribe_url !== '') : ?>
                                <br><?php esc_html_e('Du bekommst diese Mail, weil du dich für den Newsletter angemeldet hast.', 'novemberkind-produkte'); ?>
                                <a href="<?php echo esc_url($unsubscribe_url); ?>" style="<?php echo esc_attr($link_style); ?>"><?php esc_html_e('Vom Newsletter abmelden', 'novemberkind-produkte'); ?></a>
                            <?php endif; ?>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
