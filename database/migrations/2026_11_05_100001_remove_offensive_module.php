<?php

use App\Models\Setting;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Removes the "Offensive Module" (judol detection, defacement detection, content
 * change history) and everything it stored.
 *
 * Destructive by design: the tables below are dropped with their data. Take a
 * backup first (`php artisan db:backup`). Every step is guarded, so the migration
 * is safe to run on a fresh install and to re-run after a partial failure.
 *
 * Uptime monitoring tables (websites, website_checks, incidents, ...) are only
 * touched to remove what the module left behind in them; the older migrations
 * stay as they are, so migration history on production is not rewritten.
 */
return new class extends Migration
{
    /** Child tables first, so no foreign key still points at a table being dropped. */
    private const TABLES = [
        'page_change_forensics',
        'page_changes',
        'defacement_findings',
        'page_snapshots',
        'judol_findings',
        'judol_detections',
        'judol_keywords',
        'judol_exclusions',
    ];

    /** Setting keys that only the module read. */
    private const OBSOLETE_SETTINGS = [
        'judol_ai_enabled',
        'judol_ai_confidence_threshold',
        'judol_ai_max_images',
        'judol_score_threshold',
        'default_judol_scan_interval_minutes',
    ];

    /** The AI credentials outlive the module (incident investigation uses them), under provider-neutral names. */
    private const RENAMED_SETTINGS = [
        'anthropic_api_key' => 'ai_api_key',
        'anthropic_workspace_id' => 'ai_workspace_id',
        'judol_ai_model' => 'ai_model',
    ];

    private const OBSOLETE_PERMISSION_PREFIXES = ['deteksi-judol.', 'defacement.', 'perubahan-konten.'];

    public function up(): void
    {
        $this->clearQueuedWork();
        $this->deleteIncidentsAndNotifications();

        foreach (self::TABLES as $table) {
            Schema::dropIfExists($table);
        }

        $this->dropWebsiteColumns();
        $this->migrateSettings();
        $this->stripRolePermissions();

        // Evidence images and raw page bodies fetched from the monitored sites.
        Storage::disk('local')->deleteDirectory('judol-evidence');
        Storage::disk('local')->deleteDirectory('page-bodies');
    }

    /**
     * Nothing to restore: the data is gone. Re-creating the empty tables would
     * only pretend otherwise - restore the pre-migration backup instead.
     */
    public function down(): void {}

    /**
     * Jobs and failed jobs whose class no longer exists would only fail when a
     * worker picked them up, and a leftover `scans` queue has no worker anymore.
     */
    private function clearQueuedWork(): void
    {
        $classes = ['ScanWebsiteForJudolJob', 'PruneStaleJudolJob', 'JudolContentDetected'];

        foreach (['jobs', 'failed_jobs'] as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            DB::table($table)
                ->where('queue', 'scans')
                ->orWhere(function ($query) use ($classes) {
                    foreach ($classes as $class) {
                        $query->orWhere('payload', 'like', "%{$class}%");
                    }
                })
                ->delete();
        }
    }

    private function deleteIncidentsAndNotifications(): void
    {
        if (Schema::hasTable('incidents')) {
            DB::table('incidents')
                ->where('type', 'judol')
                ->orWhere(fn ($query) => $query
                    ->where('type', 'attack')
                    ->whereIn('meta->reason', ['foreign_script', 'defacement']))
                ->delete();
        }

        if (Schema::hasTable('notifications')) {
            DB::table('notifications')->where('type', 'like', '%JudolContentDetected')->delete();
        }
    }

    private function dropWebsiteColumns(): void
    {
        $columns = array_values(array_filter(
            ['judol_scan_interval_minutes', 'judol_scanned_at'],
            fn (string $column) => Schema::hasColumn('websites', $column),
        ));

        if ($columns !== []) {
            Schema::table('websites', fn (Blueprint $table) => $table->dropColumn($columns));
        }
    }

    private function migrateSettings(): void
    {
        if (! Schema::hasTable('settings')) {
            return;
        }

        foreach (self::RENAMED_SETTINGS as $old => $new) {
            // An already-saved new key wins; the old one is dropped either way.
            if (! DB::table('settings')->where('key', $new)->exists()) {
                DB::table('settings')->where('key', $old)->update(['key' => $new]);
            }

            DB::table('settings')->where('key', $old)->delete();
        }

        DB::table('settings')->whereIn('key', self::OBSOLETE_SETTINGS)->delete();

        // Settings are cached forever; a stale copy would hide the move.
        Setting::flush();
        foreach ([...array_keys(self::RENAMED_SETTINGS), ...array_values(self::RENAMED_SETTINGS), ...self::OBSOLETE_SETTINGS] as $key) {
            Cache::forget("setting.{$key}");
        }
    }

    private function stripRolePermissions(): void
    {
        if (! Schema::hasTable('roles')) {
            return;
        }

        foreach (DB::table('roles')->get(['id', 'permissions']) as $role) {
            $permissions = json_decode((string) $role->permissions, true);

            if (! is_array($permissions)) {
                continue;
            }

            $kept = array_values(array_filter(
                $permissions,
                fn ($permission) => ! is_string($permission) || ! Str::startsWith($permission, self::OBSOLETE_PERMISSION_PREFIXES),
            ));

            if (count($kept) !== count($permissions)) {
                DB::table('roles')->where('id', $role->id)->update(['permissions' => json_encode($kept)]);
            }
        }
    }
};
