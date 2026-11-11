<?php

namespace FriendsOfRedaxo\VideoEditor;

use Override;
use Redaxo\Core\Addon\Addon;
use Redaxo\Core\Addon\OwnedSchema;
use Redaxo\Core\Core;
use Redaxo\Core\Database\Column;
use Redaxo\Core\Database\Index;
use Redaxo\Core\Database\Table;
use Redaxo\Core\ExtensionPoint\Extension;
use Redaxo\Core\Filesystem\Url;
use Redaxo\Core\Http\Request;
use Redaxo\Core\MediaPool\ExtensionPoint\MediaDeleted;
use Redaxo\Core\MediaPool\ExtensionPoint\MediaDetailSidebar;
use Redaxo\Core\MediaPool\Media;
use Redaxo\Core\MediaPool\MediaPoolPermission;
use Redaxo\Core\Security\Permission;
use Redaxo\Core\Security\User;
use Redaxo\Core\Translation\I18n;
use Redaxo\Core\View\Asset;

use function filemtime;
use function in_array;
use function is_file;
use function json_encode;
use function Redaxo\Core\View\escape;
use function str_starts_with;
use function strtolower;

use const JSON_HEX_AMP;
use const JSON_HEX_APOS;
use const JSON_HEX_QUOT;
use const JSON_HEX_TAG;
use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_UNICODE;

/**
 * Video-Editor: Videos im Medienpool analysieren, optimieren, schneiden, Poster wählen – mit ffmpeg, Aufträge im
 * Hintergrund ({@see Worker}).
 */
final class VideoEditorAddon extends Addon
{
    /** Endungen, die als Video gelten */
    public const array VIDEO = ['mp4', 'm4v', 'mov', 'webm', 'mkv', 'ogv', 'avi'];

    /** Videos bearbeiten: optimieren (als neue Fassung), Poster wählen, Aufträge abbrechen */
    public const string PERM = 'video_editor[]';
    /** Zusätzlich: das Original durch das Ergebnis ersetzen (überschreibt die Datei) */
    public const string PERM_REPLACE = 'video_editor[replace]';

    #[Override]
    public function install(): void
    {
        Table::get(Jobs::TABLE)
            ->ensurePrimaryIdColumn()
            ->ensureColumn(new Column('filename', 'varchar(255)'))
            ->ensureColumn(new Column('kind', 'varchar(20)'))
            ->ensureColumn(new Column('params', 'text'))
            ->ensureColumn(new Column('status', 'varchar(10)'))
            ->ensureColumn(new Column('progress', 'tinyint(3) unsigned', false, '0'))
            ->ensureColumn(new Column('cancel', 'tinyint(1)', false, '0'))
            ->ensureColumn(new Column('result', 'varchar(255)', true))
            ->ensureColumn(new Column('message', 'text', true))
            ->ensureColumn(new Column('log', 'mediumtext', true))
            ->ensureColumn(new Column('createuser', 'varchar(255)'))
            ->ensureColumn(new Column('createdate', 'datetime'))
            ->ensureColumn(new Column('startdate', 'datetime', true))
            ->ensureColumn(new Column('finishdate', 'datetime', true))
            ->ensureIndex(new Index('status', ['status']))
            ->ensureIndex(new Index('filename', ['filename']))
            ->ensure();

        Table::get(Links::TABLE)
            ->ensurePrimaryIdColumn()
            ->ensureColumn(new Column('source', 'varchar(255)'))
            ->ensureColumn(new Column('target', 'varchar(255)'))
            ->ensureColumn(new Column('kind', 'varchar(20)'))
            ->ensureColumn(new Column('label', 'varchar(255)', true))
            ->ensureColumn(new Column('preset', 'varchar(20)', true))
            ->ensureColumn(new Column('createdate', 'datetime'))
            ->ensureIndex(new Index('source', ['source']))
            ->ensureIndex(new Index('target', ['target']))
            ->ensure();
    }

    #[Override]
    public function getOwnedSchema(): OwnedSchema
    {
        return new OwnedSchema(tables: [Jobs::TABLE, Links::TABLE]);
    }

