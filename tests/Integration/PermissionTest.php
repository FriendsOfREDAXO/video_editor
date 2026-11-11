<?php

namespace FriendsOfRedaxo\VideoEditor\Tests\Integration;

use FriendsOfRedaxo\VideoEditor\Api;
use FriendsOfRedaxo\VideoEditor\Ffmpeg;
use FriendsOfRedaxo\VideoEditor\Jobs;
use FriendsOfRedaxo\VideoEditor\VideoEditorAddon;
use Override;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Redaxo\Core\Database\Sql;
use Redaxo\Core\Filesystem\Dir;
use Redaxo\Core\Filesystem\Path;
use Redaxo\Core\Language\Language;
use Redaxo\Core\MediaPool\MediaCategoryHandler;
use Redaxo\Core\MediaPool\MediaHandler;
use Redaxo\Core\Security\User;
use Redaxo\Core\Translation\I18n;
use Symfony\Component\HttpFoundation\File\File as SymfonyFile;

/**
 * Wer darf was? Ansehen darf jeder mit Zugriff auf die Medienkategorie, bearbeiten nur mit video_editor[], das
 * Original ersetzen nur zusätzlich mit video_editor[replace] (Admins immer).
 *
 * Testdaten (zwei Medienkategorien mit je einem kurzen Video, drei Rollen, vier Benutzer) tragen „ve-test“ im Namen
 * und werden nach den Tests entfernt.
 *
 * @internal
 */
final class PermissionTest extends TestCase
{
    private static int $categoryA;
    private static int $categoryB;
    private static string $videoA;
    private static string $videoB;
    /** @var array<string, int> Benutzer-IDs: viewer, editor, replacer, admin */
    private static array $users = [];
    /** @var list<int> */
    private static array $roles = [];

    #[Override]
    public static function setUpBeforeClass(): void
    {
        if (!VIDEO_EDITOR_INTEGRATION || !Ffmpeg::available()) {
            return;
        }
        self::$categoryA = MediaCategoryHandler::add(['parent_id' => null, 'name' => 've-test Videos A'])->id;
        self::$categoryB = MediaCategoryHandler::add(['parent_id' => null, 'name' => 've-test Videos B'])->id;
        self::$videoA = self::video('ve-test-a', self::$categoryA);
        self::$videoB = self::video('ve-test-b', self::$categoryB);

        // alle Rollen nur mit Zugriff auf Kategorie A
        $viewer = self::role('ve-test ansehen', '', '');
        $editor = self::role('ve-test bearbeiten', '|video_editor[]|', '');
        $replacer = self::role('ve-test ersetzen', '|video_editor[]|', '|video_editor[replace]|');
        self::$users = [
            'viewer' => self::user('ve-test-ansehen', $viewer),
            'editor' => self::user('ve-test-bearbeiten', $editor),
            'replacer' => self::user('ve-test-ersetzen', $replacer),
            'admin' => self::user('ve-test-admin', null),
        ];
    }

    #[Override]
    public static function tearDownAfterClass(): void
    {
        if (!VIDEO_EDITOR_INTEGRATION || [] === self::$users) {
            return;
        }
        foreach ([self::$videoA, self::$videoB] as $file) {
            Sql::factory()->setQuery('DELETE FROM ' . Jobs::TABLE . ' WHERE filename = ?', [$file]);
            MediaHandler::delete($file);
        }
        MediaCategoryHandler::delete(self::$categoryA);
        MediaCategoryHandler::delete(self::$categoryB);
        foreach (self::$users as $id) {
            Sql::factory()->setQuery('DELETE FROM rex_user_role_assignment WHERE user_id = ?', [$id]);
            Sql::factory()->setQuery('DELETE FROM rex_user WHERE id = ?', [$id]);
            User::clearInstance($id);
        }
        foreach (self::$roles as $id) {
            Sql::factory()->setQuery('DELETE FROM rex_user_role WHERE id = ?', [$id]);
        }
        $_POST = [];
    }

