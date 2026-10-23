<?php

declare(strict_types=1);

namespace NovemberkindProdukte;

defined('ABSPATH') || exit;

/**
 * Inhalt aus dem Blockeditor: Blöcke prüfen und für die Mail in Tabellen mit Inline-Stilen übersetzen.
 * Gelesen werden nur Art und Einstellungen der erlaubten Blöcke, nie fremdes CSS.
 */
final class NewsletterHtml
{
    public const MAX_BYTES = 1048576;
    // Blöcke, die der Editor anbietet und die Mail darstellen kann
    public const BLOCKS = [
        'core/paragraph', 'core/heading', 'core/list', 'core/list-item', 'core/image',
        'core/buttons', 'core/button', 'core/separator', 'core/spacer', 'core/columns', 'core/column',
    ];
    // Breite des Textbereichs: 600 px Mail abzüglich Innenabstand
    public const WIDTH = 544;
    private const COLUMN_GAP = 24;
    private const INLINE = ['strong' => [], 'em' => [], 'b' => [], 'i' => [], 's' => [], 'br' => [], 'a' => ['href' => true]];

    /**
     * Bereinigt die Blöcke. Sie werden später wieder in den Editor geladen, auch von anderen Konten,
     * deshalb gelten dieselben Regeln wie für Beiträge. Andere Blöcke fallen weg.
     */
    public static function source(string $markup): string
    {
        // wp_kses_post prüft über den Filter pre_kses auch die Einstellungen in den Block-Kommentaren
        return trim(serialize_blocks(self::allowed(parse_blocks(wp_kses_post($markup)))));
    }

    /**
     * Inhalt der Mail aus den Blöcken.
     */
    public static function mail(string $markup): string
    {
        return self::render(parse_blocks($markup), self::WIDTH);
    }

    /**
     * Ersetzt Fotos aus der Mediathek durch ihre JPEG-Fassung für Outlook. Andere Bilder fallen weg.
     * Gilt für Ausgaben, die noch mit dem früheren Designer gestaltet wurden.
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
     * Nur erlaubte Blöcke. Leerraum zwischen Blöcken bleibt, anderer Text außerhalb von Blöcken fällt weg.
     *
     * @param array<int, array<string, mixed>> $blocks
     * @return array<int, array<string, mixed>>
     */
    private static function allowed(array $blocks): array
    {
        $kept = [];
        foreach ($blocks as $block) {
            $name = $block['blockName'] ?? null;
            if ($name === null ? trim((string) ($block['innerHTML'] ?? '')) === '' : in_array($name, self::BLOCKS, true)) {
                $kept[] = self::allowed_inner($block);
            }
        }

        return $kept;
    }

    /**
     * Entfernt nicht erlaubte innere Blöcke samt ihrer Stelle in innerContent.
     *
     * @param array<string, mixed> $block
     * @return array<string, mixed>
     */
    private static function allowed_inner(array $block): array
    {
        $inner   = (array) ($block['innerBlocks'] ?? []);
        $blocks  = [];
        $content = [];
        $index   = 0;
        foreach ((array) ($block['innerContent'] ?? []) as $chunk) {
            if ($chunk !== null) {
                $content[] = $chunk;
                continue;
            }
            $child = $inner[$index++] ?? null;
            if (is_array($child) && in_array($child['blockName'] ?? null, self::BLOCKS, true)) {
                $blocks[]  = self::allowed_inner($child);
                $content[] = null;
            }
        }

        return ['innerBlocks' => $blocks, 'innerContent' => $content] + $block;
    }

    /**
     * @param array<int, array<string, mixed>> $blocks
     */
    private static function render(array $blocks, int $width): string
    {
        $html = '';
        foreach ($blocks as $block) {
            $html .= self::block($block, $width);
        }

        return $html;
    }

    /**
     * @param array<string, mixed> $block
     */
    private static function block(array $block, int $width): string
    {
        $attrs  = (array) ($block['attrs'] ?? []);
        $markup = (string) ($block['innerHTML'] ?? '');
        $colors = NewsletterMail::COLORS;

        return match ($block['blockName'] ?? null) {
            'core/paragraph' => self::text('p', $markup, "margin:0 0 16px;font-size:16px;line-height:1.7;color:{$colors['text']};"),
            'core/heading'   => self::heading($block),
            'core/list'      => self::list($block),
            'core/image'     => self::image($attrs, $markup, $width),
            'core/buttons'   => self::buttons($block),
            'core/separator' => '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:24px 0;"><tr>'
                . '<td style="border-top:1px solid ' . $colors['sky'] . ';font-size:1px;line-height:1px;">&nbsp;</td></tr></table>',
            'core/spacer'    => self::spacer($attrs),
            'core/columns'   => self::columns($block, $width),
            default          => '',
        };
    }

