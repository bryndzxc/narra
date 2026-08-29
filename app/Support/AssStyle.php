<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * Builds the ASS header blocks from a configured style preset.
 *
 * Separated from the writer so the colour arithmetic — the part with a classic
 * silent bug in it — can be tested without producing a subtitle file.
 */
final class AssStyle
{
    public const STYLE_NAME = 'Karaoke';

    /**
     * @param  array<string, mixed>  $preset
     */
    public function __construct(
        private readonly array $preset,
        private readonly int $playResX,
        private readonly int $playResY,
    ) {}

    public static function fromConfig(?string $name = null): self
    {
        $name ??= (string) config('render.subtitles.preset');
        $preset = config("render.subtitles.presets.{$name}");

        if (! is_array($preset)) {
            throw new InvalidArgumentException("Unknown subtitle preset: {$name}");
        }

        return new self(
            $preset,
            (int) config('render.video.width'),
            (int) config('render.video.height'),
        );
    }

    /**
     * RGB hex to an ASS colour literal.
     *
     * ASS is &HAABBGGRR — alpha first, then BLUE, GREEN, RED. The byte order is
     * reversed relative to hex RGB, and alpha is inverted (00 is opaque). Both
     * are easy to get wrong and neither fails loudly: you just get the wrong
     * colour, or an invisible subtitle.
     */
    public static function colour(string $hex, int $alpha = 0): string
    {
        $hex = ltrim(trim($hex), '#');

        if (preg_match('/^[0-9a-fA-F]{6}$/', $hex) !== 1) {
            throw new InvalidArgumentException("Expected a 6-digit RGB hex colour, got '{$hex}'.");
        }

        if ($alpha < 0 || $alpha > 255) {
            throw new InvalidArgumentException("Alpha must be 0-255, got {$alpha}.");
        }

        return sprintf(
            '&H%02X%02X%02X%02X',
            $alpha,
            hexdec(substr($hex, 4, 2)), // blue
            hexdec(substr($hex, 2, 2)), // green
            hexdec(substr($hex, 0, 2)), // red
        );
    }

    public function scriptInfo(string $title): string
    {
        return implode("\n", [
            '[Script Info]',
            'Title: '.str_replace(["\n", "\r"], ' ', $title),
            'ScriptType: v4.00+',
            'WrapStyle: 0',
            'ScaledBorderAndShadow: yes',
            'YCbCr Matrix: TV.709',
            'PlayResX: '.$this->playResX,
            'PlayResY: '.$this->playResY,
            '',
        ])."\n";
    }

    public function stylesBlock(): string
    {
        $p = $this->preset;

        $fields = [
            self::STYLE_NAME,
            (string) $p['font'],
            (string) (int) $p['font_size'],
            // Swept-to colour. See the note in config/render.php.
            self::colour((string) $p['highlight_colour']),
            // Resting colour.
            self::colour((string) $p['primary_colour']),
            self::colour((string) $p['outline_colour']),
            self::colour((string) $p['back_colour']),
            ! empty($p['bold']) ? '-1' : '0',
            '0', '0', '0',          // italic, underline, strikeout
            '100', '100',           // scale x, y
            '0', '0',               // spacing, angle
            '1',                    // border style: outline + shadow
            (string) (int) $p['outline'],
            (string) (int) $p['shadow'],
            (string) (int) $p['alignment'],
            (string) (int) $p['margin_l'],
            (string) (int) $p['margin_r'],
            (string) (int) $p['margin_v'],
            '1',                    // encoding
        ];

        return implode("\n", [
            '[V4+ Styles]',
            'Format: Name, Fontname, Fontsize, PrimaryColour, SecondaryColour, OutlineColour, '
                .'BackColour, Bold, Italic, Underline, StrikeOut, ScaleX, ScaleY, Spacing, Angle, '
                .'BorderStyle, Outline, Shadow, Alignment, MarginL, MarginR, MarginV, Encoding',
            'Style: '.implode(',', $fields),
            '',
        ])."\n";
    }

    public function eventsHeader(): string
    {
        return implode("\n", [
            '[Events]',
            'Format: Layer, Start, End, Style, Name, MarginL, MarginR, MarginV, Effect, Text',
        ])."\n";
    }

    /**
     * Centiseconds to ASS's H:MM:SS.cc.
     */
    public static function timestamp(int $centiseconds): string
    {
        if ($centiseconds < 0) {
            throw new InvalidArgumentException("Negative timestamp: {$centiseconds}");
        }

        $hours = intdiv($centiseconds, 360000);
        $minutes = intdiv($centiseconds % 360000, 6000);
        $seconds = intdiv($centiseconds % 6000, 100);

        return sprintf('%d:%02d:%02d.%02d', $hours, $minutes, $seconds, $centiseconds % 100);
    }
}
