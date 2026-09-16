<?php

namespace App\Livewire\Auth;

use App\Models\AuditLog;
use App\Models\User;
use App\Services\Security\AccountAlerts;
use App\Services\Security\TwoFactor;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Step two of signing in: the code from the authenticator app.
 *
 * Nobody is authenticated while this page is open. All that exists is a
 * short-lived ticket in the session naming the account whose password checked
 * out - so an abandoned tab is not a half-open session, and closing the browser
 * simply means starting over.
 */
#[Layout('components.layouts.guest')]
class TwoFactorChallenge extends Component
{
    /** How long the password step stays good for, in seconds. */
    protected const TICKET_LIFETIME = 300;

    public string $code = '';

    public string $recoveryCode = '';

    /** Switches the form between the six-digit code and a recovery code. */
    public bool $useRecovery = false;

    public function mount()
    {
        if (! $this->pendingUser()) {
            return $this->redirectRoute('login', navigate: true);
        }

        return null;
    }

    public function toggleRecovery(): void
    {
        $this->useRecovery = ! $this->useRecovery;
        $this->reset(['code', 'recoveryCode']);
        $this->resetErrorBag();
    }

    public function verify()
    {
        $user = $this->pendingUser();

        if (! $user) {
            return $this->redirectRoute('login', navigate: true);
        }

        $this->ensureIsNotRateLimited($user);

        $accepted = $this->useRecovery
            ? $user->consumeRecoveryCode($this->recoveryCode)
            : app(TwoFactor::class)->verify($user->two_factor_secret, $this->code);

        if (! $accepted) {
            RateLimiter::hit($this->throttleKey($user));

            AuditLog::record('login.2fa-gagal', $user->email);

            throw ValidationException::withMessages([
                $this->useRecovery ? 'recoveryCode' : 'code' => $this->useRecovery
                    ? 'Kode pemulihan tidak dikenali atau sudah pernah dipakai.'
                    : 'Kode verifikasi salah atau sudah kedaluwarsa.',
            ]);
        }

        RateLimiter::clear($this->throttleKey($user));

        // The ticket is spent before the session is regenerated, so a replay of the
        // same request cannot sign anyone in a second time.
        session()->forget(['auth.2fa.user_id', 'auth.2fa.started_at']);

        app(AccountAlerts::class)->notifyIfNewDevice($user);

        Auth::loginUsingId($user->id);
        session()->regenerate();

        AuditLog::record(
            'login.berhasil',
            $user->email,
            ['metode' => $this->useRecovery ? 'kode pemulihan' : 'aplikasi autentikator']
        );

        // Spending a recovery code is worth saying out loud: it means the
        // authenticator was unavailable, which is either a lost phone or somebody
        // else holding the codes.
        if ($this->useRecovery) {
            session()->flash('profileStatus', 'Anda masuk memakai kode pemulihan. Sisa kode: '.count($user->recoveryCodes()).'. Buat ulang kode pemulihan bila perlu.');
        }

        return $this->redirectRoute($user->hasPermission('dashboard.view') ? 'dashboard' : 'profil');
    }

    /**
     * The account waiting on its second factor, or null when there is no valid
     * ticket - never started, already expired, banned, or 2FA turned off since.
     */
    protected function pendingUser(): ?User
    {
        $id = session('auth.2fa.user_id');
        $startedAt = (int) session('auth.2fa.started_at');

        if (! $id || now()->timestamp - $startedAt > self::TICKET_LIFETIME) {
            session()->forget(['auth.2fa.user_id', 'auth.2fa.started_at']);

            return null;
        }

        $user = User::find($id);

        return $user && ! $user->isBanned() && $user->hasTwoFactorEnabled() ? $user : null;
    }

    protected function ensureIsNotRateLimited(User $user): void
    {
        if (! RateLimiter::tooManyAttempts($this->throttleKey($user), 5)) {
            return;
        }

        $seconds = RateLimiter::availableIn($this->throttleKey($user));

        throw ValidationException::withMessages([
            'code' => "Terlalu banyak percobaan. Coba lagi dalam {$seconds} detik.",
        ]);
    }

    /**
     * Keyed by account and IP, separately from the password throttle: six digits is
     * a small space, and without its own limiter someone who already has the
     * password gets unlimited guesses at the factor meant to stop them.
     */
    protected function throttleKey(User $user): string
    {
        return '2fa|'.$user->id.'|'.Str::lower(request()->ip());
    }

    public function render()
    {
        return view('livewire.auth.two-factor-challenge');
    }
}
