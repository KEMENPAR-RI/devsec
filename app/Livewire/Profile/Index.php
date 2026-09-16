<?php

namespace App\Livewire\Profile;

use App\Models\AuditLog;
use App\Services\Security\AccountAlerts;
use App\Services\Security\ActiveSessions;
use App\Services\Security\TwoFactor;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * The signed-in user's own account page. Deliberately needs no permission at all:
 * everyone who can log in can read and change their own name, email, and password,
 * regardless of role - including a user whose role was removed.
 */
#[Layout('components.layouts.app')]
class Index extends Component
{
    public string $name = '';

    public string $email = '';

    public string $currentPassword = '';

    public string $password = '';

    public string $passwordConfirmation = '';

    // --- Two-factor enrolment ---------------------------------------------

    /** The secret being enrolled, held only until the first code confirms it. */
    public ?string $pendingSecret = null;

    public string $twoFactorCode = '';

    /** Shown exactly once, right after enrolling or regenerating. */
    public ?array $freshRecoveryCodes = null;

    /** Proving the password again before switching the second factor off. */
    public string $disablePassword = '';

    /** Same proof, for ending every other session. */
    public string $sessionPassword = '';

    public function mount(): void
    {
        $this->name = Auth::user()->name;
        $this->email = Auth::user()->email;
    }

