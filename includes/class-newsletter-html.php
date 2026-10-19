<?php

declare(strict_types=1);

namespace NovemberkindProdukte;

defined('ABSPATH') || exit;

/**
 * Inhalt aus dem Newsletter-Designer: MJML als Quelle zum Weiterbearbeiten und das im Browser kompilierte
 * Mail-HTML für den Versand. Der Server kann MJML nicht kompilieren, deshalb prüft er beides hier.
 */
final class NewsletterHtml
{
    public const MAX_BYTES = 1048576;
    // MJML-Elemente, die der Designer erzeugt. mj-style, mj-font und mj-include fehlen absichtlich:
    // eigenes CSS, Webfonts von fremden Servern und Dateien vom Server gehören nicht in die Mail.
    private const MJML_TAGS = [
        'mjml', 'mj-head', 'mj-body', 'mj-attributes', 'mj-all', 'mj-class', 'mj-breakpoint', 'mj-title', 'mj-preview',
        'mj-wrapper', 'mj-section', 'mj-group', 'mj-column', 'mj-text', 'mj-image', 'mj-button', 'mj-divider',
        'mj-spacer', 'mj-table', 'mj-navbar', 'mj-navbar-link', 'mj-social', 'mj-social-element', 'mj-hero', 'mj-raw',
    ];
    // Eigenschaften, die Mails brauchen und die safecss_filter_attr() sonst entfernt
    private const MAIL_CSS = [
        'border-collapse', 'border-spacing', 'cursor', 'direction', 'display', 'font', 'font-family', 'font-size',
        'font-style', 'font-weight', 'height', 'letter-spacing', 'line-height', 'max-width', 'min-width', 'outline',
        'table-layout', 'text-align', 'text-decoration', 'text-transform', 'vertical-align', 'white-space', 'width',
        'word-break', 'word-spacing', 'word-wrap', '-webkit-text-size-adjust', '-ms-text-size-adjust',
        '-ms-interpolation-mode', 'mso-hide', 'mso-line-height-rule', 'mso-padding-alt', 'mso-table-lspace', 'mso-table-rspace',
    ];
    // Bedingte Kommentare für Outlook, wie MJML sie schreibt, z. B. [if mso | IE] oder [if lte mso 11]
    private const CONDITION = '/^!?\s*(mso|IE|mso \| IE|(lt|lte|gt|gte) mso \d+)$/';

    /**
     * Leere Vorlage für einen neuen Newsletter in Schrift und Farben des Shops.
     */
    public static function starter(): string
    {
        $colors = NewsletterMail::COLORS;
        $font   = esc_attr(NewsletterMail::FONT);
        $title  = esc_html__('Überschrift', 'novemberkind-produkte');
        $text   = esc_html__('Dein Text', 'novemberkind-produkte');

        return '<mjml><mj-body background-color="' . $colors['page'] . '"><mj-section><mj-column>'
            . '<mj-text font-family="' . $font . '" font-size="24px" line-height="1.3" color="' . $colors['text'] . '">' . $title . '</mj-text>'
            . '<mj-text font-family="' . $font . '" font-size="16px" line-height="1.7" color="' . $colors['text'] . '"><p>' . $text . '</p></mj-text>'
            . '</mj-column></mj-section></mj-body></mjml>';
    }

    /**
     * MJML für einen Text aus dem früheren Editor, damit ältere Entwürfe im Designer weiter bearbeitet werden können.
     */
    public static function from_text(string $content): string
    {
        $colors = NewsletterMail::COLORS;

        return '<mjml><mj-body background-color="' . $colors['page'] . '"><mj-section><mj-column>'
            . '<mj-text font-family="' . esc_attr(NewsletterMail::FONT) . '" font-size="16px" line-height="1.7" color="' . $colors['text'] . '">'
            . wp_kses_post($content) . '</mj-text></mj-column></mj-section></mj-body></mjml>';
    }

    /**
     * Bereinigt die MJML-Quelle. Sie wird später wieder in den Designer geladen, auch von anderen Konten,
     * deshalb gelten dieselben Regeln wie für Beiträge: keine Skripte, keine Event-Handler, nur sichere Adressen.
     */
    public static function source(string $mjml): string
    {
        // MJML-Attribute sind zahlreich. Erlaubt ist, was im Text vorkommt, außer Event-Handlern.
        preg_match_all('/\s([a-z][a-z0-9-]*)\s*=/i', $mjml, $found);
        $attributes = [];
        foreach (array_unique(array_map('strtolower', $found[1])) as $name) {
            if (!str_starts_with($name, 'on')) {
                $attributes[$name] = true;
            }
        }
        $allowed = wp_kses_allowed_html('post');
        foreach (self::MJML_TAGS as $tag) {
            $allowed[$tag] = $attributes;
        }

        $uri = static fn(array $names): array => [...$names, 'background-url', 'icon-src'];
        add_filter('wp_kses_uri_attributes', $uri);
        try {
            return trim(wp_kses($mjml, $allowed));
        } finally {
            remove_filter('wp_kses_uri_attributes', $uri);
        }
    }

