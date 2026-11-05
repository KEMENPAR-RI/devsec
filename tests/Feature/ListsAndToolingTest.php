<?php

namespace Tests\Feature;

use App\Livewire\Incidents\Index as IncidentsIndex;
use App\Livewire\Notifications\Index as NotificationsIndex;
use App\Livewire\NotificationsDropdown;
use App\Livewire\Roles\Index as RolesIndex;
use App\Livewire\Settings\Index as SettingsIndex;
use App\Livewire\Websites\Index;
use App\Models\Incident;
use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use App\Models\Website;
use App\Notifications\WebsiteDownNotification;
use App\Services\AppSettings;
use App\Services\Backup\DatabaseBackup;
use App\Support\Dates;
use App\Support\Permissions;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class ListsAndToolingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        (new RoleSeeder)->run();
    }

    // --- Timezone helper ----------------------------------------------------

    public function test_timestamps_carry_the_indonesian_zone_label(): void
    {
        $moment = Carbon::parse('2026-09-02 08:00:00', 'UTC');

        Setting::set('app_timezone', 'Asia/Jakarta');
        $this->assertSame('WIB', app_timezone_label());
        $this->assertSame('02 Sep 2026, 15:00 WIB', app_datetime($moment));

        Setting::set('app_timezone', 'Asia/Makassar');
        $this->assertSame('WITA', app_timezone_label());
        $this->assertSame('02 Sep 2026, 16:00 WITA', app_datetime($moment));

        Setting::set('app_timezone', 'Asia/Jayapura');
        $this->assertSame('WIT', app_timezone_label());
        $this->assertSame('02 Sep 2026, 17:00 WIT', app_datetime($moment));
    }

    public function test_a_null_timestamp_renders_as_nothing_rather_than_a_fallback_date(): void
    {
        $this->assertSame('', app_datetime(null));
        $this->assertSame('', app_datetime_full(null));
        $this->assertSame('', Dates::plain(null));
    }

    public function test_an_unlisted_timezone_falls_back_to_its_own_abbreviation(): void
    {
        $this->assertSame('UTC', Dates::abbreviation('UTC'));
    }

    // --- Notifications ------------------------------------------------------

    public function test_opening_a_notification_marks_it_read(): void
    {
        $user = $this->superadmin();
        $website = Website::factory()->create();
        $incident = $this->incident($website);
        $user->notify(new WebsiteDownNotification($website, $incident));

        $notification = $user->notifications()->firstOrFail();
        $this->assertNull($notification->read_at);

        Livewire::actingAs($user)
            ->test(NotificationsDropdown::class)
            ->call('open', $notification->id)
            ->assertRedirect(route('situs.show', $website));

        $this->assertNotNull($notification->fresh()->read_at);
        $this->assertSame(0, $user->unreadNotifications()->count());
    }

    public function test_one_user_cannot_open_or_delete_another_users_notification(): void
    {
        $owner = $this->superadmin();
        $intruder = $this->superadmin();
        $website = Website::factory()->create();
        $owner->notify(new WebsiteDownNotification($website, $this->incident($website)));

        $notification = $owner->notifications()->firstOrFail();

        Livewire::actingAs($intruder)->test(NotificationsIndex::class)->call('delete', $notification->id);

        $this->assertDatabaseHas('notifications', ['id' => $notification->id]);
        $this->assertNull($notification->fresh()->read_at);
    }

    public function test_a_notification_can_be_deleted_from_the_list(): void
    {
        $user = $this->superadmin();
        $website = Website::factory()->create();
        $user->notify(new WebsiteDownNotification($website, $this->incident($website)));
        $notification = $user->notifications()->firstOrFail();

        Livewire::actingAs($user)->test(NotificationsIndex::class)->call('delete', $notification->id);

        $this->assertDatabaseMissing('notifications', ['id' => $notification->id]);
    }

    // --- Per-row delete by uuid ---------------------------------------------

    public function test_a_single_incident_can_be_deleted_by_uuid(): void
    {
        $website = Website::factory()->create();
        $keep = $this->incident($website);
        $drop = $this->incident($website);

        Livewire::actingAs($this->superadmin())
            ->test(IncidentsIndex::class)
            ->call('delete', $drop->uuid);

        $this->assertDatabaseMissing('incidents', ['id' => $drop->id]);
        $this->assertDatabaseHas('incidents', ['id' => $keep->id]);
    }

    public function test_a_read_only_role_cannot_delete_a_row(): void
    {
        $website = Website::factory()->create();
        $incident = $this->incident($website);

        Livewire::actingAs($this->userWith('admin'))
            ->test(IncidentsIndex::class)
            ->call('delete', $incident->uuid)
            ->assertForbidden();

        $this->assertDatabaseHas('incidents', ['id' => $incident->id]);
    }

    // --- Ordering -----------------------------------------------------------

    public function test_the_situs_list_puts_the_newest_first(): void
    {
        $old = Website::factory()->create(['name' => 'Aaa Lama', 'created_at' => now()->subWeek()]);
        $new = Website::factory()->create(['name' => 'Zzz Baru', 'created_at' => now()]);

        $names = Livewire::actingAs($this->superadmin())
            ->test(Index::class)
            ->viewData('websites')
            ->pluck('name')
            ->all();

        $this->assertSame([$new->name, $old->name], $names, 'newest first, not alphabetical');
    }

    // --- Roles --------------------------------------------------------------

    public function test_select_all_and_clear_all_work_from_one_control(): void
    {
        $component = Livewire::actingAs($this->superadmin())
            ->test(RolesIndex::class)
            ->call('openCreate')
            ->call('toggleAll');

        $this->assertSame(Permissions::all(), $component->get('permissions'));
        $this->assertSame([], $component->call('toggleAll')->get('permissions'));
    }

    public function test_the_roles_page_never_prints_a_uuid(): void
    {
        $role = Role::factory()->create(['name' => 'Operator']);

        $body = $this->actingAs($this->superadmin())->get(route('roles'))->assertOk()->getContent();

        $this->assertStringContainsString('Operator', $body);
        // It is still what the action arguments carry, just not shown to the reader.
        $this->assertStringContainsString("delete('{$role->uuid}')", $body);
        $this->assertDoesNotMatchRegularExpression('/>\s*[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-/', $body);
    }

    // --- Logo ---------------------------------------------------------------

    public function test_a_logo_upload_is_stored_and_replaces_the_previous_one(): void
    {
        Storage::fake('public');

        $component = Livewire::actingAs($this->superadmin())
            ->test(SettingsIndex::class)
            ->set('logo', UploadedFile::fake()->image('logo.png'));

        $first = Setting::get('app_logo_path');
        $this->assertNotNull($first);
        Storage::disk('public')->assertExists($first);

        $component->set('logo', UploadedFile::fake()->image('logo-baru.png'));

        $second = Setting::get('app_logo_path');
        $this->assertNotSame($first, $second);
        Storage::disk('public')->assertExists($second);
        Storage::disk('public')->assertMissing($first);
    }

    public function test_removing_the_logo_falls_back_to_the_initial(): void
    {
        Storage::fake('public');

        $component = Livewire::actingAs($this->superadmin())
            ->test(SettingsIndex::class)
            ->set('logo', UploadedFile::fake()->image('logo.png'));

        $path = Setting::get('app_logo_path');
        $component->call('removeLogo');

        $this->assertNull(Setting::get('app_logo_path'));
        Storage::disk('public')->assertMissing($path);
    }

    public function test_a_non_image_is_refused(): void
    {
        Storage::fake('public');

        Livewire::actingAs($this->superadmin())
            ->test(SettingsIndex::class)
            ->set('logo', UploadedFile::fake()->create('dump.sql', 10))
            ->assertHasErrors('logo');

        $this->assertNull(Setting::get('app_logo_path'));
    }

    public function test_a_favicon_upload_is_stored_and_replaces_the_previous_one(): void
    {
        Storage::fake('public');

        $component = Livewire::actingAs($this->superadmin())
            ->test(SettingsIndex::class)
            ->set('favicon', UploadedFile::fake()->image('favicon.png', 32, 32));

        $first = Setting::get('app_favicon_path');
        $this->assertNotNull($first);
        Storage::disk('public')->assertExists($first);

        $component->set('favicon', UploadedFile::fake()->image('favicon-baru.png', 32, 32));

        $second = Setting::get('app_favicon_path');
        $this->assertNotSame($first, $second);
        Storage::disk('public')->assertMissing($first);
    }

    public function test_the_favicon_is_linked_in_the_page_head(): void
    {
        Storage::fake('public');

        Livewire::actingAs($this->superadmin())
            ->test(SettingsIndex::class)
            ->set('favicon', UploadedFile::fake()->image('favicon.png', 32, 32));

        $url = app(AppSettings::class)->faviconUrl();

        $this->actingAs($this->superadmin())
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('<link rel="icon" href="'.$url.'">', false);
    }

    public function test_removing_the_favicon_drops_the_link_again(): void
    {
        Storage::fake('public');

        $component = Livewire::actingAs($this->superadmin())
            ->test(SettingsIndex::class)
            ->set('favicon', UploadedFile::fake()->image('favicon.png', 32, 32));

        $path = Setting::get('app_favicon_path');
        $component->call('removeFavicon');

        $this->assertNull(Setting::get('app_favicon_path'));
        Storage::disk('public')->assertMissing($path);
        $this->actingAs($this->superadmin())->get(route('dashboard'))->assertDontSee('<link rel="icon"', false);
    }

    public function test_an_oversized_favicon_is_refused(): void
    {
        Storage::fake('public');

        Livewire::actingAs($this->superadmin())
            ->test(SettingsIndex::class)
            ->set('favicon', UploadedFile::fake()->create('besar.png', 900))
            ->assertHasErrors('favicon');

        $this->assertNull(Setting::get('app_favicon_path'));
    }

    // --- Backup -------------------------------------------------------------

    public function test_only_the_five_newest_backups_are_kept(): void
    {
        Storage::fake(DatabaseBackup::DISK);
        $backup = app(DatabaseBackup::class);

        // Seven dumps, oldest first, one hour apart.
        foreach (range(1, 7) as $i) {
            Storage::disk(DatabaseBackup::DISK)->put(
                DatabaseBackup::DIRECTORY."/backup-uji-{$i}.sql",
                "-- uji {$i}"
            );
        }

        $backup->prune();

        $this->assertSame(DatabaseBackup::MAX_KEEP, $backup->all()->count());
    }

    public function test_a_backup_is_written_as_an_importable_sql_gz(): void
    {
        // dump() needs MySQL, so it is stubbed here; what is being pinned is the
        // packaging around it - a file mysql or phpMyAdmin would refuse to import
        // only shows up on the day someone actually needs to restore.
        Storage::fake(DatabaseBackup::DISK);

        $sql = "-- dump\n".str_repeat("INSERT INTO `t` (`a`) VALUES ('x');\n", 200);

        $backup = new class($sql) extends DatabaseBackup
        {
            public function __construct(protected string $stub) {}

            protected function dump(): string
            {
                return $this->stub;
            }
        };

        $filename = $backup->create();
        $stored = Storage::disk(DatabaseBackup::DISK)->get(DatabaseBackup::DIRECTORY.'/'.$filename);

        $this->assertStringEndsWith('.sql.gz.enc', $filename);

        // At rest the file is encrypted, so it is deliberately not a readable
        // gzip any more - that is the whole point of BackupCipher.
        $this->assertStringNotContainsString('INSERT INTO', $stored);

        // What the operator actually receives is still the importable .sql.gz.
        $delivered = $backup->contents($filename);
        $this->assertStringEndsWith('.sql.gz', $backup->downloadName($filename));
        // The gzip magic number: what gunzip, mysql and phpMyAdmin sniff for.
        $this->assertSame("\x1f\x8b", substr($delivered, 0, 2));
        $this->assertSame($sql, gzdecode($delivered));
        $this->assertLessThan(strlen($sql), strlen($delivered));
    }

    public function test_dumps_written_before_compression_are_still_listed(): void
    {
        Storage::fake(DatabaseBackup::DISK);
        $disk = Storage::disk(DatabaseBackup::DISK);

        $disk->put(DatabaseBackup::DIRECTORY.'/backup-baru.sql.gz', 'x');
        $disk->put(DatabaseBackup::DIRECTORY.'/backup-lama.sql', '-- lama');
        $disk->put(DatabaseBackup::DIRECTORY.'/backup-sebentar.tar.gz', 'x');
        // Not a backup at all - must not be offered for download or deletion.
        $disk->put(DatabaseBackup::DIRECTORY.'/catatan.txt', 'abaikan');

        $names = app(DatabaseBackup::class)->all()->pluck('name');

        $this->assertCount(3, $names);
        $this->assertFalse($names->contains('catatan.txt'));
    }

    public function test_the_retention_setting_cannot_be_pushed_past_the_ceiling(): void
    {
        // A stored value from an older release (or hand-edited) must not lift it.
        Setting::set('backup_keep', '50');

        $this->assertSame(DatabaseBackup::MAX_KEEP, app(DatabaseBackup::class)->keep());
    }

    public function test_a_smaller_retention_is_still_honoured(): void
    {
        Setting::set('backup_keep', '2');

        $this->assertSame(2, app(DatabaseBackup::class)->keep());
    }

    public function test_the_backup_schedule_only_fires_at_the_configured_moment(): void
    {
        $backup = app(DatabaseBackup::class);
        Setting::set('app_timezone', 'Asia/Jakarta');
        Setting::set('backup_schedule_enabled', '1');
        Setting::set('backup_frequency', 'daily');
        Setting::set('backup_time', '02:00');

        // 02:00 WIB is 19:00 UTC the day before.
        $this->assertTrue($backup->isDue(Carbon::parse('2026-09-01 19:00:00', 'UTC')));
        $this->assertFalse($backup->isDue(Carbon::parse('2026-09-01 19:01:00', 'UTC')));
    }

    public function test_a_weekly_schedule_only_fires_on_monday(): void
    {
        $backup = app(DatabaseBackup::class);
        Setting::set('app_timezone', 'Asia/Jakarta');
        Setting::set('backup_schedule_enabled', '1');
        Setting::set('backup_frequency', 'weekly');
        Setting::set('backup_time', '02:00');

        $this->assertTrue($backup->isDue(Carbon::parse('2026-09-07 02:00:00', 'Asia/Jakarta')));
        $this->assertFalse($backup->isDue(Carbon::parse('2026-09-08 02:00:00', 'Asia/Jakarta')));
    }

    public function test_a_switched_off_schedule_never_fires(): void
    {
        Setting::set('backup_schedule_enabled', '0');
        Setting::set('backup_time', '02:00');

        $this->assertFalse(app(DatabaseBackup::class)->isDue(Carbon::parse('2026-09-01 02:00:00', 'Asia/Jakarta')));
    }

    public function test_a_download_needs_permission_and_a_real_file(): void
    {
        $url = route('pengaturan.backup.download', ['filename' => 'tidak-ada.sql']);

        $this->actingAs($this->superadmin())->get($url)->assertNotFound();
        $this->actingAs($this->userWith('admin'))->get($url)->assertForbidden();
    }

    protected function incident(Website $website, string $type = 'downtime'): Incident
    {
        return Incident::create([
            'website_id' => $website->id, 'type' => $type, 'severity' => 'critical',
            'title' => 'Uji insiden', 'status' => 'open', 'detected_at' => now(),
        ]);
    }

    protected function superadmin(): User
    {
        return $this->userWith('superadmin');
    }

    protected function userWith(string $slug): User
    {
        return User::factory()->create(['role_id' => Role::where('slug', $slug)->value('id')]);
    }
}