    public function updateProfile(): void
    {
        $user = Auth::user();

        $data = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
        ]);

        $user->fill($data)->save();

        session()->flash('profileStatus', 'Profil berhasil diperbarui.');
    }

    public function updatePassword(): void
    {
        $user = Auth::user();

        $this->validate([
            // Proving you know the old one is what stops a walked-away-from session
            // being turned into a permanent takeover.
            'currentPassword' => ['required', 'string'],
            'password' => ['required', Password::defaults(), 'same:passwordConfirmation'],
            'passwordConfirmation' => ['required', 'string'],
        ], [
            'password.same' => 'Konfirmasi kata sandi tidak cocok.',
        ], [
            'currentPassword' => 'kata sandi saat ini',
            'password' => 'kata sandi baru',
            'passwordConfirmation' => 'konfirmasi kata sandi',
        ]);

        if (! Hash::check($this->currentPassword, $user->password)) {
            $this->addError('currentPassword', 'Kata sandi saat ini salah.');

            return;
        }

        // 'password' is a hashed cast on the User model, so plain text in is fine.
        $user->password = $this->password;
        $user->save();

        // A password is changed because the old one may be known to somebody
        // else - and a session opened with the old one keeps working until it
        // expires unless it is ended here.
        $ended = app(ActiveSessions::class)->revokeOthers($user);

        AuditLog::record('users.kata-sandi-diubah', $user->email, $ended ? ['sesi lain diakhiri' => $ended] : []);
        app(AccountAlerts::class)->notifyPasswordChanged($user);

        $this->reset(['currentPassword', 'password', 'passwordConfirmation']);
        session()->flash('passwordStatus', $ended
            ? "Kata sandi berhasil diperbarui. {$ended} sesi lain ikut dikeluarkan."
            : 'Kata sandi berhasil diperbarui.');
    }

    // --- Active sessions ---------------------------------------------------

    /**
     * Ends every session but this one. Costs the password, same as changing it:
     * the button exists for "somebody else is signed in as me", and a walked-away
     * -from screen must not be able to lock the real owner out instead.
     */
    public function logoutOtherDevices(): void
    {
        $user = Auth::user();

        $this->validate(
            ['sessionPassword' => ['required', 'string']],
            [],
            ['sessionPassword' => 'kata sandi']
        );

        if (! Hash::check($this->sessionPassword, $user->password)) {
            $this->addError('sessionPassword', 'Kata sandi salah.');

            return;
        }

        $ended = app(ActiveSessions::class)->revokeOthers($user);

        AuditLog::record('users.sesi-lain-dikeluarkan', $user->email, ['jumlah' => $ended]);

        $this->reset('sessionPassword');
        session()->flash('sessionStatus', $ended
            ? "{$ended} sesi lain berhasil dikeluarkan."
            : 'Tidak ada sesi lain yang sedang aktif.');
    }

    // --- Two-factor enrolment ---------------------------------------------

    /**
     * Starts an enrolment. The secret lives on the component until a working code
     * proves the authenticator actually has it - writing it to the user first
     * would create an account that can be locked out by a mistyped scan.
     */
    public function startTwoFactor(): void
    {
        $this->resetErrorBag();
        $this->freshRecoveryCodes = null;
        $this->twoFactorCode = '';
        $this->pendingSecret = app(TwoFactor::class)->generateSecret();
    }

    public function cancelTwoFactor(): void
    {
        $this->reset(['pendingSecret', 'twoFactorCode']);
        $this->resetErrorBag();
    }

    public function confirmTwoFactor(): void
    {
        $twoFactor = app(TwoFactor::class);

        if (! $this->pendingSecret) {
            return;
        }

        if (! $twoFactor->verify($this->pendingSecret, $this->twoFactorCode)) {
            $this->addError('twoFactorCode', 'Kode tidak cocok. Pastikan jam perangkat Anda tepat, lalu coba kode terbaru.');

            return;
        }

        $codes = $twoFactor->generateRecoveryCodes();

        Auth::user()->forceFill([
            'two_factor_secret' => $this->pendingSecret,
            'two_factor_recovery_codes' => $codes,
            'two_factor_confirmed_at' => now(),
        ])->save();

        AuditLog::record('users.2fa-diaktifkan', Auth::user()->email);

        $this->reset(['pendingSecret', 'twoFactorCode']);
        $this->freshRecoveryCodes = $codes;
    }

    /**
     * Issues a new set and invalidates the old one. Used after a code is spent, or
     * when the saved copy is no longer trusted.
     */
    public function regenerateRecoveryCodes(): void
    {
        $user = Auth::user();

        if (! $user->hasTwoFactorEnabled()) {
            return;
        }

        $codes = app(TwoFactor::class)->generateRecoveryCodes();
        $user->forceFill(['two_factor_recovery_codes' => $codes])->save();

        AuditLog::record('users.2fa-kode-pemulihan-dibuat-ulang', $user->email);

        $this->freshRecoveryCodes = $codes;
    }

    /**
     * Turning the second factor off is a security downgrade, so it costs the
     * password - the same bar as changing it. A walked-away-from session must not
     * be enough to strip the protection and then take the account over.
     */
    public function disableTwoFactor(): void
    {
        $user = Auth::user();

        $this->validate(
            ['disablePassword' => ['required', 'string']],
            [],
            ['disablePassword' => 'kata sandi']
        );

        if (! Hash::check($this->disablePassword, $user->password)) {
            $this->addError('disablePassword', 'Kata sandi salah.');

            return;
        }

        $user->disableTwoFactor();

        AuditLog::record('users.2fa-dinonaktifkan', $user->email);
        app(AccountAlerts::class)->notifyTwoFactorDisabled($user);

        $this->reset(['disablePassword', 'pendingSecret', 'twoFactorCode', 'freshRecoveryCodes']);
        session()->flash('twoFactorStatus', 'Verifikasi dua faktor dinonaktifkan.');
    }

    public function render()
    {
        $user = Auth::user()->loadMissing('role');
        $twoFactor = app(TwoFactor::class);

        $sessions = app(ActiveSessions::class);

        return view('livewire.profile.index', [
            'user' => $user,
            'sessionsSupported' => $sessions->isSupported(),
            'activeSessions' => $sessions->forUser($user),
            'twoFactorEnabled' => $user->hasTwoFactorEnabled(),
            'remainingRecoveryCodes' => count($user->recoveryCodes()),
            'qrCodeSvg' => $this->pendingSecret ? $twoFactor->qrCodeSvg($user, $this->pendingSecret) : null,
            'formattedSecret' => $this->pendingSecret ? $twoFactor->formatSecret($this->pendingSecret) : null,
        ]);
    }
}
