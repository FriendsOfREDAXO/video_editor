<?php

namespace FriendsOfRedaxo\VideoEditor;

use Redaxo\Core\Exception\UserMessageException;
use Redaxo\Core\Filesystem\File;
use Redaxo\Core\Translation\I18n;

use function array_map;
use function array_unique;
use function array_values;
use function count;
use function explode;
use function implode;
use function in_array;
use function intdiv;
use function is_array;
use function is_numeric;
use function json_decode;
use function max;
use function min;
use function pathinfo;
use function round;
use function sort;
use function sprintf;
use function str_contains;
use function str_replace;
use function strtolower;
use function usort;

use const PATHINFO_EXTENSION;

/**
 * Schneiden: Abschnitte, die erhalten bleiben, werden aneinandergesetzt.
 *
 *  - schnell: ohne Neukodierung (stream copy) – sofort fertig, Schnitt am nächsten Keyframe davor
 *  - genau:   bildgenau, dafür neu kodiert (H.264/AAC bzw. VP9/Opus bei WebM)
 */
final class Cut
{
    /** kürzester Abschnitt in Sekunden */
    public const float MIN = 0.1;

    /**
     * Abschnitte aus der Anfrage prüfen: innerhalb der Dauer, sortiert, überlappende zusammengefasst.
     *
     * @throws UserMessageException
     * @return list<array{float, float}>
     */
    public static function segments(string $json, float $duration): array
    {
        $raw = json_decode($json, true);
        if (!is_array($raw)) {
            throw new UserMessageException(I18n::msg('video_editor_cut_invalid'));
        }
        $list = [];
        foreach ($raw as $pair) {
            if (!is_array($pair) || !isset($pair[0], $pair[1]) || !is_numeric($pair[0]) || !is_numeric($pair[1])) {
                throw new UserMessageException(I18n::msg('video_editor_cut_invalid'));
            }
            $start = max(0.0, min($duration, round((float) $pair[0], 3)));
            $end = max(0.0, min($duration, round((float) $pair[1], 3)));
            if ($end - $start >= self::MIN) {
                $list[] = [$start, $end];
            }
        }
        usort($list, static fn (array $a, array $b) => $a[0] <=> $b[0]);
        $merged = [];
        foreach ($list as [$start, $end]) {
            $last = count($merged) - 1;
            if ($last >= 0 && $start <= $merged[$last][1]) {
                $merged[$last][1] = max($merged[$last][1], $end);
            } else {
                $merged[] = [$start, $end];
            }
        }
        if ([] === $merged) {
            throw new UserMessageException(I18n::msg('video_editor_cut_empty'));
        }
        if (1 === count($merged) && $merged[0][0] < self::MIN && $merged[0][1] > $duration - self::MIN) {
            throw new UserMessageException(I18n::msg('video_editor_cut_nothing'));
        }

        return $merged;
    }

    /**
     * Zeitpunkte der Schlüsselbilder (aus den Paketen, ohne zu dekodieren – auch bei langen Videos schnell).
     *
     * @return list<float>
     */
    public static function keyframes(string $file): array
    {
        $ffprobe = Ffmpeg::binary('ffprobe');
        if (null === $ffprobe) {
            return [];
        }
        $result = Ffmpeg::run([$ffprobe, '-v', 'error', '-select_streams', 'v:0', '-show_entries', 'packet=pts_time,flags', '-of', 'csv=p=0', $file], 60);
        $times = [];
        foreach (explode("\n", $result['out']) as $line) {
            $parts = explode(',', trim($line));
            if (isset($parts[1]) && str_contains($parts[1], 'K') && is_numeric($parts[0])) {
                $times[] = round((float) $parts[0], 3);
            }
        }
        sort($times);

        return array_values(array_unique($times));
    }

    /**
     * Schnell schneiden heißt: ab dem Schlüsselbild davor. Anfänge entsprechend vorziehen, Überlappungen zusammenfassen.
     *
     * @param list<array{float, float}> $segments
     * @param list<float> $keyframes
     * @return list<array{float, float}>
     */
    public static function snap(array $segments, array $keyframes): array
    {
        if ([] === $keyframes) {
            return $segments;
        }
        $snapped = [];
        foreach ($segments as [$start, $end]) {
            $key = 0.0;
            foreach ($keyframes as $k) {
                if ($k > $start + 0.001) {
                    break;
                }
                $key = $k;
            }
            $last = count($snapped) - 1;
            if ($last >= 0 && $key <= $snapped[$last][1]) {
                $snapped[$last][1] = max($snapped[$last][1], $end);
            } else {
                $snapped[] = [$key, $end];
            }
        }

        return $snapped;
    }

    /** @param list<array{float, float}> $segments */
    public static function length(array $segments): float
    {
        $sum = 0.0;
        foreach ($segments as [$start, $end]) {
            $sum += $end - $start;
        }

        return $sum;
    }

