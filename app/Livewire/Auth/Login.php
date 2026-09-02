<?php

namespace App\Livewire\Auth;

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

            throw ValidationException::withMessages([
                'email' => 'Email atau kata sandi tidak cocok dengan data kami.',
            ]);
        }

        // Checked after the credentials, deliberately: answering "this account is
        // banned" to a wrong password would confirm the address exists.
        if (Auth::user()->isBanned()) {
            $reason = Auth::user()->banned_reason;
            Auth::logout();

            throw ValidationException::withMessages([
                'email' => $reason
                    ? "Akun ini diblokir: {$reason}"
                    : 'Akun ini diblokir. Hubungi administrator.',
            ]);
        }

        RateLimiter::clear($this->throttleKey());
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
