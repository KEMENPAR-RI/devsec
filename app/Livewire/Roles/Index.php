<?php

namespace App\Livewire\Roles;

use App\Models\Role;
use App\Support\Permissions;
use Illuminate\Validation\Rule;
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

    public bool $showModal = false;

    public ?Role $editing = null;

    public string $name = '';

    public string $slug = '';

    public string $description = '';

    /**
     * Checked boxes in the permission matrix, as permission names
     * ("situs.update"). wire:model binds each checkbox's value straight into this.
     *
     * @var array<int, string>
     */
    public array $permissions = [];

    public function mount(): void
    {
        $this->authorize('roles.view');
    }

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function openCreate(): void
    {
        $this->authorize('roles.create');
        $this->reset(['editing', 'name', 'slug', 'description', 'permissions']);
        $this->resetValidation();
        $this->showModal = true;
    }

    public function openEdit(Role $role): void
    {
        $this->authorize('roles.update');
        $this->editing = $role;
        $this->name = $role->name;
        $this->slug = $role->slug;
        $this->description = (string) $role->description;
        $this->permissions = $role->grantedPermissions();
        $this->resetValidation();
        $this->showModal = true;
    }

    /** Ticks every permission there is, or clears the lot - whichever is missing. */
    public function toggleAll(): void
    {
        $this->permissions = count($this->permissions) === count(Permissions::all())
            ? []
            : Permissions::all();
    }

    /** Ticks or clears every ability of one page at once. */
    public function togglePage(string $page): void
    {
        $abilities = collect(Permissions::pages()[$page]['abilities'] ?? [])
            ->map(fn (string $ability) => "{$page}.{$ability}");

        $this->permissions = $abilities->every(fn (string $p) => in_array($p, $this->permissions, true))
            ? array_values(array_diff($this->permissions, $abilities->all()))
            : array_values(array_unique([...$this->permissions, ...$abilities->all()]));
    }

    protected function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'slug' => [
                'required', 'string', 'max:255', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                Rule::unique('roles', 'slug')->ignore($this->editing?->id),
            ],
            'description' => ['nullable', 'string', 'max:255'],
            'permissions' => ['array'],
            'permissions.*' => ['string', Rule::in(Permissions::all())],
        ];
    }

    protected function messages(): array
    {
        return ['slug.regex' => 'Slug hanya boleh huruf kecil, angka, dan tanda hubung (contoh: operator-situs).'];
    }

    public function save(): void
    {
        $this->authorize($this->editing ? 'roles.update' : 'roles.create');

        $this->slug = $this->slug ?: str($this->name)->slug()->value();

        $data = $this->validate();

        // A locked role (Superadmin, Admin) keeps its identity: the name and slug
        // are what the seeder and the rest of the app refer to it by, so only its
        // description and permission set are editable here.
        $role = $this->editing ?? new Role;
        $role->fill([
            ...($role->is_locked ? [] : ['name' => $data['name'], 'slug' => $data['slug']]),
            'description' => $data['description'] ?: null,
            'permissions' => Permissions::sanitize($data['permissions'] ?? []),
        ]);
        $role->save();

        $this->showModal = false;
        session()->flash('status', $this->editing ? "Role {$role->name} diperbarui." : "Role {$role->name} dibuat.");
        $this->editing = null;
    }

    public function delete(Role $role): void
    {
        $this->authorize('roles.delete');

        if ($role->is_locked) {
            session()->flash('status', "Role {$role->name} bawaan sistem dan tidak bisa dihapus.");

            return;
        }

        // Users keep working, just without permissions, until someone gives them a
        // new role - the FK is nullOnDelete, so this never orphans an account.
        $role->delete();
        session()->flash('status', "Role {$role->name} dihapus.");
    }

    public function render()
    {
        $roles = Role::query()
            ->withCount('users')
            ->when($this->search, fn ($q) => $q->where(fn ($q) => $q
                ->where('name', 'like', "%{$this->search}%")
                ->orWhere('slug', 'like', "%{$this->search}%")))
            // Superadmin stays pinned at the top; the rest newest first.
            ->orderByDesc('is_superadmin')
            ->latest()
            ->paginate(10);

        return view('livewire.roles.index', [
            'roles' => $roles,
            'pages' => Permissions::pages(),
            'abilities' => Permissions::ABILITIES,
        ]);
    }
}