    /**
     * Absatz oder Überschrift mit Ausrichtung, im Text nur fett, kursiv, durchgestrichen und Links.
     */
    private static function text(string $tag, string $markup, string $style): string
    {
        $content = self::inline(self::unwrap($markup));
        if (trim(wp_strip_all_tags($content)) === '') {
            return '';
        }
        $align = preg_match('/\bhas-text-align-(left|center|right)\b/', $markup, $found) ? "text-align:{$found[1]};" : '';

        return "<{$tag} style=\"{$style}{$align}\">{$content}</{$tag}>";
    }

    /**
     * @param array<string, mixed> $block
     */
    private static function heading(array $block): string
    {
        $level  = (int) ($block['attrs']['level'] ?? 2) >= 3 ? 3 : 2;
        $size   = $level === 2 ? 24 : 20;
        $color  = NewsletterMail::COLORS['text'];

        return self::text("h{$level}", (string) $block['innerHTML'], "margin:28px 0 12px;font-size:{$size}px;line-height:1.3;font-weight:normal;color:{$color};");
    }

    /**
     * Liste mit Punkten oder Zahlen, auch verschachtelt.
     *
     * @param array<string, mixed> $block
     */
    private static function list(array $block): string
    {
        $items = '';
        foreach ((array) $block['innerBlocks'] as $item) {
            if (($item['blockName'] ?? '') !== 'core/list-item') {
                continue;
            }
            // Der eigene Text steht vor der ersten verschachtelten Liste
            $text = (string) ($item['innerContent'][0] ?? '');
            $text = self::inline((string) preg_replace('#^\s*<li\b[^>]*>|</li>\s*$#i', '', $text));
            $items .= '<li style="margin:0 0 6px;">' . $text . self::render((array) $item['innerBlocks'], self::WIDTH) . '</li>';
        }
        if ($items === '') {
            return '';
        }
        $tag = !empty($block['attrs']['ordered']) ? 'ol' : 'ul';

        return "<{$tag} style=\"margin:0 0 16px;padding:0 0 0 24px;font-size:16px;line-height:1.7;color:" . NewsletterMail::COLORS['text'] . ";\">{$items}</{$tag}>";
    }

    /**
     * Foto aus der Mediathek als JPEG-Fassung, zentriert, höchstens so breit wie der Platz. Fremde Bilder fallen weg.
     *
     * @param array<string, mixed> $attrs
     */
    private static function image(array $attrs, string $markup, int $width): string
    {
        $attachment_id = (int) ($attrs['id'] ?? 0);
        if (!$attachment_id || !wp_attachment_is_image($attachment_id)) {
            return '';
        }
        $url = ImageProcessor::mail_url($attachment_id, 'full');
        if ($url === '') {
            return '';
        }

        $chosen = (int) ($attrs['width'] ?? 0);
        $size   = $chosen > 0 ? min($chosen, $width) : $width;
        $alt    = preg_match('/<img\b[^>]*\salt="([^"]*)"/i', $markup, $found) ? html_entity_decode($found[1], ENT_QUOTES, 'UTF-8') : '';
        $image  = '<img src="' . esc_url($url) . '" alt="' . esc_attr($alt) . '" width="' . $size . '"'
            . ' style="display:block;width:' . $size . 'px;max-width:100%;height:auto;border:0;margin:0 auto;">';
        if (preg_match('/<a\b[^>]*\shref="([^"]+)"/i', $markup, $link)) {
            $image = '<a href="' . esc_url(html_entity_decode($link[1], ENT_QUOTES, 'UTF-8')) . '">' . $image . '</a>';
        }
        $caption = preg_match('#<figcaption\b[^>]*>(.*?)</figcaption>#is', $markup, $found) ? self::inline($found[1]) : '';
        if (trim(wp_strip_all_tags($caption)) !== '') {
            $image .= '<p style="margin:8px 0 0;font-size:14px;line-height:1.5;color:' . NewsletterMail::COLORS['muted'] . ';">' . $caption . '</p>';
        }

        return '<div style="margin:8px 0 16px;text-align:center;">' . $image . '</div>';
    }

    /**
     * Knöpfe nebeneinander, ausgerichtet wie im Editor. Knöpfe ohne Adresse fallen weg.
     *
     * @param array<string, mixed> $block
     */
    private static function buttons(array $block): string
    {
        $cells = [];
        foreach ((array) $block['innerBlocks'] as $button) {
            $markup = (string) ($button['innerHTML'] ?? '');
            if (($button['blockName'] ?? '') !== 'core/button' || !preg_match('#<a\b[^>]*\shref="([^"]+)"[^>]*>(.*?)</a>#is', $markup, $link)) {
                continue;
            }
            $label = trim(html_entity_decode(wp_strip_all_tags($link[2]), ENT_QUOTES, 'UTF-8'));
            if ($label !== '') {
                $cells[] = NewsletterMail::button_cell(html_entity_decode($link[1], ENT_QUOTES, 'UTF-8'), $label);
            }
        }
        if ($cells === []) {
            return '';
        }
        $align = match ($block['attrs']['layout']['justifyContent'] ?? '') {
            'center' => 'center',
            'right'  => 'right',
            default  => 'left',
        };

        return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr><td align="' . $align . '" style="padding:8px 0 24px;">'
            . '<table role="presentation" cellpadding="0" cellspacing="0" border="0"><tr>'
            . implode('<td style="width:12px;font-size:1px;">&nbsp;</td>', $cells)
            . '</tr></table></td></tr></table>';
    }

