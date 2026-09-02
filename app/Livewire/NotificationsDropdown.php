<?php

namespace App\Livewire;

use Livewire\Component;

class NotificationsDropdown extends Component
{
    public function markAllAsRead(): void
    {
        auth()->user()->unreadNotifications->markAsRead();
    }

    /**
     * Marks one notification read and sends the reader where it points.
     *
     * Opening a notification is what "reading" it means, so making that a separate
     * deliberate action was busywork - the bell now empties as you work through it.
     * Returns a redirect rather than letting the row be a plain <a>, because the
     * read has to be recorded server-side before the navigation happens.
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

    public function render()
    {
        return view('livewire.notifications-dropdown', [
            'notifications' => auth()->user()->notifications()->latest()->limit(6)->get(),
            'unreadCount' => auth()->user()->unreadNotifications()->count(),
        ]);
    }
}
