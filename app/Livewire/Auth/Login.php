<?php

namespace App\Livewire\Auth;

use App\Models\AuditLog;
use App\Services\Security\AccountAlerts;
use App\Services\Security\Recaptcha;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('components.layouts.guest')]
class Login extends Component
{
    public string $email = '';

    public string $password = '';

    /** Set by the reCAPTCHA v3 magic just before submit; empty when it's switched off. */
    public string $recaptchaToken = '';

    public function login(): void
    {
        $this->validate([
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ]);

        $this->ensureIsNotRateLimited();

        // Before the credentials are even looked at, so a scripted password spray
        // never reaches the hasher.
        $verdict = app(Recaptcha::class)->verify($this->recaptchaToken, 'login');

        if (! $verdict['ok']) {
            $this->reset('recaptchaToken');

            throw ValidationException::withMessages(['email' => $verdict['message']]);
        }

        // No "remember me": sessions always expire per SESSION_LIFETIME (config/session.php).
        if (! Auth::attempt(['email' => $this->email, 'password' => $this->password])) {
            RateLimiter::hit($this->throttleKey());

            // Attributed to nobody on purpose - the credentials did not identify
            // anyone. The attempted address and the IP are the whole point here:
            // a spray shows up as many lines from one IP across many addresses.
            AuditLog::record('login.gagal', $this->email);

            throw ValidationException::withMessages([
                'email' => 'Email atau kata sandi tidak cocok dengan data kami.',
            ]);
        }

        // Checked after the credentials, deliberately: answering "this account is
        // banned" to a wrong password would confirm the address exists.
        if (Auth::user()->isBanned()) {
            $reason = Auth::user()->banned_reason;
            AuditLog::record('login.ditolak', Auth::user()->email, ['alasan' => $reason ?: 'diblokir']);
            Auth::logout();

            throw ValidationException::withMessages([
                'email' => $reason
                    ? "Akun ini diblokir: {$reason}"
                    : 'Akun ini diblokir. Hubungi administrator.',
            ]);
        }

        RateLimiter::clear($this->throttleKey());

        // The password was right, but it is only the first factor. Sign the user
        // straight back out and hand a short-lived ticket to the challenge page -
        // an authenticated session must not exist until both factors are in.
        if (Auth::user()->hasTwoFactorEnabled()) {
            $pendingId = Auth::id();
            Auth::logout();

            session()->put([
                'auth.2fa.user_id' => $pendingId,
                'auth.2fa.started_at' => now()->timestamp,
            ]);

            $this->redirectRoute('two-factor.challenge');

            return;
        }

        // Before the audit line, or this address would already look familiar.
        app(AccountAlerts::class)->notifyIfNewDevice(Auth::user());

        AuditLog::record('login.berhasil', Auth::user()->email);

        // session() rather than request()->session(): the helper resolves the
        // shared store, which is there whether or not a session store was bound
        // onto this particular request object.
        session()->regenerate();

        // A role without dashboard access would land straight on a 403.
        $this->redirectRoute(Auth::user()->hasPermission('dashboard.view') ? 'dashboard' : 'profil');
    }

    protected function ensureIsNotRateLimited(): void
    {
        if (! RateLimiter::tooManyAttempts($this->throttleKey(), 5)) {
            return;
        }

        event(new Lockout(request()));

        $seconds = RateLimiter::availableIn($this->throttleKey());

        throw ValidationException::withMessages([
            'email' => "Terlalu banyak percobaan. Coba lagi dalam {$seconds} detik.",
        ]);
    }

    protected function throttleKey(): string
    {
        return Str::transliterate(Str::lower($this->email).'|'.request()->ip());
    }

    public function render()
    {
        return view('livewire.auth.login');
    }
}
