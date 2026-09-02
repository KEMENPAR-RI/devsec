<?php

namespace App\Support;

use Illuminate\Support\Collection;

/**
 * The single source of truth for what can be permissioned in this app.
 *
 * Every entry is one *page* (a menu item), and every page declares which of the
 * four CRUD abilities actually mean something there - the Insiden page has no
 * "create", for instance, since incidents are raised by the monitor, not by hand.
 * Permission names are "{page}.{ability}" (e.g. "situs.update"); each one is
 * registered as a Gate in AppServiceProvider, so it works with @can in Blade, the
 * `can:` route middleware, and $this->authorize() inside Livewire components.
 *
 * Adding a page here is all it takes for it to appear in the Roles permission
 * matrix - no migration, no seeder change.
 */
class Permissions
{
    /** Ability => human label, in the order they're shown as checkbox columns. */
    public const ABILITIES = [
        'view' => 'Lihat',
        'create' => 'Tambah',
        'update' => 'Ubah',
        'delete' => 'Hapus',
    ];

    /**
     * @return array<string, array{label: string, icon: string, abilities: array<int, string>, description: string}>
     */
    public static function pages(): array
    {
        return [
            'dashboard' => [
                'label' => 'Dashboard',
                'icon' => 'house',
                'abilities' => ['view'],
                'description' => 'Ringkasan status seluruh situs.',
            ],
            'situs' => [
                'label' => 'Situs',
                'icon' => 'globe',
                'abilities' => ['view', 'create', 'update', 'delete'],
                'description' => 'Daftar situs yang dipantau beserta ambang batasnya.',
            ],
            'insiden' => [
                'label' => 'Insiden',
                'icon' => 'bell-ringing',
                // Incidents are raised by the monitor, never created by hand;
                // "update" covers menandai selesai & menyelidiki penyebab.
                'abilities' => ['view', 'update', 'delete'],
                'description' => 'Down time, anomali serangan, dan SSL.',
            ],
            'deteksi-judol' => [
                'label' => 'Deteksi Judol',
                'icon' => 'shield-warning',
                // Findings are raised by the scanner, never added by hand; "update"
                // covers managing kata kunci, the threshold, and a finding's status.
                'abilities' => ['view', 'update', 'delete'],
                'description' => 'Temuan konten judi online dan kata kuncinya.',
            ],
            'notifikasi' => [
                'label' => 'Notifikasi',
                'icon' => 'bell',
                'abilities' => ['view', 'update', 'delete'],
                'description' => 'Riwayat notifikasi & email yang terkirim.',
            ],
            'pengaturan' => [
                'label' => 'Pengaturan',
                'icon' => 'gear',
                // Nothing to create or delete - the settings rows already exist.
                'abilities' => ['view', 'update'],
                'description' => 'Kredensial AI, SMTP, dan ambang batas global.',
            ],
            'roles' => [
                'label' => 'Roles',
                'icon' => 'shield-check',
                'abilities' => ['view', 'create', 'update', 'delete'],
                'description' => 'Peran dan hak akses per halaman.',
            ],
            'users' => [
                'label' => 'Users',
                'icon' => 'users',
                'abilities' => ['view', 'create', 'update', 'delete'],
                'description' => 'Akun pengguna dan peran yang dipegangnya.',
            ],
        ];
    }

    /**
     * Every permission name that exists, e.g. ['dashboard.view', 'situs.view', ...].
     *
     * @return array<int, string>
     */
    public static function all(): array
    {
        return collect(static::pages())
            ->flatMap(fn (array $page, string $key) => collect($page['abilities'])->map(fn (string $ability) => "{$key}.{$ability}"))
            ->values()
            ->all();
    }

    /**
     * The permissions granted by an "everything except adding and changing things"
     * role - what the seeded Admin gets. Kept here rather than in the seeder so a
     * newly added page is covered automatically.
     *
     * @return array<int, string>
     */
    public static function readAndDelete(): array
    {
        return collect(static::all())
            ->reject(fn (string $permission) => str_ends_with($permission, '.create') || str_ends_with($permission, '.update'))
            ->values()
            ->all();
    }

    /**
     * Look, don't touch - what the seeded Admin gets. Kept here rather than in the
     * seeder so a page added later is covered without editing two files.
     *
     * @return array<int, string>
     */
    public static function readOnly(): array
    {
        return collect(static::all())
            ->filter(fn (string $permission) => str_ends_with($permission, '.view'))
            ->values()
            ->all();
    }

    /** Keeps a role's stored permissions to names that still exist, in a stable order. */
    public static function sanitize(mixed $permissions): array
    {
        return collect(is_array($permissions) ? $permissions : [])
            ->intersect(static::all())
            ->pipe(fn (Collection $granted) => collect(static::all())->intersect($granted))
            ->values()
            ->all();
    }

    public static function label(string $permission): string
    {
        [$page, $ability] = array_pad(explode('.', $permission, 2), 2, '');

        return (static::pages()[$page]['label'] ?? $page).' - '.(static::ABILITIES[$ability] ?? $ability);
    }
}
