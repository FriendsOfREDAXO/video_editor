<?php

namespace FriendsOfRedaxo\VideoEditor\Tests;

use FriendsOfRedaxo\VideoEditor\Subtitles;
use PHPUnit\Framework\TestCase;

/** @internal */
final class SubtitlesTest extends TestCase
{
    public function testParsesSrtAndWritesVtt(): void
    {
        $srt = "\xEF\xBB\xBF1\r\n00:00:01,500 --> 00:00:03,250\r\nHallo\r\nWelt\r\n\r\n2\r\n00:01:02,000 --> 00:01:04,000\r\nZweiter\r\n";
        $cues = Subtitles::parse($srt);

        self::assertNotNull($cues);
        self::assertCount(2, $cues);
        self::assertSame(1500, $cues[0]['start']);
        self::assertSame(3250, $cues[0]['end']);
        self::assertSame("Hallo\nWelt", $cues[0]['text']);
        self::assertSame(62000, $cues[1]['start']);
        self::assertSame(
            "WEBVTT\n\n1\n00:00:01.500 --> 00:00:03.250\nHallo\nWelt\n\n2\n00:01:02.000 --> 00:01:04.000\nZweiter\n",
            Subtitles::toVtt($cues),
        );
    }

    public function testParsesVttWithHeaderNotesAndSettings(): void
    {
        $vtt = "WEBVTT - Titel\nKind: captions\n\nNOTE Kommentar\n\nSTYLE\n::cue { color: red }\n\nid-1\n00:02.000 --> 00:04.000 align:start position:10% evil:1\n<v Anna>Hallo</v>\n";
        $cues = Subtitles::parse($vtt);

        self::assertNotNull($cues);
        self::assertCount(1, $cues);
        self::assertSame(2000, $cues[0]['start']);
        self::assertSame('align:start position:10%', $cues[0]['settings']);
        self::assertSame('<v Anna>Hallo</v>', $cues[0]['text']);
    }

    public function testRejectsNonSubtitles(): void
    {
        self::assertNull(Subtitles::parse('Das ist einfach Text.'));
        self::assertNull(Subtitles::parse("1\n00:00:01,000 --> 00:00:02,000\n\0binär"));
    }

    public function testCleansDangerousMarkup(): void
    {
        $text = Subtitles::cleanText('<script>alert(1)</script><font color="red">Rot</font> <b>fett <i>kursiv</b> & <img src=x onerror=alert(1)> --> Ende');

        self::assertStringNotContainsString('script', $text);
        self::assertStringNotContainsString('font', $text);
        self::assertStringNotContainsString('img', $text);
        self::assertStringNotContainsString('-->', $text);
        self::assertStringContainsString('&amp;', $text);
        // offene Tags werden geschlossen
        self::assertSame(1, substr_count($text, '</i>'));
    }

    public function testCleanSortsAndDropsEmptyCues(): void
    {
        $cues = Subtitles::clean([
            ['start' => 5000, 'end' => 6000, 'text' => 'B'],
            ['start' => 1000, 'end' => 500, 'text' => 'A'],
            ['start' => 2000, 'end' => 3000, 'text' => '   '],
            ['start' => 'x', 'end' => 1, 'text' => 'ungültig'],
        ]);

        self::assertCount(2, $cues);
        self::assertSame('A', $cues[0]['text']);
        // Ende vor dem Anfang: eine Sekunde Dauer
        self::assertSame(2000, $cues[0]['end']);
    }

    public function testRemapAfterCut(): void
    {
        $cues = [
            ['start' => 400, 'end' => 2600, 'text' => 'vorne', 'settings' => ''],
            ['start' => 4800, 'end' => 6000, 'text' => 'angeschnitten', 'settings' => ''],
            ['start' => 5600, 'end' => 8200, 'text' => 'weg', 'settings' => ''],
            ['start' => 8600, 'end' => 11800, 'text' => 'hinten', 'settings' => ''],
            ['start' => 8300, 'end' => 8500, 'text' => 'zu kurz', 'settings' => ''],
        ];
        $out = Subtitles::remap($cues, [[0.0, 5.2], [8.4, 12.97]]);

        self::assertSame(['vorne', 'angeschnitten', 'hinten'], array_column($out, 'text'));
        self::assertSame([4800, 5200], [$out[1]['start'], $out[1]['end']]);
        self::assertSame([5400, 8600], [$out[2]['start'], $out[2]['end']]);
    }
}
