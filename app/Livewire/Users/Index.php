<?php

namespace App\Livewire\Users;

use App\Models\Role;
use App\Models\User;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('components.layouts.app')]
class Index extends Component
{
    use WithPagination;

    #[Url]
    public string $search = '';

    #[Url]
    public string $roleFilter = '';

    /** '' = semua, 'active' = tidak diblokir, 'banned' = diblokir. */
    #[Url]
    public string $statusFilter = '';

    public bool $showBanModal = false;

    public ?User $banning = null;

    public string $banReason = '';

    public bool $showModal = false;

    public ?User $editing = null;

    public string $name = '';

    public string $email = '';

    /** Blank while editing means "keep the current password". */
    public string $password = '';

    public string $passwordConfirmation = '';

    /** The chosen role's uuid - no real primary key ever reaches the browser. */
    public ?string $roleUuid = null;

    public function mount(): void
    {
        $this->authorize('users.view');
    }

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function updatingRoleFilter(): void
    {
        $this->resetPage();
    }

    public function updatingStatusFilter(): void
    {
        $this->resetPage();
    }

    public function openBan(User $user): void
    {
        $this->authorize('users.update');
        $this->banning = $user;
        $this->banReason = '';
        $this->resetValidation();
        $this->showBanModal = true;
    }

    /**
     * Blocks the account without deleting it: the user, their notifications, and
     * everything they did stay on record, they just can't sign in - and any
     * session they already had ends on its next request (EnsureUserIsNotBanned).
     */
    public function ban(): void
    {
        $this->authorize('users.update');

        $this->validate(['banReason' => ['nullable', 'string', 'max:255']]);

        $user = $this->banning;

        if (! $user) {
            return;
        }

        if ($user->is(auth()->user())) {
            session()->flash('status', 'Anda tidak bisa memblokir akun Anda sendiri.');
            $this->showBanModal = false;

            return;
        }

        if ($user->isSuperadmin() && $this->isLastActiveSuperadmin($user)) {
            session()->flash('status', 'Ini satu-satunya superadmin yang aktif - blokir akan mengunci semua orang di luar.');
            $this->showBanModal = false;

            return;
        }

        $user->update(['banned_at' => now(), 'banned_reason' => $this->banReason ?: null]);

        $this->showBanModal = false;
        $this->banning = null;
        session()->flash('status', "Akun {$user->name} diblokir.");
    }

    public function unban(User $user): void
    {
        $this->authorize('users.update');

        $user->update(['banned_at' => null, 'banned_reason' => null]);
        session()->flash('status', "Blokir akun {$user->name} dicabut.");
    }

    public function openCreate(): void
    {
        $this->authorize('users.create');
        $this->reset(['editing', 'name', 'email', 'password', 'passwordConfirmation', 'roleUuid']);
        $this->resetValidation();
        $this->showModal = true;
    }

    public function openEdit(User $user): void
    {
        $this->authorize('users.update');
        $this->editing = $user;
        $this->name = $user->name;
        $this->email = $user->email;
        $this->roleUuid = $user->role?->uuid;
        $this->password = '';
        $this->passwordConfirmation = '';
        $this->resetValidation();
        $this->showModal = true;
    }

    protected function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($this->editing?->id)],
            // Required when creating; on edit, an empty box just leaves the password
            // alone. 'confirmed' expects a "password_confirmation" field, which a
            // camelCase Livewire property isn't - so the pair is matched with 'same'.
            'password' => [$this->editing ? 'nullable' : 'required', Password::defaults(), 'same:passwordConfirmation'],
            'passwordConfirmation' => ['nullable', 'string'],
            'roleUuid' => ['nullable', 'string', Rule::exists('roles', 'uuid')],
        ];
    }

    protected function validationAttributes(): array
    {
        return [
            'roleUuid' => 'role',
            'password' => 'kata sandi',
            'passwordConfirmation' => 'konfirmasi kata sandi',
        ];
    }

    protected function messages(): array
    {
        return ['password.same' => 'Konfirmasi kata sandi tidak cocok.'];
    }

    public function save(): void
    {
        $this->authorize($this->editing ? 'users.update' : 'users.create');

        $data = $this->validate();

        $user = $this->editing ?? new User;
        $user->fill([
            'name' => $data['name'],
            'email' => $data['email'],
            'role_id' => $data['roleUuid'] ? Role::where('uuid', $data['roleUuid'])->value('id') : null,
        ]);

        if ($this->password !== '') {
            // 'password' is a hashed cast on the model, so plain text in is fine.
            $user->password = $this->password;
        }

        $user->save();

        $this->showModal = false;
        session()->flash('status', $this->editing ? "Pengguna {$user->name} diperbarui." : "Pengguna {$user->name} dibuat.");
        $this->editing = null;
    }

    public function delete(User $user): void
    {
        $this->authorize('users.delete');

        // Deleting yourself would log you out mid-request and, if you were the last
        // superadmin, leave nobody able to hand the role back out.
        if ($user->is(auth()->user())) {
            session()->flash('status', 'Anda tidak bisa menghapus akun Anda sendiri.');

            return;
        }

        if ($user->isSuperadmin() && $this->isLastActiveSuperadmin($user)) {
            session()->flash('status', 'Ini satu-satunya akun superadmin - buat penggantinya dulu sebelum menghapusnya.');

            return;
        }

        $name = $user->name;
        $user->delete();
        session()->flash('status', "Pengguna {$name} dihapus.");
    }

    /** No banned account counts here - a blocked superadmin can't let anyone back in. */
    protected function isLastActiveSuperadmin(User $user): bool
    {
        return User::notBanned()
            ->whereHas('role', fn ($q) => $q->where('is_superadmin', true))
            ->whereKeyNot($user->getKey())
            ->doesntExist();
    }

    public function render()
    {
        $users = User::query()
            ->with('role')
            ->when($this->search, fn ($q) => $q->where(fn ($q) => $q
                ->where('name', 'like', "%{$this->search}%")
                ->orWhere('email', 'like', "%{$this->search}%")))
            ->when($this->roleFilter !== '', fn ($q) => $this->roleFilter === 'none'
                ? $q->whereNull('role_id')
                : $q->whereRelation('role', 'uuid', $this->roleFilter))
            ->when($this->statusFilter === 'banned', fn ($q) => $q->banned())
            ->when($this->statusFilter === 'active', fn ($q) => $q->notBanned())
            ->latest()
            ->paginate(10);

        return view('livewire.users.index', [
            'users' => $users,
            'roles' => Role::orderByDesc('is_superadmin')->orderBy('name')->get(),
        ]);
    }
}