    /**
     * Zerlegt die kompilierte Mail in Inhalt und Stile und bereinigt beides.
     *
     * @return array{content: string, css: string}
     */
    public static function document(string $html): array
    {
        // Hintergrundbilder würden von fremden Servern laden und Outlook zeigt sie ohnehin kaum
        $html = (string) preg_replace('/url\s*\([^)]*\)/i', 'none', $html);

        $head = preg_match('#<head\b[^>]*>(.*?)</head>#is', $html, $match) ? $match[1] : '';
        // Stile für Outlook stehen fest im Rahmen der Mail, hier zählen nur die für alle Mailprogramme
        $head = (string) preg_replace('/<!--.*?-->/s', '', $head);
        preg_match_all('#<style\b[^>]*>(.*?)</style>#is', $head, $styles);
        $css = self::css(implode("\n", $styles[1]));

        $body = preg_match('#<body\b[^>]*>(.*)</body>#is', $html, $match) ? $match[1] : (string) preg_replace('#<head\b.*?</head>#is', '', $html);

        return ['content' => self::body($body), 'css' => $css];
    }

    /**
     * Stile aus dem Kopf der Mail: Regeln für Spalten und Handys. Webfonts, Importe, Adressen und alles,
     * was aus dem style-Element ausbrechen könnte, fallen weg.
     */
    public static function css(string $css): string
    {
        $css = (string) preg_replace('#/\*.*?\*/#s', '', $css);
        $css = (string) preg_replace('/@(import|font-face|charset|namespace)\b[^;{]*(;|\{[^}]*\})/i', '', $css);
        $css = (string) preg_replace('/(expression|javascript|behavior|-moz-binding|url)\s*[:(]/i', '', $css);
        // Ohne < kann nichts das style-Element schließen, > bleibt für Selektoren
        $css = str_replace(['<', '\\', '"', "'"], '', $css);
        $lines = array_filter(array_map('trim', explode("\n", $css)), static fn(string $line): bool => $line !== '');

        return implode("\n", $lines);
    }

    /**
     * Ersetzt Fotos aus der Mediathek durch ihre JPEG-Fassung für Outlook. Andere Bilder fallen weg.
     */
    public static function mail_images(string $html): string
    {
        return (string) preg_replace_callback('/<img\s[^>]*>/i', static function (array $found): string {
            if (!preg_match('/\ssrc\s*=\s*"([^"]*)"/i', $found[0], $src)) {
                return '';
            }
            $attachment_id = attachment_url_to_postid(html_entity_decode($src[1], ENT_QUOTES, 'UTF-8'));
            $url = $attachment_id && wp_attachment_is_image($attachment_id) ? ImageProcessor::mail_url($attachment_id, 'full') : '';

            return $url !== '' ? str_replace($src[0], ' src="' . esc_url($url) . '"', $found[0]) : '';
        }, $html);
    }

    /**
     * Inhalt der Mail mit den Regeln für Beiträge plus Mail-CSS. Bedingte Kommentare für Outlook bleiben,
     * ihr Inhalt wird ebenso geprüft. Andere Kommentare fallen weg.
     */
    private static function body(string $body): string
    {
        $comments = [];
        $body = (string) preg_replace_callback('/<!--(.*?)-->/s', static function (array $comment) use (&$comments): string {
            $kept = self::comment($comment[1]);
            if ($kept === '') {
                return '';
            }
            $key = 'nkpkommentar' . count($comments) . 'x';
            $comments[$key] = $kept;

            return $key;
        }, $body);

        // Offene Elemente würden in der Mail den Fuß mit dem Abmeldelink umschließen
        $body = trim(force_balance_tags(self::kses($body)));

        return strtr($body, $comments);
    }

    /**
     * Ein bedingter Kommentar mit bereinigtem Inhalt oder leer, wenn er wegfällt.
     */
    private static function comment(string $inner): string
    {
        // <!--[if !mso]><!--> und <!--<![endif]--> umschließen, was alle außer Outlook sehen
        if (preg_match('/^\[if !mso\]><!$/', $inner)) {
            return '<!--[if !mso]><!-->';
        }
        if ($inner === '<![endif]') {
            return '<!--<![endif]-->';
        }
        if (!preg_match('/^\[if ([^\]]+)\]>(.*)<!\[endif\]$/s', $inner, $parts) || !preg_match(self::CONDITION, $parts[1])) {
            return '';
        }

        return '<!--[if ' . $parts[1] . ']>' . str_replace('--', '', self::kses($parts[2])) . '<![endif]-->';
    }

    private static function kses(string $html): string
    {
        $css = static fn(array $properties): array => [...$properties, ...self::MAIL_CSS];
        add_filter('safe_style_css', $css);
        try {
            return wp_kses_post($html);
        } finally {
            remove_filter('safe_style_css', $css);
        }
    }
}
