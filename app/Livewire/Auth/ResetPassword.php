<?php

namespace App\Livewire\Auth;

use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Step two: the page the emailed link lands on. The token comes from the URL and
 * is verified by Laravel's password broker, which also enforces its expiry
 * (config/auth.php: passwords.users.expire).
 */
#[Layout('components.layouts.guest')]
class ResetPassword extends Component
{
    public string $token = '';

    public string $email = '';

    public string $password = '';

    public string $passwordConfirmation = '';

    public function mount(string $token): void
    {
        $this->token = $token;
        // Prefilled from the link, but still submitted and verified against the
        // token - a mismatched pair is rejected by the broker.
        $this->email = (string) request()->query('email', '');
    }

    public function resetPassword(): void
    {
        $this->validate([
            'email' => ['required', 'string', 'email'],
            'password' => ['required', PasswordRule::defaults(), 'same:passwordConfirmation'],
            'passwordConfirmation' => ['required', 'string'],
        ], [
            'password.same' => 'Konfirmasi kata sandi tidak cocok.',
        ], [
            'password' => 'kata sandi baru',
            'passwordConfirmation' => 'konfirmasi kata sandi',
        ]);

        $status = Password::reset(
            [
                'email' => $this->email,
                'password' => $this->password,
                'password_confirmation' => $this->passwordConfirmation,
                'token' => $this->token,
            ],
            function (User $user) {
                // 'password' is a hashed cast on the model, so plain text in is fine.
                $user->forceFill([
                    'password' => $this->password,
                    'remember_token' => Str::random(60),
                ])->save();

                event(new PasswordReset($user));
            }
        );

        if ($status !== Password::PASSWORD_RESET) {
            $this->addError('email', match ($status) {
                Password::INVALID_TOKEN => 'Tautan reset sudah kedaluwarsa atau tidak berlaku. Silakan minta tautan baru.',
                Password::INVALID_USER => 'Tidak ada akun dengan email tersebut.',
                default => 'Kata sandi gagal diatur ulang. Silakan coba lagi.',
            });

            return;
        }

        session()->flash('status', 'Kata sandi berhasil diatur ulang. Silakan masuk dengan kata sandi baru Anda.');

        $this->redirectRoute('login');
    }

    public function render()
    {
        return view('livewire.auth.reset-password');
    }
}