    /**
     * @param array<string, mixed> $attrs
     */
    private static function spacer(array $attrs): string
    {
        $height = max(1, min(400, (int) ($attrs['height'] ?? 100)));

        return '<div style="height:' . $height . 'px;line-height:' . $height . 'px;font-size:1px;">&nbsp;</div>';
    }

    /**
     * Spalten als Tabelle. Auf dem Handy stehen sie untereinander, außer das ist im Editor abgeschaltet.
     *
     * @param array<string, mixed> $block
     */
    private static function columns(array $block, int $width): string
    {
        $columns = array_values(array_filter((array) $block['innerBlocks'], static fn(array $column): bool => ($column['blockName'] ?? '') === 'core/column'));
        if ($columns === []) {
            return '';
        }

        // Spalten ohne eigene Breite teilen sich den Rest
        $shares = array_map(static fn(array $column): float => self::share($column['attrs']['width'] ?? null, $width), $columns);
        $open   = count(array_filter($shares, static fn(float $share): bool => $share === 0.0));
        $rest   = max(0.0, 1 - array_sum($shares));
        $shares = array_map(static fn(float $share): float => $share === 0.0 ? ($open > 0 ? $rest / $open : 0.0) : $share, $shares);
        $total  = array_sum($shares) ?: 1.0;

        $stack = ($block['attrs']['isStackedOnMobile'] ?? true) !== false;
        $last  = count($columns) - 1;
        $cells = '';
        $empty = true;
        foreach ($columns as $index => $column) {
            $share   = $shares[$index] / $total;
            $left    = $index > 0 ? self::COLUMN_GAP / 2 : 0;
            $right   = $index < $last ? self::COLUMN_GAP / 2 : 0;
            $valign  = match ($column['attrs']['verticalAlignment'] ?? $block['attrs']['verticalAlignment'] ?? '') {
                'center' => 'middle',
                'bottom' => 'bottom',
                default  => 'top',
            };
            $content = self::render((array) $column['innerBlocks'], (int) floor($width * $share) - $left - $right);
            $empty   = $empty && $content === '';
            $cells  .= '<td' . ($stack ? ' class="nkp-mail-column"' : '') . ' width="' . round($share * 100, 2) . '%" valign="' . $valign . '" style="padding:0 ' . $right . 'px 0 ' . $left . 'px;">'
                . $content . '</td>';
        }
        if ($empty) {
            return '';
        }

        return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 8px;"><tr>' . $cells . '</tr></table>';
    }

    /**
     * Anteil einer Spalte aus ihrer Breite im Editor, z. B. „33.33%“ oder „200px“. 0 heißt ohne Angabe.
     */
    private static function share(mixed $value, int $width): float
    {
        if (!is_string($value) || !preg_match('/^(\d+(?:\.\d+)?)(%|px)$/', trim($value), $found)) {
            return 0.0;
        }
        $share = $found[2] === '%' ? (float) $found[1] / 100 : (float) $found[1] / $width;

        return max(0.05, min(1.0, $share));
    }

    /**
     * Inhalt eines Elements ohne das Element selbst, z. B. den Text eines Absatzes.
     */
    private static function unwrap(string $markup): string
    {
        return preg_match('#^\s*<([a-z0-9]+)\b[^>]*>(.*)</\1>\s*$#is', $markup, $found) ? $found[2] : $markup;
    }

    /**
     * Text mit fett, kursiv, durchgestrichen und Links in der Farbe des Shops.
     */
    private static function inline(string $html): string
    {
        $style = 'color:' . NewsletterMail::COLORS['link'] . ';font-weight:bold;font-style:italic;text-decoration:none;';
        // Offene Elemente würden in der Mail den Fuß mit dem Abmeldelink umschließen
        $html = force_balance_tags(wp_kses($html, self::INLINE));

        // Links nur auf Webseiten und Mailadressen, sonst bleibt der Text
        return trim((string) preg_replace_callback('#<a href="([^"]*)">(.*?)</a>#is', static fn(array $link): string => preg_match('#^(https?://|mailto:)#i', $link[1])
            ? '<a style="' . $style . '" href="' . $link[1] . '">' . $link[2] . '</a>'
            : $link[2], $html));
    }
}
