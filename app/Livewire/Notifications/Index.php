<?php

namespace App\Livewire\Notifications;

use App\Livewire\Concerns\InteractsWithToasts;
use App\Models\Website;
use App\Support\DailyGroups;
use Illuminate\Notifications\DatabaseNotification;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('components.layouts.app')]
class Index extends Component
{
    use InteractsWithToasts;
    use WithPagination;

    #[Url]
    public string $search = '';

    public function mount(): void
    {
        $this->authorize('notifikasi.view');
    }

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function markAllAsRead(): void
    {
        $this->authorize('notifikasi.update');

        auth()->user()->unreadNotifications->markAsRead();
    }

    /**
     * Wipes every notification belonging to the current user (never other users' -
     * this is per-user data unlike the rest of the app's shared monitoring data).
     * Bound to both "Reset" and "Hapus Data" in the view.
     */
    /**
     * Opening a notification is what reading it means, so the click records the
     * read before following the link - same behaviour as the bell dropdown.
     */
    public function open(string $id)
    {
        $notification = auth()->user()->notifications()->whereKey($id)->first();

        if (! $notification) {
            return null;
        }

        $notification->markAsRead();

        return $this->redirect($notification->data['url'] ?? route('notifikasi'), navigate: true);
    }

    public function delete(string $id): void
    {
        $this->authorize('notifikasi.delete');

        // Scoped to the signed-in user: notification ids are uuids, but they are
        // still somebody's, and one operator must not be able to clear another's.
        auth()->user()->notifications()->whereKey($id)->delete();

        $this->toastSuccess('Notifikasi dihapus.');
    }

    public function deleteAll(): void
    {
        $this->authorize('notifikasi.delete');

        auth()->user()->notifications()->delete();

        $this->resetPage();
        $this->toastSuccess('Semua notifikasi berhasil dihapus.');
    }

    /**
     * The current user's notifications, filtered - before grouping by (site, day).
     */
    protected function baseQuery()
    {
        return auth()->user()->notifications()
            ->when($this->search, fn ($q) => $q->where(fn ($q) => $q
                ->where('data->title', 'like', "%{$this->search}%")
                ->orWhere('data->message', 'like', "%{$this->search}%")));
    }

    public function render()
    {
        ['groups' => $groups, 'rows' => $rows] = DailyGroups::forPage(
            query: fn () => $this->baseQuery(),
            dateColumn: 'created_at',
            rowKey: fn (DatabaseNotification $notification) => DailyGroups::key(
                $notification->data['website_id'] ?? null,
                $notification->created_at->toDateString(),
            ),
            // website_id lives inside the notification payload, not in its own column.
            siteKeyExpression: "json_extract(data, '$.website_id')",
            siteFilterColumn: 'data->website_id',
        );

        return view('livewire.notifications.index', [
            'groups' => $groups,
            'notificationsByGroup' => $rows,
            'websites' => Website::whereIn('id', $groups->pluck('site_key'))->get()->keyBy('id'),
        ]);
    }
}
