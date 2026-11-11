<?php

namespace FriendsOfRedaxo\VideoEditor\Tests;

use FriendsOfRedaxo\VideoEditor\Preset;
use PHPUnit\Framework\TestCase;

use function implode;

/** @internal */
final class PresetTest extends TestCase
{
    public function testWebPresetsLimitTheLongSideAndNeverUpscale(): void
    {
        $args = implode(' ', Preset::Web1080->args());
        self::assertStringContainsString("scale='min(iw,1920)':'min(ih,1920)':force_original_aspect_ratio=decrease", $args);
        self::assertStringContainsString('format=yuv420p', $args);
        self::assertStringContainsString('+faststart', $args);

        self::assertStringContainsString("'min(iw,1280)':'min(ih,1280)'", implode(' ', Preset::Web720->args()));
        self::assertStringContainsString("'min(iw,854)':'min(ih,854)'", implode(' ', Preset::Web480->args()));
        self::assertStringContainsString("'min(iw,1920)':'min(ih,1920)'", implode(' ', Preset::Webm->args()));
    }

    public function testLongSide(): void
    {
        self::assertSame(1920, Preset::longSide(1080));
        self::assertSame(1280, Preset::longSide(720));
        self::assertSame(854, Preset::longSide(480));
    }
}
