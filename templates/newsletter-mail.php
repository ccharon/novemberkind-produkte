<?php

/**
 * HTML-Rahmen einer Newsletter-Mail im Stil von novemberkind.art. Tabellen und Inline-Stile, weil Mailprogramme kaum CSS verstehen.
 *
 * @var string                $subject         Betreff, auch als Titel
 * @var string                $preheader       Vorschauzeile im Posteingang
 * @var string                $content         fertiges HTML des Inhalts
 * @var string                $unsubscribe_url Abmeldelink, leer bei der Bestätigungsmail
 * @var string                $shop            Name des Shops
 * @var string                $home            Startseite des Shops
 * @var string                $imprint         Impressum, falls bekannt
 * @var string                $privacy         Datenschutzerklärung, falls bekannt
 * @var string                $logo            Logo des Shops, leer ohne Logo
 * @var int                   $logo_width      Breite des Logos in der Mail
 * @var array<string, string> $colors          Farben aus NewsletterMail::COLORS
 * @var string                $font            Schriften
 */

declare(strict_types=1);

namespace NovemberkindProdukte;

defined('ABSPATH') || exit;

$footer_link = 'color:#ffffff;text-decoration:underline;';
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
            .nkp-mail-body { padding: 8px 20px 28px !important; }
        }
    </style>
</head>
<body style="margin:0;padding:0;background:<?php echo esc_attr($colors['page']); ?>;color:<?php echo esc_attr($colors['text']); ?>;font-family:<?php echo esc_attr($font); ?>;">
    <?php if ($preheader !== '') : ?>
        <span class="nkp-mail-preheader" style="display:none;max-height:0;overflow:hidden;opacity:0;"><?php echo esc_html($preheader); ?></span>
    <?php endif; ?>
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:<?php echo esc_attr($colors['page']); ?>;">
        <tr>
            <td align="center" style="background-color:<?php echo esc_attr($colors['sky']); ?>;background-image:linear-gradient(<?php echo esc_attr($colors['sky']); ?>,<?php echo esc_attr($colors['page']); ?>);padding:28px 16px 36px;">
                <a href="<?php echo esc_url($home); ?>" style="text-decoration:none;color:<?php echo esc_attr($colors['text']); ?>;">
                    <?php if ($logo !== '') : ?>
                        <img src="<?php echo esc_url($logo); ?>" alt="<?php echo esc_attr($shop); ?>" width="<?php echo esc_attr((string) $logo_width); ?>" style="display:block;width:<?php echo esc_attr((string) $logo_width); ?>px;max-width:100%;height:auto;border:0;">
                    <?php else : ?>
                        <span style="font-size:28px;letter-spacing:0.02em;"><?php echo esc_html($shop); ?></span>
                    <?php endif; ?>
                </a>
            </td>
        </tr>
        <tr>
            <td align="center" style="padding:0 12px;">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="max-width:600px;">
                    <tr>
                        <td class="nkp-mail-body" style="padding:8px 28px 36px;font-size:16px;line-height:1.7;color:<?php echo esc_attr($colors['text']); ?>;">
                            <?php echo $content; // phpcs:ignore WordPress.Security.EscapeOutput -- beim Speichern mit wp_kses_post bereinigt, Karten und Knöpfe escapen selbst ?>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
        <tr>
            <td align="center" style="background:<?php echo esc_attr($colors['footer']); ?>;padding:28px 16px;font-size:13px;line-height:1.7;color:#d7dce2;">
                <?php echo esc_html($shop); ?>
                <?php if ($imprint !== '' || $privacy !== '') : ?>
                    <br>
                <?php endif; ?>
                <?php if ($imprint !== '') : ?>
                    <a href="<?php echo esc_url($imprint); ?>" style="<?php echo esc_attr($footer_link); ?>"><?php esc_html_e('Impressum', 'novemberkind-produkte'); ?></a>
                <?php endif; ?>
                <?php if ($privacy !== '') : ?>
                    <?php echo $imprint !== '' ? ' · ' : ''; ?>
                    <a href="<?php echo esc_url($privacy); ?>" style="<?php echo esc_attr($footer_link); ?>"><?php esc_html_e('Datenschutz', 'novemberkind-produkte'); ?></a>
                <?php endif; ?>
                <?php if ($unsubscribe_url !== '') : ?>
                    <br><?php esc_html_e('Du bekommst diese Mail, weil du dich für den Newsletter angemeldet hast.', 'novemberkind-produkte'); ?>
                    <a href="<?php echo esc_url($unsubscribe_url); ?>" style="<?php echo esc_attr($footer_link); ?>"><?php esc_html_e('Vom Newsletter abmelden', 'novemberkind-produkte'); ?></a>
                <?php endif; ?>
            </td>
        </tr>
    </table>
</body>
</html>
