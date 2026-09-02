<?php

namespace App\Livewire\Profile;

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
            'password' => ['required', Password::min(8), 'same:passwordConfirmation'],
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

        $this->reset(['currentPassword', 'password', 'passwordConfirmation']);
        session()->flash('passwordStatus', 'Kata sandi berhasil diperbarui.');
    }

    public function render()
    {
        return view('livewire.profile.index', [
            'user' => Auth::user()->loadMissing('role'),
        ]);
    }
}
