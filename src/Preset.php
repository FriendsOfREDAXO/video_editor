<?php

namespace FriendsOfRedaxo\VideoEditor;

use Redaxo\Core\Translation\I18n;

use function in_array;
use function strtolower;

/**
 * Feste Vorlagen für ffmpeg – keine frei eingegebenen Befehle. Alle laufen auch mit ffmpeg 4.4.
 */
enum Preset: string
{
    case Web1080 = 'web1080';
    case Web720 = 'web720';
    case Web480 = 'web480';
    case Archive = 'archive';
    case Webm = 'webm';
    case Faststart = 'faststart';
    case Mute = 'mute';
    case Loudnorm = 'loudnorm';

    public function label(): string
    {
        return I18n::msg('video_editor_preset_' . $this->value);
    }

    public function description(): string
    {
        return I18n::msg('video_editor_preset_' . $this->value . '_text');
    }

    /** Dateiendung des Ergebnisses; null = wie die Quelle */
    public function extension(): ?string
    {
        return match ($this) {
            self::Webm => 'webm',
            self::Faststart, self::Mute, self::Loudnorm => null,
            default => 'mp4',
        };
    }

    /** Höhe in Pixeln bei den Web-Fassungen (MP4), sonst null */
    public function height(): ?int
    {
        return match ($this) {
            self::Web1080 => 1080,
            self::Web720 => 720,
            self::Web480 => 480,
            default => null,
        };
    }

    /** Für diese Quelle verwendbar? (Faststart nur für MP4-Container) */
    public function supports(string $sourceExtension): bool
    {
        return match ($this) {
            self::Faststart => in_array(strtolower($sourceExtension), ['mp4', 'm4v', 'mov'], true),
            self::Mute, self::Loudnorm => in_array(strtolower($sourceExtension), ['mp4', 'm4v', 'mov', 'webm', 'mkv'], true),
            default => true,
        };
    }

    /** Ergebnis wird neu kodiert (dauert) oder nur umgepackt (schnell)? */
    public function reencodes(): bool
    {
        return !in_array($this, [self::Faststart, self::Mute], true);
    }

    /**
     * Skalierungsfilter für „<höhe>p“: Das Video passt danach in ein Quadrat mit der langen Seite eines 16:9-Bildes
     * dieser Höhe (1080 → 1920). Querformat wird höchstens 1920 × 1080, Hochformat höchstens 1080 × 1920 – bisher
     * wurde Hochformat über die Höhe begrenzt und schrumpfte auf 607 × 1080. Kleinere Videos bleiben unverändert
     * („min(iw, …)“), force_divisible_by=2 hält die Maße gerade (libx264/4:2:0).
     */
    public static function scale(int $height): string
    {
        $long = self::longSide($height);

        return "scale='min(iw," . $long . ")':'min(ih," . $long . ")':force_original_aspect_ratio=decrease:force_divisible_by=2:flags=lanczos";
    }

    /** Lange Seite eines 16:9-Bildes mit dieser Höhe, gerade (1080 → 1920, 480 → 854) */
    public static function longSide(int $height): int
    {
        return (int) (2 * round($height * 16 / 9 / 2));
    }

    /**
     * Argumente zwischen Eingabe und Ausgabe.
     *
     * @return list<string>
     */
    public function args(): array
    {
        $h264 = static fn (int $height, int $crf, string $speed, string $audio) => [
            '-map', '0:v:0', '-map', '0:a:0?',
            '-vf', self::scale($height) . ',format=yuv420p',
            '-c:v', 'libx264', '-preset', $speed, '-crf', (string) $crf, '-profile:v', 'high',
            '-c:a', 'aac', '-b:a', $audio, '-ac', '2',
            '-movflags', '+faststart',
        ];

        return match ($this) {
            self::Web1080 => $h264(1080, 23, 'medium', '128k'),
            self::Web720 => $h264(720, 24, 'medium', '128k'),
            self::Web480 => $h264(480, 25, 'medium', '96k'),
            self::Archive => [
                '-map', '0:v:0', '-map', '0:a:0?',
                '-vf', 'format=yuv420p',
                '-c:v', 'libx264', '-preset', 'slow', '-crf', '18', '-profile:v', 'high',
                '-c:a', 'aac', '-b:a', '192k',
                '-movflags', '+faststart',
            ],
            self::Webm => [
                '-map', '0:v:0', '-map', '0:a:0?',
                '-vf', self::scale(1080) . ',format=yuv420p',
                '-c:v', 'libvpx-vp9', '-crf', '33', '-b:v', '0', '-row-mt', '1', '-deadline', 'good', '-cpu-used', '2',
                '-c:a', 'libopus', '-b:a', '96k',
            ],
            self::Faststart => ['-map', '0', '-c', 'copy', '-movflags', '+faststart'],
            self::Mute => ['-map', '0:v', '-c', 'copy', '-an', '-movflags', '+faststart'],
            self::Loudnorm => [
                '-map', '0:v:0', '-map', '0:a:0',
                '-c:v', 'copy',
                '-af', 'loudnorm=I=-16:TP=-1.5:LRA=11',
                '-c:a', 'aac', '-b:a', '160k',
                '-movflags', '+faststart',
            ],
        };
    }
}
