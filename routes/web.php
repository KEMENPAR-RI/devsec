<?php

use App\Http\Controllers\TvDisplayController;
use App\Http\Controllers\JudolEvidenceController;
use App\Http\Controllers\PwaController;
use App\Livewire\Audit\Index as AuditIndex;
use App\Livewire\Auth\ForgotPassword;
use App\Livewire\Auth\Login;
use App\Livewire\Auth\ResetPassword;
use App\Livewire\Auth\TwoFactorChallenge;
use App\Livewire\Dashboard;
use App\Livewire\Incidents\Index as IncidentsIndex;
use App\Livewire\JudolDetections\Index as JudolDetectionsIndex;
use App\Livewire\Notifications\Index as NotificationsIndex;
use App\Livewire\Profile\Index as ProfileIndex;
use App\Livewire\Reports\Index as ReportsIndex;
use App\Livewire\Roles\Index as RolesIndex;
use App\Livewire\Settings\Index as SettingsIndex;
use App\Livewire\Users\Index as UsersIndex;
use App\Livewire\Websites\Index as WebsitesIndex;
use App\Livewire\Websites\Show as WebsiteShow;
use App\Models\AuditLog;
use App\Models\Website;
use App\Services\Backup\DatabaseBackup;
use App\Services\Reporting\MonthlyReport;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

// Installable-app plumbing. Public on purpose: the browser fetches a manifest
// anonymously (an auth redirect there kills installability), and the install
// prompt has to be able to appear on the login screen. Neither route exposes
// anything beyond the branding that is already on every page.
Route::get('/manifest.webmanifest', [PwaController::class, 'manifest'])->name('pwa.manifest');
Route::get('/pwa/icon-{size}-maskable.png', [PwaController::class, 'maskableIcon'])->whereNumber('size')->name('pwa.icon.maskable');
Route::get('/pwa/icon-{size}.png', [PwaController::class, 'icon'])->whereNumber('size')->name('pwa.icon');

Route::get('/', function () {
    if (! Auth::check()) {
        return redirect()->route('login');
    }

    // A role without dashboard access would bounce straight into a 403 here, so
    // send them to the first page they can actually open (profile always works).
    return redirect()->route(Auth::user()->hasPermission('dashboard.view') ? 'dashboard' : 'profil');
});

Route::middleware('guest')->group(function () {
    Route::get('/login', Login::class)->name('login');

    // Self-service password reset. The name 'password.reset' is what Laravel's
    // built-in ResetPassword notification builds its link from, so it must stay.
    // Step two of signing in. Sits in the guest group because nobody is
    // authenticated until the code checks out - all the page has is a short-lived
    // ticket in the session (see TwoFactorChallenge).
    Route::get('/verifikasi-dua-faktor', TwoFactorChallenge::class)->name('two-factor.challenge');

    Route::get('/lupa-kata-sandi', ForgotPassword::class)->name('password.request');
    Route::get('/atur-ulang-kata-sandi/{token}', ResetPassword::class)->name('password.reset');
});

Route::post('/logout', function () {
    AuditLog::record('logout', Auth::user()?->email);

    Auth::logout();
    request()->session()->invalidate();
    request()->session()->regenerateToken();

    return redirect()->route('login');
})->middleware('auth')->name('logout');

Route::middleware('tv.token')->group(function () {
    Route::get('/tv', [TvDisplayController::class, 'show'])->name('tv');
    Route::get('/tv/data', [TvDisplayController::class, 'data'])->name('tv.data');
});

Route::middleware('auth')->group(function () {
    // Own account: no permission gate, since every signed-in user - even one whose
    // role was taken away - must be able to see and change their own details.
    Route::get('/profil', ProfileIndex::class)->name('profil');

    // Each page is gated by the matching "{page}.view" permission from
    // App\Support\Permissions (registered as Gates in AppServiceProvider), so a
    // user without it gets a 403 even when typing the URL directly - the sidebar
    // just hides the link. The components authorize again in mount(), which is what
    // covers Livewire's own update requests.
    Route::get('/dashboard', Dashboard::class)->middleware('can:dashboard.view')->name('dashboard');

    Route::middleware('can:situs.view')->group(function () {
        Route::get('/situs', WebsitesIndex::class)->name('situs');
        Route::get('/situs/{website}', WebsiteShow::class)->name('situs.show');
    });

    Route::get('/insiden', IncidentsIndex::class)->middleware('can:insiden.view')->name('insiden');
    Route::middleware('can:deteksi-judol.view')->group(function () {
        Route::get('/deteksi-judol', JudolDetectionsIndex::class)->name('deteksi-judol');

        // Promo banners found at an injection site, proxied rather than hotlinked -
        // the page's CSP is img-src 'self', so a remote <img> never renders. See
        // JudolEvidenceController for why the image is addressed by index.
        Route::get('/deteksi-judol/{finding}/gambar/{index}', [JudolEvidenceController::class, 'image'])
            ->whereNumber('index')
            ->name('deteksi-judol.gambar');
    });
    Route::get('/notifikasi', NotificationsIndex::class)->middleware('can:notifikasi.view')->name('notifikasi');

    Route::middleware('can:laporan.view')->group(function () {
        Route::get('/laporan', ReportsIndex::class)->name('laporan');

        // Streamed rather than written to disk: the report is derived data, and a
        // file on the server would be one more copy of it to keep in step.
        Route::get('/laporan/unduh', function (MonthlyReport $report) {
            $month = Carbon::createFromFormat('Y-m', request()->query('bulan', now()->subMonthNoOverflow()->format('Y-m')))->startOfMonth();
            $site = request()->query('situs') ? Website::where('uuid', request()->query('situs'))->first() : null;

            $built = $report->build($month, $month->copy()->endOfMonth(), $site);
            $csv = $report->toCsv($built['rows']);

            AuditLog::record('laporan.diunduh', $month->format('Y-m'));

            return response()->streamDownload(
                fn () => print ($csv),
                'laporan-'.$month->format('Y-m').'.csv',
                ['Content-Type' => 'text/csv; charset=UTF-8'],
            );
        })->name('laporan.unduh');
    });

    Route::get('/audit', AuditIndex::class)->middleware('can:audit.view')->name('audit');

    Route::get('/roles', RolesIndex::class)->middleware('can:roles.view')->name('roles');
    Route::get('/users', UsersIndex::class)->middleware('can:users.view')->name('users');

    Route::get('/pengaturan', SettingsIndex::class)->middleware('can:pengaturan.view')->name('pengaturan');

    // Backups are served through PHP, never as static files: a dump holds every
    // account's password hash, so it must never sit under the public root.
    Route::get('/pengaturan/backup/{filename}', function (string $filename, DatabaseBackup $backup) {
        // Streamed rather than sent from disk: dumps are encrypted at rest (see
        // BackupCipher), so what the operator downloads is decrypted on the way out.
        abort_unless(($contents = $backup->contents($filename)) !== null, 404);

        // A dump holds every account's password hash, so who took a copy is exactly
        // the kind of thing the trail exists to answer.
        AuditLog::record('pengaturan.backup-diunduh', $filename);

        return response()->streamDownload(
            fn () => print ($contents),
            $backup->downloadName($filename),
            ['Content-Type' => 'application/gzip'],
        );
    })->middleware('can:pengaturan.update')->name('pengaturan.backup.download');
});