    #[Override]
    public function boot(): void
    {
        Permission::register(self::PERM, I18n::msg('video_editor_perm'));
        Permission::register(self::PERM_REPLACE, I18n::msg('video_editor_perm_replace'), Permission::OPTIONS);

        // Verknüpfungen und Aufträge gelöschter Medien aufräumen
        Extension::register(MediaDeleted::class, static function (MediaDeleted $ep): void {
            Links::forget($ep->media->fileName);
            Jobs::deleteFor($ep->media->fileName);
        });

        if (!Core::isBackend() || null === Core::getUser()) {
            return;
        }
        // auf allen Seiten: der Medienpool öffnet sich auch als Overlay über anderen Seiten
        Asset::addCssFile($this->asset('video-editor.css'));
        Asset::addJsFile($this->asset('video-editor.js'), [Asset::JS_DEFERED => true]);

        if (str_starts_with(Request::get('page', 'string', ''), 'mediapool')) {
            Extension::register(MediaDetailSidebar::class, static function (MediaDetailSidebar $ep): string {
                $user = Core::getUser();

                // ansehen (Vorschau, Analyse) darf jeder, der das Medium öffnen kann – bearbeiten nur mit Recht
                return null !== $user && self::isVideo($ep->media)
                    ? self::panel($ep->media) . $ep->subject
                    : (string) $ep->subject;
            });
        }
    }

    /** Darf der Benutzer Videos bearbeiten? */
    public static function allowed(User $user): bool
    {
        return $user->admin || $user->hasPerm(self::PERM);
    }

    /** Darf er das Video sehen? Medienrecht für dessen Kategorie */
    public static function mayView(User $user, Media $media): bool
    {
        $perm = $user->getComplexPerm('media');

        return $user->admin || ($perm instanceof MediaPoolPermission && $perm->hasCategoryPerm($media->categoryId ?: null));
    }

    /** Darf er das Original überschreiben? */
    public static function mayReplace(User $user): bool
    {
        return $user->admin || (self::allowed($user) && $user->hasPerm(self::PERM_REPLACE));
    }

    public static function isVideo(Media $media): bool
    {
        return in_array(strtolower($media->getExtension()), self::VIDEO, true);
    }

    /** Platzhalter in der Seitenleiste der Medien-Detailansicht – das Skript baut den Editor */
    private static function panel(Media $media): string
    {
        $config = [
            'api' => Url::backendController(Api::getUrlParams()),
            'file' => $media->fileName,
            'i18n' => self::texts(),
        ];

        return '<section class="ve-panel" data-video-editor="'
            . escape(json_encode($config, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT))
            . '"><p class="ve-loading">' . escape(I18n::msg('video_editor_loading')) . '</p></section>';
    }

    /** @return array<string, string> */
    private static function texts(): array
    {
        $keys = ['title', 'loading', 'error', 'no_ffmpeg', 'score', 'optimize', 'optimize_title', 'poster', 'poster_title',
            'poster_take', 'poster_current', 'preview', 'mode_replace_denied', 'cut', 'cut_title', 'cut_help', 'cut_timeline', 'cut_segment', 'cut_split', 'cut_in', 'cut_out', 'cut_remove', 'cut_undo', 'cut_reset', 'cut_play', 'cut_stop', 'cut_length', 'cut_method', 'cut_fast', 'cut_fast_text', 'cut_exact', 'cut_exact_text', 'cut_keys', 'cut_snap_note', 'cut_snap_none', 'jobs', 'versions', 'source', 'cancel', 'retry', 'start', 'close', 'mode',
            'mode_version', 'mode_replace', 'mode_replace_note', 'recommended', 'fast', 'status_queued', 'status_running',
            'status_done', 'status_failed', 'status_canceled', 'open', 'apply', 'hints_none', 'duration', 'resolution',
            'bitrate', 'codec', 'faststart_yes', 'faststart_no', 'no_audio'];
        $list = [];
        foreach ($keys as $key) {
            $list[$key] = I18n::msg('video_editor_' . $key);
        }

        return $list;
    }

    private function asset(string $file): string
    {
        $path = $this->getPath('assets/' . $file);

        return $this->getAssetsUrl($file) . '?v=' . (is_file($path) ? filemtime($path) : '0');
    }
}
