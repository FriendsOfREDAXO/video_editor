<?php

namespace FriendsOfRedaxo\VideoEditor;

use Redaxo\Core\Database\Sql;
use Redaxo\Core\MediaPool\Media;

use function date;

/**
 * Verknüpfungen zwischen einem Video und daraus entstandenen Medien (Tabelle rex_video_editor_link):
 *  - version  optimierte bzw. geschnittene Fassung
 *  - poster   Standbild als Poster
 */
final class Links
{
    public const string TABLE = 'rex_video_editor_link';

    public const string VERSION = 'version';
    public const string POSTER = 'poster';

    public static function add(string $source, string $target, string $kind, ?string $label = null, ?Preset $preset = null): void
    {
        $sql = Sql::factory()->setTable(self::TABLE);
        $sql->setValue('source', $source);
        $sql->setValue('target', $target);
        $sql->setValue('kind', $kind);
        $sql->setValue('label', $label);
        $sql->setValue('preset', $preset?->value);
        $sql->setValue('createdate', date('Y-m-d H:i:s'));
        $sql->insert();
    }

    /**
     * Verknüpfte Medien eines Videos (nur noch vorhandene).
     *
     * @return list<array{file: string, kind: string, label: ?string, preset: ?Preset, date: string}>
     */
    public static function of(string $source, ?string $kind = null): array
    {
        $rows = Sql::factory()->getArray(
            'SELECT target, kind, label, preset, createdate FROM ' . self::TABLE . ' WHERE source = ?' . (null !== $kind ? ' AND kind = ?' : '') . ' ORDER BY id DESC',
            null !== $kind ? [$source, $kind] : [$source],
        );
        $list = [];
        foreach ($rows as $row) {
            if (Media::get((string) $row['target'])) {
                $list[] = ['file' => (string) $row['target'], 'kind' => (string) $row['kind'], 'label' => $row['label'] ? (string) $row['label'] : null,
                    'preset' => Preset::tryFrom((string) $row['preset']), 'date' => (string) $row['createdate']];
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

    public static function forget(string $filename): void
    {
        Sql::factory()->setQuery('DELETE FROM ' . self::TABLE . ' WHERE source = ? OR target = ?', [$filename, $filename]);
    }
}