    #[Override]
    protected function setUp(): void
    {
        if (!VIDEO_EDITOR_INTEGRATION) {
            self::markTestSkipped('Integrationstest: REDAXO_BOOTSTRAP ist nicht gesetzt.');
        }
        if (!Ffmpeg::available()) {
            self::markTestSkipped('ffmpeg ist nicht installiert.');
        }
    }

    /** @return iterable<string, array{string, bool, bool}> */
    public static function users(): iterable
    {
        yield 'ohne Recht' => ['viewer', false, false];
        yield 'video_editor[]' => ['editor', true, false];
        yield 'video_editor[] + replace' => ['replacer', true, true];
        yield 'Admin' => ['admin', true, true];
    }

    #[DataProvider('users')]
    public function testViewIsAllowedAndFlagsMatchPermissions(string $user, bool $canEdit, bool $canReplace): void
    {
        $data = self::call($user, 'info', self::$videoA);

        self::assertTrue($data['ok'], (string) ($data['error'] ?? ''));
        self::assertSame($canEdit, $data['canEdit']);
        self::assertSame($canReplace, $data['canReplace']);
        self::assertSame(VideoEditorAddon::allowed(self::account($user)), $canEdit);
    }

    public function testViewerMayNotEdit(): void
    {
        $actions = [
            'optimize' => ['preset' => 'faststart'], 'poster' => ['time' => '0.5'], 'cancel' => ['job' => '1'], 'retry' => ['job' => '1'],
            'cut' => ['segments' => '[[0,0.5]]'], 'frames' => [],
            'track_save' => ['lang' => 'de', 'cues' => '[{"start":0,"end":500,"text":"x"}]'], 'track_delete' => ['track' => 'x.vtt'],
            'track_parse' => ['content' => "WEBVTT\n\n00:00.000 --> 00:01.000\nx"],
        ];
        foreach ($actions as $action => $params) {
            $data = self::call('viewer', $action, self::$videoA, $params);
            self::assertFalse($data['ok'], $action);
            self::assertSame(I18n::msg('video_editor_no_permission'), $data['error'], $action);
        }
        self::assertSame([], Jobs::forFile(self::$videoA), 'ohne Recht entsteht kein Auftrag');
    }

    public function testEditorMayNotReplaceOriginal(): void
    {
        $data = self::call('editor', 'optimize', self::$videoA, ['preset' => 'faststart', 'mode' => 'replace']);

        self::assertFalse($data['ok']);
        self::assertSame(I18n::msg('video_editor_mode_replace_denied'), $data['error']);
        self::assertSame([], Jobs::forFile(self::$videoA));
    }

    public function testReplaceNeedsSinglePreset(): void
    {
        $data = self::call('replacer', 'optimize', self::$videoA, ['presets' => 'faststart,mute', 'mode' => 'replace']);

        self::assertFalse($data['ok']);
        self::assertSame(I18n::msg('video_editor_mode_replace_one'), $data['error']);
        $data = self::call('editor', 'optimize', self::$videoA, ['presets' => 'web720,unbekannt']);
        self::assertSame(I18n::msg('video_editor_preset_unknown'), $data['error']);
        self::assertSame([], Jobs::forFile(self::$videoA));
    }

    /** Die Warteschlange zählt alle offenen Aufträge, nennt aber nur Dateien aus erlaubten Kategorien */
    public function testQueueHidesForeignFiles(): void
    {
        $admin = self::account('admin');
        Jobs::add(self::$videoA, 'optimize', ['preset' => 'web480', 'mode' => 'version'], $admin);
        Jobs::add(self::$videoB, 'optimize', ['preset' => 'web480', 'mode' => 'version'], $admin);
        try {
            $data = self::call('viewer', 'queue', '');

            self::assertTrue($data['ok']);
            self::assertArrayHasKey(self::$videoA, $data['files']);
            self::assertArrayNotHasKey(self::$videoB, $data['files']);
            self::assertStringContainsString('2', $data['summary']);
            self::assertArrayHasKey(self::$videoB, self::call('admin', 'queue', '')['files']);
        } finally {
            Sql::factory()->setQuery('DELETE FROM ' . Jobs::TABLE . ' WHERE filename IN (?, ?)', [self::$videoA, self::$videoB]);
        }
    }

