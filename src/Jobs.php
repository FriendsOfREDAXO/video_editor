<?php

namespace FriendsOfRedaxo\VideoEditor;

use Redaxo\Core\Database\Sql;
use Redaxo\Core\Security\User;

use function array_map;
use function date;
use function is_array;
use function json_decode;
use function json_encode;

use const JSON_THROW_ON_ERROR;

/**
 * Aufträge (Tabelle rex_video_editor_job): queued → running → done | failed | canceled.
 *
 * Die eigentliche Arbeit macht der {@see Worker}.
 */
final class Jobs
{
    public const string TABLE = 'rex_video_editor_job';

    public const string QUEUED = 'queued';
    public const string RUNNING = 'running';
    public const string DONE = 'done';
    public const string FAILED = 'failed';
    public const string CANCELED = 'canceled';

    /** @param array<string, mixed> $params */
    public static function add(string $filename, string $kind, array $params, ?User $user): int
    {
        $sql = Sql::factory()->setTable(self::TABLE);
        $sql->setValue('filename', $filename);
        $sql->setValue('kind', $kind);
        $sql->setValue('params', json_encode($params, JSON_THROW_ON_ERROR));
        $sql->setValue('status', self::QUEUED);
        $sql->setValue('progress', 0);
        $sql->setValue('cancel', 0);
        $sql->setValue('createuser', $user->login ?? 'console');
        $sql->setValue('createdate', date('Y-m-d H:i:s'));
        $sql->insert();

        return (int) $sql->getLastId();
    }

    /** @return array<string, mixed>|null */
    public static function get(int $id): ?array
    {
        $rows = Sql::factory()->getArray('SELECT * FROM ' . self::TABLE . ' WHERE id = ?', [$id]);

        return isset($rows[0]) ? self::row($rows[0]) : null;
    }

    /**
     * Letzte Aufträge eines Mediums, neueste zuerst.
     *
     * @return list<array<string, mixed>>
     */
    public static function forFile(string $filename, int $limit = 8): array
    {
        return array_map(self::row(...), Sql::factory()->getArray(
            'SELECT * FROM ' . self::TABLE . ' WHERE filename = ? ORDER BY id DESC LIMIT ' . $limit,
            [$filename],
        ));
    }

    /**
     * Offene Aufträge (laufend und wartend) aller Medien, in der Reihenfolge der Warteschlange.
     *
     * @return list<array{id: int, filename: string, status: string, progress: int}>
     */
    public static function pending(): array
    {
        return array_map(static fn (array $r) => ['id' => (int) $r['id'], 'filename' => (string) $r['filename'], 'status' => (string) $r['status'], 'progress' => (int) $r['progress']],
            Sql::factory()->getArray('SELECT id, filename, status, progress FROM ' . self::TABLE . ' WHERE status IN (?, ?) ORDER BY id', [self::RUNNING, self::QUEUED]));
    }

    /**
     * Nächster wartender Auftrag – und gleich als laufend markiert (atomar, falls zwei Worker starten).
     *
     * @return array<string, mixed>|null
     */
    public static function claim(): ?array
    {
        $rows = Sql::factory()->getArray('SELECT id FROM ' . self::TABLE . ' WHERE status = ? ORDER BY id LIMIT 1', [self::QUEUED]);
        if (!isset($rows[0])) {
            return null;
        }
        $id = (int) $rows[0]['id'];
        $sql = Sql::factory();
        $sql->setQuery('UPDATE ' . self::TABLE . ' SET status = ?, startdate = ?, progress = 0 WHERE id = ? AND status = ?',
            [self::RUNNING, date('Y-m-d H:i:s'), $id, self::QUEUED]);

        return $sql->getRows() > 0 ? self::get($id) : self::claim();
    }

    public static function progress(int $id, int $progress): void
    {
        Sql::factory()->setQuery('UPDATE ' . self::TABLE . ' SET progress = ? WHERE id = ?', [$progress, $id]);
    }

    public static function cancelRequested(int $id): bool
    {
        $rows = Sql::factory()->getArray('SELECT cancel FROM ' . self::TABLE . ' WHERE id = ?', [$id]);

        return (int) ($rows[0]['cancel'] ?? 1) > 0;
    }

    public static function finish(int $id, string $status, ?string $result = null, ?string $message = null, ?string $log = null): void
    {
        $sql = Sql::factory()->setTable(self::TABLE)->setWhere(['id' => $id]);
        $sql->setValue('status', $status);
        $sql->setValue('progress', self::DONE === $status ? 100 : 0);
        $sql->setValue('result', $result);
        $sql->setValue('message', $message);
        $sql->setValue('log', $log);
        $sql->setValue('finishdate', date('Y-m-d H:i:s'));
        $sql->update();
    }

    /** Abbrechen: wartende sofort, laufende über das Abbruch-Kennzeichen (der Worker beendet ffmpeg) */
    public static function cancel(int $id): void
    {
        Sql::factory()->setQuery('UPDATE ' . self::TABLE . ' SET status = ?, finishdate = ? WHERE id = ? AND status = ?',
            [self::CANCELED, date('Y-m-d H:i:s'), $id, self::QUEUED]);
        Sql::factory()->setQuery('UPDATE ' . self::TABLE . ' SET cancel = 1 WHERE id = ? AND status = ?', [$id, self::RUNNING]);
    }

    /** Fehlgeschlagenen oder abgebrochenen Auftrag erneut einreihen */
    public static function retry(int $id): void
    {
        Sql::factory()->setQuery('UPDATE ' . self::TABLE . ' SET status = ?, progress = 0, cancel = 0, message = NULL, result = NULL, startdate = NULL, finishdate = NULL WHERE id = ? AND status IN (?, ?)',
            [self::QUEUED, $id, self::FAILED, self::CANCELED]);
    }

    /** Hängengebliebene Aufträge (Worker abgestürzt) wieder freigeben bzw. aufräumen */
    public static function recover(int $staleSeconds): void
    {
        $limit = date('Y-m-d H:i:s', time() - $staleSeconds);
        Sql::factory()->setQuery('UPDATE ' . self::TABLE . ' SET status = ?, message = ? WHERE status = ? AND startdate < ?',
            [self::FAILED, 'Zeitüberschreitung – der Auftrag wurde nicht beendet.', self::RUNNING, $limit]);
        Sql::factory()->setQuery('DELETE FROM ' . self::TABLE . ' WHERE status IN (?, ?, ?) AND finishdate < ?',
            [self::DONE, self::FAILED, self::CANCELED, date('Y-m-d H:i:s', time() - 30 * 86_400)]);
    }

    public static function hasQueued(): bool
    {
        return [] !== Sql::factory()->getArray('SELECT id FROM ' . self::TABLE . ' WHERE status = ? LIMIT 1', [self::QUEUED]);
    }

    public static function deleteFor(string $filename): void
    {
        Sql::factory()->setQuery('DELETE FROM ' . self::TABLE . ' WHERE filename = ? AND status NOT IN (?, ?)', [$filename, self::QUEUED, self::RUNNING]);
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function row(array $row): array
    {
        $row['id'] = (int) $row['id'];
        $row['progress'] = (int) $row['progress'];
        $params = json_decode((string) $row['params'], true);
        $row['params'] = is_array($params) ? $params : [];

        return $row;
    }
}
