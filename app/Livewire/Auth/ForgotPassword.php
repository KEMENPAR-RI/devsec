<?php

namespace App\Livewire\Auth;

use App\Models\User;
use App\Services\Notifications\MailSettings;
use App\Services\Security\Recaptcha;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Throwable;

/**
 * Step one of self-service password reset: emails a signed, expiring link.
 *
 * The response is deliberately identical whether or not the address exists (and
 * whether or not the account is banned), so this page can't be used to find out
 * which addresses have accounts here.
 */
#[Layout('components.layouts.guest')]
class ForgotPassword extends Component
{
    public string $email = '';

    public bool $sent = false;

    /** Set by the reCAPTCHA v3 magic just before submit; empty when it's switched off. */
    public string $recaptchaToken = '';

    public function sendResetLink(): void
    {
        $this->validate([
            'email' => ['required', 'string', 'email'],
        ]);

        $mail = app(MailSettings::class);

        // Not account-specific, so saying it out loud leaks nothing - and it beats
        // a "link sent!" for a link that was never going anywhere.
        if (! $mail->isConfigured()) {
            $this->addError('email', 'Pengiriman email belum dikonfigurasi di aplikasi ini. Hubungi administrator.');

            return;
        }

        // This form sends real email, so it is worth the same gate as login.
        $verdict = app(Recaptcha::class)->verify($this->recaptchaToken, 'password_reset');

        if (! $verdict['ok']) {
            $this->reset('recaptchaToken');
            $this->addError('email', $verdict['message']);

            return;
        }

        $this->ensureIsNotRateLimited();
        RateLimiter::hit($this->throttleKey(), 900);

        // Uses whatever SMTP credentials are saved on the Pengaturan page, the same
        // as every other mail this app sends.
        $mail->applyToRuntime();

        // A banned account must not be able to walk back in through a reset link.
        $user = User::where('email', $this->email)->first();

        if ($user && ! $user->isBanned()) {
            try {
                Password::sendResetLink(['email' => $this->email]);
            } catch (Throwable $e) {
                // An SMTP rejection is a server problem, not a wrong-email problem;
                // reporting it is what stops "the link never arrives" being silent.
                Log::error('Gagal mengirim tautan reset kata sandi: '.$e->getMessage());

                $this->addError('email', 'Email gagal dikirim: '.$e->getMessage());

                return;
            }
        }

        $this->sent = true;
    }

    protected function ensureIsNotRateLimited(): void
    {
        // Low ceiling on purpose: each attempt sends an email, so this is both an
        // enumeration guard and a way to keep the SMTP account from being abused.
        if (! RateLimiter::tooManyAttempts($this->throttleKey(), 3)) {
            return;
        }

        $minutes = ceil(RateLimiter::availableIn($this->throttleKey()) / 60);

        throw ValidationException::withMessages([
            'email' => "Terlalu banyak permintaan. Coba lagi dalam {$minutes} menit.",
        ]);
    }

    protected function throttleKey(): string
    {
        return 'reset|'.Str::transliterate(Str::lower($this->email).'|'.request()->ip());
    }

    public function render()
    {
        return view('livewire.auth.forgot-password');
    }
}