    public function testEditorPassesPermissionCheck(): void
    {
        // unbekannter Auftrag: die Rechteprüfung ist bestanden, es scheitert erst an der Auftragsnummer
        $data = self::call('editor', 'cancel', self::$videoA, ['job' => '999999999']);

        self::assertFalse($data['ok']);
        self::assertSame(I18n::msg('video_editor_not_found'), $data['error']);
    }

    /** @return iterable<string, array{string}> */
    public static function logins(): iterable
    {
        foreach (self::users() as $label => [$user]) {
            yield $label => [$user];
        }
    }

    /** Auch das Bearbeitungsrecht öffnet keine fremde Medienkategorie */
    #[DataProvider('logins')]
    public function testForeignCategoryIsDeniedToNonAdmins(string $user): void
    {
        $data = self::call($user, 'info', self::$videoB);

        if ('admin' === $user) {
            self::assertTrue($data['ok']);
        } else {
            self::assertFalse($data['ok']);
            self::assertSame(I18n::msg('video_editor_no_permission'), $data['error']);
        }
    }

    /**
     * @param array<string, string> $params
     * @return array<string, mixed>
     */
    private static function call(string $user, string $action, string $file, array $params = []): array
    {
        $_POST = ['action' => $action, 'file' => $file] + $params;
        try {
            return Api::handle(self::account($user));
        } finally {
            $_POST = [];
        }
    }

    private static function account(string $key): User
    {
        User::clearInstance(self::$users[$key]);

        return User::require(self::$users[$key]);
    }

    /** Kurzes Testvideo (1 s) über ffmpeg erzeugen und in den Medienpool legen */
    private static function video(string $name, int $categoryId): string
    {
        $dir = Path::addonCache('video_editor', 've-test');
        Dir::create($dir);
        $file = $dir . '/' . $name . '.mp4';
        $result = Ffmpeg::run([(string) Ffmpeg::binary(), '-hide_banner', '-nostdin', '-y', '-f', 'lavfi', '-i', 'testsrc=size=320x180:rate=25',
            '-t', '1', '-pix_fmt', 'yuv420p', '-c:v', 'libx264', '-movflags', '+faststart', $file], 60);
        self::assertSame(0, $result['code'], $result['err']);

        $media = MediaHandler::add(['file' => new SymfonyFile($file), 'category_id' => $categoryId, 'title' => $name]);
        Dir::delete($dir);

        return $media->fileName;
    }

    private static function role(string $name, string $general, string $options): int
    {
        $role = Sql::factory()->setTable('rex_user_role');
        $role->setValue('name', $name);
        $role->setArrayValue('perms', [
            'general' => $general,
            'options' => $options,
            'extras' => '',
            'media' => '|' . self::$categoryA . '|',
            'language' => '|' . Language::getStartId() . '|',
        ]);
        $role->addGlobalCreateFields('ve-test');
        $role->addGlobalUpdateFields('ve-test');
        $role->insert();

        return self::$roles[] = (int) $role->getLastId();
    }

    private static function user(string $login, ?int $roleId): int
    {
        $sql = Sql::factory()->setTable('rex_user');
        $sql->setValue('login', $login);
        $sql->setValue('name', $login);
        $sql->setValue('status', 1);
        $sql->setValue('admin', null === $roleId ? 1 : 0);
        $sql->addGlobalCreateFields('ve-test');
        $sql->addGlobalUpdateFields('ve-test');
        $sql->insert();
        $id = (int) $sql->getLastId();
        if (null !== $roleId) {
            Sql::factory()->setTable('rex_user_role_assignment')->setValue('user_id', $id)->setValue('role_id', $roleId)->insert();
        }

        return $id;
    }
}
