<?php

namespace App\Domain\Mail;

use League\CommonMark\CommonMarkConverter;

/**
 * Podstawia zmienne {{ nazwa.pole }} i zamienia Markdown na HTML.
 *
 * Wartości zmiennych pochodzą m.in. od klientów (nazwa maszyny, temat
 * zgłoszenia) — w treści escapujemy znaki Markdown i HTML, żeby nikt nie
 * wstrzyknął do maila linku ani znacznika. Treść szablonu (od administratora)
 * idzie przez Markdown z escapowaniem surowego HTML.
 */
final class TemplateRenderer
{
    private const VAR = '/\{\{\s*([A-Za-z0-9_.]+)\s*\}\}/';

    /** Temat: zwykły tekst, bez Markdown. */
    public static function subject(string $template, array $vars): string
    {
        $text = preg_replace_callback(self::VAR, fn ($m) => self::plain(data_get($vars, $m[1])), $template);

        return trim(preg_replace('/[\r\n]+/', ' ', (string) $text));
    }

    /** Treść: Markdown → HTML. Zmienne wewnątrz adresu linku nie są escapowane jak tekst. */
    public static function html(string $template, array $vars): string
    {
        // Najpierw adresy w linkach [tekst](adres) — tam podstawiamy URL (tylko http/https).
        $template = preg_replace_callback('/\]\(\s*'.substr(self::VAR, 1, -1).'\s*\)/', function ($m) use ($vars) {
            $url = self::plain(data_get($vars, $m[1]));

            return ']('.(preg_match('#^https?://[^\s()<>]+$#i', $url) ? $url : '#').')';
        }, $template);

        $markdown = preg_replace_callback(self::VAR, fn ($m) => self::escapeMarkdown(self::plain(data_get($vars, $m[1]))), $template);

        // Czysty CommonMark, bez rozszerzeń GFM: autolinki zrobiłyby klikalny link
        // z adresu wpisanego przez klienta (np. w temacie zgłoszenia).
        $converter = new CommonMarkConverter([
            'html_input' => 'escape',
            'allow_unsafe_links' => false,
            'renderer' => ['soft_break' => "<br>\n"],
        ]);

        return (string) $converter->convert((string) $markdown);
    }

    public static function text(string $html): string
    {
        $text = preg_replace(['/<br\s*\/?>/i', '/<\/(p|li|h\d)>/i', '/<li>/i'], ["\n", "\n\n", '- '], $html);

        return trim(html_entity_decode(strip_tags((string) $text), ENT_QUOTES | ENT_HTML5));
    }

    private static function plain(mixed $value): string
    {
        return match (true) {
            $value === null => '—',
            is_bool($value) => $value ? 'tak' : 'nie',
            is_scalar($value) || $value instanceof \Stringable => (string) $value,
            default => '—',
        };
    }

    private static function escapeMarkdown(string $value): string
    {
        $escaped = preg_replace('/([\\\\`*_{}\[\]()<>#+!|~])/', '\\\\$1', $value);

        // Wiodące „-”, „1.” albo „>” zrobiłyby z wartości listę lub cytat.
        return preg_replace('/^(\s*)(-|\d+\.)(\s)/m', '$1\\\\$2$3', (string) $escaped);
    }
}
