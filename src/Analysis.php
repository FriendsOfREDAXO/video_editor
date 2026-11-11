<?php

namespace FriendsOfRedaxo\VideoEditor;

use Redaxo\Core\Translation\I18n;

use function fclose;
use function fopen;
use function fread;
use function fseek;
use function in_array;
use function is_array;
use function is_numeric;
use function max;
use function min;
use function round;
use function strlen;
use function strtolower;
use function unpack;

/**
 * Was steckt im Video? Auflösung, Codecs, Dauer, Datenrate, ob es fürs Web vorbereitet ist (faststart) – dazu eine
 * Bewertung (0–100) und Empfehlungen mit passender Vorlage.
 */
final readonly class Analysis
{
    /** @param list<array{text: string, preset: ?string}> $hints */
    public function __construct(
        public float $duration,
        public int $width,
        public int $height,
        public ?string $videoCodec,
        public ?string $audioCodec,
        public float $fps,
        /** Bit pro Sekunde, gesamt */
        public int $bitrate,
        public int $size,
        public ?bool $faststart,
        public int $score,
        public array $hints,
    ) {}

    public static function of(string $file, string $extension): ?self
    {
        $data = Ffmpeg::probe($file);
        if (null === $data) {
            return null;
        }
        $video = $audio = null;
        foreach ($data['streams'] ?? [] as $stream) {
            if (!is_array($stream)) {
                continue;
            }
            if ('video' === ($stream['codec_type'] ?? null) && null === $video && 'mjpeg' !== ($stream['codec_name'] ?? null)) {
                $video = $stream;
            } elseif ('audio' === ($stream['codec_type'] ?? null) && null === $audio) {
                $audio = $stream;
            }
        }
        $format = is_array($data['format'] ?? null) ? $data['format'] : [];
        $duration = (float) ($format['duration'] ?? $video['duration'] ?? 0);
        $size = (int) ($format['size'] ?? 0);
        $bitrate = (int) ($format['bit_rate'] ?? ($duration > 0 ? $size * 8 / $duration : 0));
        $fps = 0.0;
        if (is_array($video) && 1 === preg_match('~^(\d+)/(\d+)$~', (string) ($video['avg_frame_rate'] ?? ''), $m) && (int) $m[2] > 0) {
            $fps = round((int) $m[1] / (int) $m[2], 2);
        }
        $ext = strtolower($extension);
        $faststart = in_array($ext, ['mp4', 'm4v', 'mov'], true) ? self::moovFirst($file) : null;

        $analysis = new self(
            duration: $duration,
            width: (int) ($video['width'] ?? 0),
            height: (int) ($video['height'] ?? 0),
            videoCodec: isset($video['codec_name']) ? (string) $video['codec_name'] : null,
            audioCodec: isset($audio['codec_name']) ? (string) $audio['codec_name'] : null,
            fps: $fps,
            bitrate: $bitrate,
            size: $size,
            faststart: $faststart,
            score: 0,
            hints: [],
        );

        return $analysis->rated($ext);
    }

    /** Bewertung und Empfehlungen */
    private function rated(string $ext): self
    {
        $score = 100;
        $hints = [];
        $hint = static function (string $key, ?Preset $preset, int $minus) use (&$score, &$hints): void {
            $score -= $minus;
            $hints[] = ['text' => I18n::msg('video_editor_hint_' . $key), 'preset' => $preset?->value];
        };

        if (null === $this->videoCodec) {
            $hint('no_video', null, 100);
        } elseif (!in_array($this->videoCodec, ['h264', 'vp9', 'av1', 'hevc', 'vp8'], true)) {
            $hint('codec', Preset::Web1080, 35);
        } elseif ('hevc' === $this->videoCodec) {
            // H.265 spielen nicht alle Browser ab
            $hint('hevc', Preset::Web1080, 25);
        }
        // Hochformat (Smartphone): die lange bzw. kurze Seite zählt, nicht die Höhe – sonst gilt 1080 × 1920 als zu groß
        $long = max($this->width, $this->height);
        $short = min($this->width, $this->height);
        if ($long > Preset::longSide(1080)) {
            $hint('resolution', Preset::Web1080, 20);
        }
        $pixels = max(1, $this->width * $this->height);
        $bitsPerPixel = $this->fps > 0 ? $this->bitrate / ($pixels * $this->fps) : 0;
        if ($bitsPerPixel > 0.2 || ($short <= 1080 && $this->bitrate > 8_000_000)) {
            $hint('bitrate', $short > 720 ? Preset::Web1080 : Preset::Web720, 20);
        }
        if (false === $this->faststart) {
            $hint('faststart', Preset::Faststart, 15);
        }
        if (!in_array($ext, ['mp4', 'm4v', 'webm'], true)) {
            $hint('container', Preset::Web1080, 15);
        }

        return new self($this->duration, $this->width, $this->height, $this->videoCodec, $this->audioCodec, $this->fps,
            $this->bitrate, $this->size, $this->faststart, max(0, min(100, $score)), $hints);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'duration' => round($this->duration, 2),
            'width' => $this->width,
            'height' => $this->height,
            'videoCodec' => $this->videoCodec,
            'audioCodec' => $this->audioCodec,
            'fps' => $this->fps,
            'bitrate' => $this->bitrate,
            'size' => $this->size,
            'faststart' => $this->faststart,
            'score' => $this->score,
            'hints' => $this->hints,
        ];
    }

    /** Steht der „moov“-Block vor den Daten („mdat“)? Dann kann der Browser sofort abspielen. */
    private static function moovFirst(string $file): ?bool
    {
        $handle = @fopen($file, 'r');
        if (false === $handle) {
            return null;
        }
        $offset = 0;
        try {
            for ($i = 0; $i < 64; ++$i) {
                if (0 !== fseek($handle, $offset)) {
                    return null;
                }
                $header = fread($handle, 16);
                if (false === $header || strlen($header) < 8) {
                    return null;
                }
                $size = unpack('N', $header)[1] ?? 0;
                $type = substr($header, 4, 4);
                if ('moov' === $type) {
                    return true;
                }
                if ('mdat' === $type) {
                    return false;
                }
                if (1 === $size && strlen($header) >= 16) {
                    $high = unpack('J', substr($header, 8, 8))[1] ?? 0;
                    $size = is_numeric($high) ? (int) $high : 0;
                }
                if ($size < 8) {
                    return null;
                }
                $offset += $size;
            }
        } finally {
            fclose($handle);
        }

        return null;
    }
}
