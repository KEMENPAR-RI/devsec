<?php

namespace Tests\Feature;

use App\Models\Incident;
use App\Models\Role;
use App\Models\Setting;
use App\Models\Website;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The cleanup migration runs against production data, so what it keeps matters
 * as much as what it removes. RefreshDatabase has already run it on the empty
 * schema; these tests plant leftovers and run it again (it is idempotent).
 */
class RemoveOffensiveModuleMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_offensive_tables_and_website_columns_are_gone(): void
    {
        foreach (['judol_detections', 'judol_findings', 'judol_keywords', 'judol_exclusions', 'defacement_findings', 'page_snapshots', 'page_changes', 'page_change_forensics'] as $table) {
            $this->assertFalse(Schema::hasTable($table), "{$table} should be dropped");
        }

        $this->assertFalse(Schema::hasColumn('websites', 'judol_scan_interval_minutes'));
        $this->assertFalse(Schema::hasColumn('websites', 'judol_scanned_at'));
        $this->assertTrue(Schema::hasTable('websites'));
        $this->assertTrue(Schema::hasTable('website_checks'));
        $this->assertTrue(Schema::hasTable('incidents'));
    }

    public function test_it_removes_offensive_incidents_but_keeps_every_other_kind(): void
    {
        $website = Website::factory()->create();
        $make = fn (string $type, ?array $meta = null) => Incident::create([
            'website_id' => $website->id, 'type' => $type, 'severity' => 'warning',
            'title' => $type, 'status' => 'open', 'detected_at' => now(), 'meta' => $meta,
        ]);

        $judol = $make('judol');
        $foreign = $make('attack', ['reason' => 'foreign_script']);
        $defaced = $make('attack', ['reason' => 'defacement']);
        $downtime = $make('downtime');
        $spike = $make('attack', ['reason' => 'response_time_spike']);
        $size = $make('attack', ['reason' => 'content_changed']);
        $ssl = $make('ssl');

        $this->runMigration();

        foreach ([$judol, $foreign, $defaced] as $gone) {
            $this->assertDatabaseMissing('incidents', ['id' => $gone->id]);
        }

        foreach ([$downtime, $spike, $size, $ssl] as $kept) {
            $this->assertDatabaseHas('incidents', ['id' => $kept->id]);
        }
    }

    public function test_it_clears_queued_work_for_removed_classes_and_leaves_the_rest(): void
    {
        $job = fn (string $queue, string $payload) => DB::table('jobs')->insertGetId([
            'queue' => $queue, 'payload' => $payload, 'attempts' => 0, 'available_at' => now()->timestamp, 'created_at' => now()->timestamp,
        ]);

        $scan = $job('scans', '{"displayName":"App\\\\Jobs\\\\ScanWebsiteForJudolJob"}');
        $notify = $job('checks-high', '{"displayName":"App\\\\Notifications\\\\JudolContentDetected"}');
        $check = $job('checks', '{"displayName":"App\\\\Jobs\\\\CheckWebsiteJob"}');

        DB::table('failed_jobs')->insert([
            'uuid' => 'a', 'connection' => 'database', 'queue' => 'scans', 'payload' => '{}', 'exception' => 'x',
        ]);
        DB::table('failed_jobs')->insert([
            'uuid' => 'b', 'connection' => 'database', 'queue' => 'checks', 'payload' => '{"displayName":"CheckWebsiteJob"}', 'exception' => 'x',
        ]);

        $this->runMigration();

        $this->assertDatabaseMissing('jobs', ['id' => $scan]);
        $this->assertDatabaseMissing('jobs', ['id' => $notify]);
        $this->assertDatabaseHas('jobs', ['id' => $check]);
        $this->assertDatabaseMissing('failed_jobs', ['uuid' => 'a']);
        $this->assertDatabaseHas('failed_jobs', ['uuid' => 'b']);
    }

    public function test_it_removes_only_the_judol_notifications(): void
    {
        $row = fn (string $id, string $type) => DB::table('notifications')->insert([
            'id' => $id, 'type' => $type, 'notifiable_type' => 'App\\Models\\User', 'notifiable_id' => 1, 'data' => '{}',
        ]);
        $row('11111111-1111-1111-1111-111111111111', 'App\\Notifications\\JudolContentDetected');
        $row('22222222-2222-2222-2222-222222222222', 'App\\Notifications\\WebsiteDownNotification');

        $this->runMigration();

        $this->assertDatabaseMissing('notifications', ['id' => '11111111-1111-1111-1111-111111111111']);
        $this->assertDatabaseHas('notifications', ['id' => '22222222-2222-2222-2222-222222222222']);
    }

    public function test_ai_credentials_survive_under_their_new_names_and_module_settings_go(): void
    {
        $set = fn (string $key, string $value) => DB::table('settings')->insert(['uuid' => (string) Str::uuid(), 'key' => $key, 'value' => $value]);
        $set('anthropic_api_key', 'ciphertext');
        $set('anthropic_workspace_id', 'wrkspc_1');
        $set('judol_ai_model', 'claude-sonnet-5');
        $set('judol_ai_enabled', '1');
        $set('judol_score_threshold', '3');
        $set('default_judol_scan_interval_minutes', '360');
        $set('app_name', 'Tetap');

        $this->runMigration();

        $this->assertSame('ciphertext', Setting::get('ai_api_key'));
        $this->assertSame('wrkspc_1', Setting::get('ai_workspace_id'));
        $this->assertSame('claude-sonnet-5', Setting::get('ai_model'));
        $this->assertSame('Tetap', Setting::get('app_name'));

        foreach (['anthropic_api_key', 'anthropic_workspace_id', 'judol_ai_model', 'judol_ai_enabled', 'judol_score_threshold', 'default_judol_scan_interval_minutes'] as $gone) {
            $this->assertDatabaseMissing('settings', ['key' => $gone]);
        }
    }

    public function test_an_ai_key_saved_after_the_deploy_is_not_overwritten_by_the_old_one(): void
    {
        DB::table('settings')->insert([
            ['uuid' => (string) Str::uuid(), 'key' => 'anthropic_api_key', 'value' => 'old'],
            ['uuid' => (string) Str::uuid(), 'key' => 'ai_api_key', 'value' => 'new'],
        ]);

        $this->runMigration();

        $this->assertSame('new', Setting::get('ai_api_key'));
        $this->assertDatabaseMissing('settings', ['key' => 'anthropic_api_key']);
    }

    public function test_roles_lose_the_removed_permissions_and_keep_the_rest(): void
    {
        $role = Role::factory()->create(['permissions' => ['situs.view', 'deteksi-judol.view', 'defacement.update', 'perubahan-konten.delete', 'insiden.view']]);

        $this->runMigration();

        $this->assertSame(['situs.view', 'insiden.view'], $role->fresh()->permissions);
    }

    public function test_running_it_twice_is_harmless(): void
    {
        $this->runMigration();
        $this->runMigration();

        $this->assertTrue(Schema::hasTable('websites'));
    }

    private function runMigration(): void
    {
        (require database_path('migrations/2026_11_05_100001_remove_offensive_module.php'))->up();
    }
}
