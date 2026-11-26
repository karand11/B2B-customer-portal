<?php

namespace App\Support;

/**
 * Display-only cleaning of text that goes into the invoice PDF (the database keeps the original).
 * Removes control and format characters (bidi overrides, zero-width, BOM, soft hyphen), replaces emoji and other
 * supplementary-plane characters and invalid UTF-8 with U+FFFD (dompdf mishandles them and DejaVu has no emoji),
 * and breaks long unbroken runs with a space (dompdf only wraps at spaces and hyphens).
 */
class PdfText
{
    public const MAX_RUN = 28;

    /** One line: every line break becomes a space. */
    public static function line(?string $text, int $maxRun = self::MAX_RUN): string
    {
        return trim(preg_replace('/\s+/u', ' ', self::clean($text, $maxRun)) ?? '');
    }

    /**
     * Lines of a multi-line text: no empty lines.
     *
     * @return list<string>
     */
    public static function lines(?string $text, int $maxRun = self::MAX_RUN): array
    {
        return array_values(array_filter(array_map('trim', explode("\n", self::clean($text, $maxRun))), fn ($l) => $l !== ''));
    }

    /** Cleaned text; line breaks (LF) are kept. */
    public static function clean(?string $text, int $maxRun = self::MAX_RUN): string
    {
        $text = self::utf8($text ?? '');
        $text = str_replace(["\r\n", "\r", "\u{2028}", "\u{2029}", "\u{85}"], "\n", $text);
        $text = str_replace("\t", ' ', $text);
        $text = preg_replace('/[^\P{Cc}\n]|\p{Cf}/u', '', $text) ?? '';
        $text = preg_replace('/[\x{10000}-\x{10FFFF}]/u', "\u{FFFD}", $text) ?? '';

        return preg_replace_callback(
            '/[^\s]{'.($maxRun + 1).',}/u',
            fn (array $m) => implode(' ', mb_str_split($m[0], $maxRun)),
            $text
        ) ?? '';
    }

    private static function utf8(string $text): string
    {
        if (mb_check_encoding($text, 'UTF-8')) {
            return $text;
        }

        $previous = mb_substitute_character();
        mb_substitute_character(0xFFFD);
        $fixed = mb_convert_encoding($text, 'UTF-8', 'UTF-8');
        mb_substitute_character($previous);

        return $fixed;
    }
}