    /** Endung des Ergebnisses: schnell wie das Original, genau als MP4 bzw. WebM */
    public static function extension(string $source, bool $exact): string
    {
        $source = strtolower($source);

        return !$exact || in_array($source, ['mp4', 'm4v', 'mov', 'webm'], true) ? $source : 'mp4';
    }

    /**
     * Bezeichnung, z. B. „Schnitt · 0:42“.
     *
     * @param list<array{float, float}> $segments
     */
    public static function label(array $segments): string
    {
        return I18n::msg('video_editor_cut_label', self::clock(self::length($segments)));
    }

    /**
     * Arbeitsschritte für den Worker.
     *
     * @param list<array{float, float}> $segments
     * @return list<array{args: list<string>, duration: float}>
     */
    public static function steps(string $ffmpeg, string $input, string $output, array $segments, bool $exact, bool $audio, string $tmpDir): array
    {
        $ext = strtolower(pathinfo($output, PATHINFO_EXTENSION));
        $mp4 = in_array($ext, ['mp4', 'm4v', 'mov'], true) ? ['-movflags', '+faststart'] : [];
        $head = [$ffmpeg, '-hide_banner', '-nostdin', '-y'];

        if ($exact) {
            // ein Durchgang: trim/atrim je Abschnitt, dann concat – bildgenau
            $filter = [];
            $pads = '';
            foreach ($segments as $i => [$start, $end]) {
                $filter[] = sprintf('[0:v:0]trim=start=%.3F:end=%.3F,setpts=PTS-STARTPTS[v%d]', $start, $end, $i);
                $pads .= '[v' . $i . ']';
                if ($audio) {
                    $filter[] = sprintf('[0:a:0]atrim=start=%.3F:end=%.3F,asetpts=PTS-STARTPTS[a%d]', $start, $end, $i);
                    $pads .= '[a' . $i . ']';
                }
            }
            $filter[] = $pads . 'concat=n=' . count($segments) . ':v=1:a=' . ($audio ? 1 : 0) . '[v]' . ($audio ? '[a]' : '');
            $codec = 'webm' === $ext
                ? ['-c:v', 'libvpx-vp9', '-crf', '32', '-b:v', '0', '-row-mt', '1', '-deadline', 'good', '-cpu-used', '2', ...($audio ? ['-c:a', 'libopus', '-b:a', '128k'] : [])]
                : ['-c:v', 'libx264', '-preset', 'medium', '-crf', '20', '-pix_fmt', 'yuv420p', ...($audio ? ['-c:a', 'aac', '-b:a', '160k'] : [])];

            return [[
                'args' => [...$head, '-i', $input, '-filter_complex', implode(';', $filter), '-map', '[v]', ...($audio ? ['-map', '[a]'] : []),
                    ...$codec, ...$mp4, '-progress', 'pipe:1', '-nostats', $output],
                'duration' => self::length($segments),
            ]];
        }

        // schnell: jeden Abschnitt ohne Neukodierung herauskopieren (ab dem Schlüsselbild), dann verlustfrei aneinanderhängen
        $segments = self::snap($segments, self::keyframes($input));
        $copy = static fn (float $start, float $end, string $target): array => [
            'args' => [...$head, '-ss', sprintf('%.3F', $start), '-i', $input, '-t', sprintf('%.3F', $end - $start),
                '-map', '0:v:0', '-map', '0:a:0?', '-c', 'copy', '-avoid_negative_ts', 'make_zero', ...$mp4, '-progress', 'pipe:1', '-nostats', $target],
            'duration' => $end - $start,
        ];
        if (1 === count($segments)) {
            return [$copy($segments[0][0], $segments[0][1], $output)];
        }
        $steps = [];
        $parts = [];
        foreach ($segments as $i => [$start, $end]) {
            $parts[] = $part = $tmpDir . '/part-' . $i . '.' . $ext;
            $steps[] = $copy($start, $end, $part);
        }
        $list = $tmpDir . '/parts.txt';
        File::put($list, implode("\n", array_map(static fn (string $p) => "file '" . str_replace("'", "'\\''", $p) . "'", $parts)) . "\n");
        $steps[] = [
            'args' => [...$head, '-f', 'concat', '-safe', '0', '-i', $list, '-map', '0', '-c', 'copy', ...$mp4, '-progress', 'pipe:1', '-nostats', $output],
            'duration' => self::length($segments),
        ];

        return $steps;
    }

    public static function clock(float $seconds): string
    {
        $s = (int) round($seconds);

        return $s >= 3600 ? sprintf('%d:%02d:%02d', intdiv($s, 3600), intdiv($s % 3600, 60), $s % 60) : sprintf('%d:%02d', intdiv($s, 60), $s % 60);
    }
}
