<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Services\Backup\BackupCipher;
use App\Services\Backup\DatabaseBackup;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

/**
 * A dump holds every account's password hash. Being outside the public root only
 * helps while the application is the only way in - these tests are about the file
 * itself being useless to whoever picks it up off the disk.
 */
class BackupEncryptionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        (new RoleSeeder)->run();
        Storage::fake(DatabaseBackup::DISK);
    }

    public function test_a_stored_backup_is_not_readable_as_plain_text(): void
    {
        $this->storeBackup('backup-uji.sql.gz.enc', '-- dump uji dengan $2y$hash rahasia');

        $onDisk = Storage::disk(DatabaseBackup::DISK)->get('backups/backup-uji.sql.gz.enc');

        $this->assertStringNotContainsString('rahasia', $onDisk);
        $this->assertStringNotContainsString('$2y$', $onDisk);
    }

    public function test_the_service_reads_back_exactly_what_was_written(): void
    {
        $this->storeBackup('backup-uji.sql.gz.enc', '-- dump uji');

        $this->assertSame('-- dump uji', app(DatabaseBackup::class)->contents('backup-uji.sql.gz.enc'));
    }

    public function test_a_tampered_backup_fails_instead_of_decrypting_into_nonsense(): void
    {
        $this->storeBackup('backup-uji.sql.gz.enc', '-- dump uji');

        $path = 'backups/backup-uji.sql.gz.enc';
        $payload = Storage::disk(DatabaseBackup::DISK)->get($path);
        Storage::disk(DatabaseBackup::DISK)->put($path, $payload.'x');

        $this->expectException(RuntimeException::class);
        app(DatabaseBackup::class)->contents('backup-uji.sql.gz.enc');
    }

    public function test_a_different_app_key_cannot_read_the_backup(): void
    {
        $this->storeBackup('backup-uji.sql.gz.enc', '-- dump uji');

        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);

        $this->expectException(RuntimeException::class);
        app(DatabaseBackup::class)->contents('backup-uji.sql.gz.enc');
    }

    public function test_older_plain_text_backups_stay_readable(): void
    {
        // Files written before encryption existed must not become unreachable -
        // the whole point of a backup is that yesterday's still works.
        Storage::disk(DatabaseBackup::DISK)->put('backups/backup-lama.sql.gz', '-- dump lama');

        $backup = app(DatabaseBackup::class);

        $this->assertSame('-- dump lama', $backup->contents('backup-lama.sql.gz'));
        $this->assertContains('backup-lama.sql.gz', $backup->all()->pluck('name')->all());
    }

    public function test_the_download_serves_decrypted_bytes_under_the_plain_name(): void
    {
        $this->storeBackup('backup-uji.sql.gz.enc', '-- dump uji');

        $response = $this->actingAs($this->superadmin())
            ->get(route('pengaturan.backup.download', ['filename' => 'backup-uji.sql.gz.enc']));

        $response->assertOk();
        $this->assertSame('-- dump uji', $response->streamedContent());
        $this->assertStringContainsString('backup-uji.sql.gz', $response->headers->get('content-disposition'));
        $this->assertStringNotContainsString('.enc', $response->headers->get('content-disposition'));
    }

    public function test_the_download_is_closed_without_the_permission(): void
    {
        $this->storeBackup('backup-uji.sql.gz.enc', '-- dump uji');

        $user = User::factory()->create(['role_id' => Role::factory()->withPermissions(['pengaturan.view'])->create()->id]);

        $this->actingAs($user)
            ->get(route('pengaturan.backup.download', ['filename' => 'backup-uji.sql.gz.enc']))
            ->assertForbidden();
    }

    public function test_a_missing_backup_is_a_404_not_a_decryption_error(): void
    {
        $this->actingAs($this->superadmin())
            ->get(route('pengaturan.backup.download', ['filename' => 'tidak-ada.sql.gz.enc']))
            ->assertNotFound();
    }

    public function test_the_console_decrypt_command_writes_a_usable_copy(): void
    {
        $this->storeBackup('backup-uji.sql.gz.enc', '-- dump uji');

        $output = storage_path('app/backup-uji-hasil.sql.gz');

        $this->artisan('backup:decrypt', ['filename' => 'backup-uji.sql.gz.enc', '--output' => $output])
            ->assertSuccessful();

        $this->assertSame('-- dump uji', file_get_contents($output));

        @unlink($output);
    }

    protected function storeBackup(string $filename, string $plaintext): void
    {
        Storage::disk(DatabaseBackup::DISK)->put(
            'backups/'.$filename,
            app(BackupCipher::class)->encrypt($plaintext)
        );
    }

    protected function superadmin(): User
    {
        return User::factory()->create(['role_id' => Role::where('slug', 'superadmin')->value('id')]);
    }
}
