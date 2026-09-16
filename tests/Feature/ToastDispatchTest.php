<?php

namespace Tests\Feature;

use App\Livewire\Users\Index as UsersIndex;
use App\Models\Role;
use App\Models\User;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ToastDispatchTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $role = Role::create(['name' => 'Admin Toast', 'permissions' => Permissions::all()]);

        return User::factory()->create(['role_id' => $role->id]);
    }

    public function test_creating_a_user_dispatches_a_success_toast(): void
    {
        Livewire::actingAs($this->admin())
            ->test(UsersIndex::class)
            ->call('openCreate')
            ->set('name', 'Toast Baru')
            ->set('email', 'toast.baru@example.test')
            ->set('password', 'Kata-Sandi-Panjang-123')
            ->set('passwordConfirmation', 'Kata-Sandi-Panjang-123')
            ->call('save')
            ->assertDispatched('toast', fn ($e, $p) => $p['type'] === 'success'
                && str_contains($p['message'], 'dibuat'));
    }

    public function test_deleting_your_own_account_dispatches_an_error_toast(): void
    {
        $me = $this->admin();

        Livewire::actingAs($me)
            ->test(UsersIndex::class)
            ->call('delete', $me->uuid)
            ->assertDispatched('toast', fn ($e, $p) => $p['type'] === 'error'
                && str_contains($p['message'], 'tidak bisa menghapus akun Anda sendiri'));
    }
}
