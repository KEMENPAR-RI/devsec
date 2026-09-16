<div class="mx-auto flex max-w-3xl flex-col gap-6">
    <div class="enter flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
        <div>
            <h1 class="text-2xl font-semibold tracking-tight text-zinc-900 dark:text-white">Notifikasi</h1>
            <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">Dikelompokkan per situs per hari - satu situs yang berulang kali down tidak lagi memenuhi seluruh halaman.</p>
        </div>
        <div class="flex shrink-0 flex-wrap items-center gap-2 self-start sm:self-auto">
            @can('notifikasi.update')
                <button
                    type="button"
                    wire:click="markAllAsRead"
                    wire:loading.attr="disabled"
                    wire:target="markAllAsRead"
                    class="inline-flex items-center whitespace-nowrap rounded-lg border border-zinc-200 px-3.5 py-2 text-sm font-medium text-zinc-600 transition hover:bg-zinc-50 disabled:opacity-60 dark:border-zinc-700 dark:text-zinc-300 dark:hover:bg-zinc-800"
                >
                    <x-loading-label target="markAllAsRead" gap="gap-1.5">
                        Tandai semua dibaca
                    </x-loading-label>
                </button>
            @endcan
            @can('notifikasi.delete')
                <x-confirm-dialog
                    title="Hapus Semua Notifikasi?"
                    message="Semua notifikasi Anda akan dihapus. Tindakan ini tidak bisa dibatalkan."
                    confirm-text="Ya, Hapus Semua"
                    variant="danger"
                    target="deleteAll"
                >
                    <x-slot:trigger>
                        <button type="button" class="inline-flex items-center gap-1.5 rounded-lg border border-red-200 px-3.5 py-2 text-sm font-medium text-red-600 transition hover:bg-red-50 dark:border-red-500/30 dark:text-red-400 dark:hover:bg-red-500/10">
                            <x-phosphor-trash class="h-4 w-4" />
                            Hapus Data
                        </button>
                    </x-slot:trigger>
                    <form wire:submit="deleteAll" x-ref="form"></form>
                </x-confirm-dialog>
            @endcan
        </div>
    </div>

    @php
        $emptyMessage = $search
            ? "Tidak ada notifikasi yang cocok dengan \"{$search}\"."
            : 'Belum ada notifikasi.';
    @endphp

    <x-list-card
        :is-empty="$groups->isEmpty()"
        empty-icon="phosphor-bell"
        :empty-message="$emptyMessage"
    >
        <x-slot:toolbar>
            <x-search-input model="search" placeholder="Cari judul atau pesan..." />
        </x-slot:toolbar>

        <div class="divide-y divide-zinc-100 transition-opacity dark:divide-zinc-800" wire:loading.class="opacity-40" wire:target="previousPage,nextPage,gotoPage,search">
            @foreach ($groups as $group)
                @php
                    $site = $websites[$group->site_key] ?? null;
                    $items = $notificationsByGroup[\App\Support\DailyGroups::key($group->site_key, $group->day)] ?? collect();
                    $unreadCount = $items->whereNull('read_at')->count();
                    $hasCritical = $items->contains(fn ($n) => ($n->data['severity'] ?? null) === 'critical');
                @endphp

                <x-list-group
                    :title="$site?->name ?? 'Situs sudah dihapus'"
                    :href="$site ? route('situs.show', $site) : null"
                    :day="\Illuminate\Support\Carbon::parse($group->day)"
                    :count="$group->total"
                    unit="notifikasi"
                    :open="$loop->first"
                    :tone="$unreadCount ? ($hasCritical ? 'red' : 'amber') : 'zinc'"
                    icon="phosphor-bell"
                >
                    <x-slot:summary>
                        @if ($unreadCount)
                            <span class="rounded-full bg-accent-50 px-2 py-0.5 text-xs font-medium text-accent-700 dark:bg-accent-500/10 dark:text-accent-400">{{ $unreadCount }} belum dibaca</span>
                        @endif
                    </x-slot:summary>

                    <div class="divide-y divide-zinc-100 dark:divide-zinc-800">
                        @foreach ($items as $notification)
                            {{-- The row and the delete control are siblings: a <button>
                            nested inside an <a> is invalid HTML and behaves badly. --}}
                            <div @class([
                                'flex items-start transition hover:bg-white dark:hover:bg-zinc-900/40',
                                'bg-accent-50/30 dark:bg-accent-500/5' => is_null($notification->read_at),
                            ])>
                            <button
                                type="button"
                                wire:click="open('{{ $notification->id }}')"
                                wire:loading.attr="disabled"
                                wire:target="open('{{ $notification->id }}')"
                                class="flex min-w-0 flex-1 items-start gap-3 py-3.5 pl-5 pr-3 text-left disabled:opacity-60 sm:pl-[4.25rem]"
                            >
                                <span @class([
                                    'mt-1.5 h-2 w-2 shrink-0 rounded-full',
                                    'bg-transparent' => ! is_null($notification->read_at),
                                    'bg-red-500' => is_null($notification->read_at) && ($notification->data['severity'] ?? null) === 'critical',
                                    'bg-amber-500' => is_null($notification->read_at) && ($notification->data['severity'] ?? null) === 'warning',
                                    'bg-accent-500' => is_null($notification->read_at) && ! in_array($notification->data['severity'] ?? null, ['critical', 'warning']),
                                ])></span>
                                <span class="min-w-0 flex-1">
                                    <span class="block text-sm font-medium text-zinc-900 dark:text-white">{{ $notification->data['title'] ?? 'Notifikasi' }}</span>
                                    <span class="block text-sm text-zinc-500 dark:text-zinc-400">{{ $notification->data['message'] ?? '' }}</span>
                                    <span class="mt-1 block font-mono text-xs text-zinc-400" title="{{ app_datetime_full($notification->created_at) }}">{{ app_datetime($notification->created_at) }}</span>
                                </span>
                            </button>

                            @can('notifikasi.delete')
                                <x-confirm-dialog
                                    title="Hapus notifikasi ini?"
                                    message="{{ $notification->data['title'] ?? 'Notifikasi' }} akan dihapus dari daftar Anda."
                                    confirm-text="Ya, Hapus"
                                    variant="danger"
                                    target="delete('{{ $notification->id }}')"
                                >
                                    <x-slot:trigger>
                                        <button type="button" title="Hapus" class="mr-3 mt-3 inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-lg text-zinc-400 transition hover:bg-red-50 hover:text-red-600 dark:hover:bg-red-500/10 dark:hover:text-red-400">
                                            <x-phosphor-trash class="h-4 w-4" />
                                        </button>
                                    </x-slot:trigger>
                                    <form wire:submit="delete('{{ $notification->id }}')" x-ref="form"></form>
                                </x-confirm-dialog>
                            @endcan
                            </div>
                        @endforeach
                    </div>
                </x-list-group>
            @endforeach
        </div>

        <x-slot:footer>
            {{ $groups->links() }}
        </x-slot:footer>
    </x-list-card>
</div>
