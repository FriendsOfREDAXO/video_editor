<?php

namespace FriendsOfRedaxo\VideoEditor;

use Redaxo\Core\Database\Sql;
use Redaxo\Core\MediaPool\Media;

use function date;

/**
 * Verknüpfungen zwischen einem Video und daraus entstandenen Medien (Tabelle rex_video_editor_link):
 *  - version  optimierte bzw. geschnittene Fassung
 *  - poster   Standbild als Poster
 *  - track    Untertitel/Kapitel (WebVTT) mit Sprache und Art
 */
final class Links
{
    public const string TABLE = 'rex_video_editor_link';

    public const string VERSION = 'version';
    public const string POSTER = 'poster';
    public const string TRACK = 'track';

    public static function add(string $source, string $target, string $kind, ?string $label = null, ?Preset $preset = null, ?string $lang = null, ?string $role = null): void
    {
        $sql = Sql::factory()->setTable(self::TABLE);
        $sql->setValue('source', $source);
        $sql->setValue('target', $target);
        $sql->setValue('kind', $kind);
        $sql->setValue('label', $label);
        $sql->setValue('preset', $preset?->value);
        $sql->setValue('lang', $lang);
        $sql->setValue('role', $role);
        $sql->setValue('createdate', date('Y-m-d H:i:s'));
        $sql->insert();
    }

    /**
     * Verknüpfte Medien eines Videos (nur noch vorhandene).
     *
     * @return list<array{file: string, kind: string, label: ?string, preset: ?Preset, lang: ?string, role: ?string, date: string}>
     */
    public static function of(string $source, ?string $kind = null): array
    {
        $rows = Sql::factory()->getArray(
            'SELECT target, kind, label, preset, lang, role, createdate FROM ' . self::TABLE . ' WHERE source = ?' . (null !== $kind ? ' AND kind = ?' : '') . ' ORDER BY id DESC',
            null !== $kind ? [$source, $kind] : [$source],
        );
        $list = [];
        foreach ($rows as $row) {
            if (Media::get((string) $row['target'])) {
                $list[] = ['file' => (string) $row['target'], 'kind' => (string) $row['kind'], 'label' => $row['label'] ? (string) $row['label'] : null,
                    'preset' => Preset::tryFrom((string) $row['preset']),
                    'lang' => $row['lang'] ? (string) $row['lang'] : null,
                    'role' => $row['role'] ? (string) $row['role'] : null,
                    'date' => (string) $row['createdate']];
            }
        }

        return $list;
    }

    /** Poster eines Videos (zuletzt gewähltes) */
    public static function poster(string $source): ?string
    {
        return self::of($source, self::POSTER)[0]['file'] ?? null;
    }

    /** Woraus ist dieses Medium entstanden? */
    public static function sourceOf(string $target): ?string
    {
        $rows = Sql::factory()->getArray('SELECT source FROM ' . self::TABLE . ' WHERE target = ? ORDER BY id DESC LIMIT 1', [$target]);

        return isset($rows[0]) ? (string) $rows[0]['source'] : null;
    }

    /** Sprache, Art und Bezeichnung einer Untertitel-Verknüpfung ändern */
    public static function updateTrack(string $source, string $target, string $lang, string $role, ?string $label): void
    {
        Sql::factory()->setQuery('UPDATE ' . self::TABLE . ' SET lang = ?, role = ?, label = ? WHERE source = ? AND target = ? AND kind = ?',
            [$lang, $role, $label, $source, $target, self::TRACK]);
    }

    /** Ist das Medium als Untertitel-Spur verknüpft? */
    public static function isTrack(string $target): bool
    {
        return [] !== Sql::factory()->getArray('SELECT id FROM ' . self::TABLE . ' WHERE target = ? AND kind = ? LIMIT 1', [$target, self::TRACK]);
    }

    /** Ist das Medium noch mit irgendeinem Video verknüpft? */
    public static function isLinked(string $target): bool
    {
        return [] !== Sql::factory()->getArray('SELECT id FROM ' . self::TABLE . ' WHERE target = ? LIMIT 1', [$target]);
    }

    /** Eine Verknüpfung lösen */
    public static function remove(string $source, string $target, string $kind): void
    {
        Sql::factory()->setQuery('DELETE FROM ' . self::TABLE . ' WHERE source = ? AND target = ? AND kind = ?', [$source, $target, $kind]);
    }

    public static function forget(string $filename): void
    {
        Sql::factory()->setQuery('DELETE FROM ' . self::TABLE . ' WHERE source = ? OR target = ?', [$filename, $filename]);
    }
}
