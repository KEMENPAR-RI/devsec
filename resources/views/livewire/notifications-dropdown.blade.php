<div x-data="{ open: false }" class="relative" wire:poll.30s>
    <button
        type="button"
        @click="open = !open"
        @click.outside="open = false"
        class="relative inline-flex h-9 w-9 items-center justify-center rounded-lg text-zinc-500 transition hover:bg-zinc-100 dark:text-zinc-400 dark:hover:bg-zinc-800"
        aria-label="Notifikasi"
    >
        <x-phosphor-bell class="h-5 w-5" />
        @if ($unreadCount > 0)
            <span class="absolute -right-1 -top-1 flex h-4 min-w-4 items-center justify-center rounded-full bg-accent-500 px-1 font-mono text-[10px] font-medium text-white">
                {{ $unreadCount > 9 ? '9+' : $unreadCount }}
            </span>
        @endif
    </button>

    {{-- On a phone the panel is pinned to both screen edges instead of hanging off
    the bell: a fixed 20rem panel anchored right would otherwise spill past the
    viewport, which is what made the list look lopsided and clipped. From sm: up it
    goes back to being a normal right-aligned dropdown. --}}
    <x-dropdown-panel class="fixed inset-x-3 top-16 w-auto sm:absolute sm:inset-x-auto sm:right-0 sm:top-auto sm:w-80">
        <div class="flex items-center justify-between gap-3 border-b border-zinc-100 px-4 py-3 dark:border-zinc-800">
            <p class="text-sm font-semibold">Notifikasi</p>
            @if ($unreadCount > 0)
                <button
                    type="button"
                    wire:click="markAllAsRead"
                    wire:loading.attr="disabled"
                    wire:target="markAllAsRead"
                    class="inline-flex shrink-0 items-center whitespace-nowrap text-xs font-medium text-accent-600 transition hover:text-accent-700 disabled:opacity-60 dark:text-accent-400 dark:hover:text-accent-300"
                >
                    <x-loading-label target="markAllAsRead" spinner="h-3 w-3" gap="gap-1.5">
                        Tandai semua dibaca
                    </x-loading-label>
                </button>
            @endif
        </div>

        <div class="max-h-[60vh] divide-y divide-zinc-100 overflow-y-auto sm:max-h-80 dark:divide-zinc-800">
            @forelse ($notifications as $notification)
                @php $isUnread = is_null($notification->read_at); @endphp
                {{-- A button, not a link: opening it has to record the read
                server-side before navigating (see NotificationsDropdown::open). --}}
                <button
                    type="button"
                    wire:click="open('{{ $notification->id }}')"
                    wire:loading.attr="disabled"
                    wire:target="open('{{ $notification->id }}')"
                    class="flex w-full items-start gap-3 px-4 py-3 text-left transition hover:bg-zinc-50 disabled:opacity-60 dark:hover:bg-zinc-800/60"
                    @class(['bg-accent-50/40 dark:bg-accent-500/5' => $isUnread])
                >
                    <span @class([
                        'mt-1.5 h-2 w-2 shrink-0 rounded-full',
                        'bg-transparent' => ! $isUnread,
                        'bg-red-500' => $isUnread && ($notification->data['severity'] ?? null) === 'critical',
                        'bg-amber-500' => $isUnread && ($notification->data['severity'] ?? null) === 'warning',
                        'bg-accent-500' => $isUnread && ! in_array($notification->data['severity'] ?? null, ['critical', 'warning']),
                    ])></span>

                    <span class="min-w-0 flex-1">
                        <span class="flex items-start gap-1.5">
                            <span @class([
                                'min-w-0 flex-1 break-words text-sm text-zinc-900 dark:text-white',
                                'font-semibold' => $isUnread,
                                'font-medium' => ! $isUnread,
                            ])>{{ $notification->data['title'] ?? 'Notifikasi' }}</span>
                            {{-- Batched findings (JudolContentDetected) carry a count --}}
                            @if (($notification->data['count'] ?? 1) > 1)
                                <span class="mt-0.5 shrink-0 rounded-full bg-zinc-100 px-1.5 py-0.5 text-[11px] font-medium text-zinc-500 dark:bg-zinc-800 dark:text-zinc-400">{{ $notification->data['count'] }}</span>
                            @endif
                        </span>
                        <span class="mt-0.5 line-clamp-2 block text-xs text-zinc-500 dark:text-zinc-400">{{ $notification->data['message'] ?? '' }}</span>
                        <span class="mt-1 flex flex-wrap items-center gap-x-2 gap-y-0.5 text-[11px] text-zinc-400">
                            <span>{{ app_time_ago($notification->created_at) }}</span>
                            <span class="hidden sm:inline">&middot;</span>
                            <span class="font-mono">{{ app_datetime($notification->created_at) }}</span>
                        </span>
                    </span>

                    {{-- Reserved, not inserted: growing a column here would reflow the
                    notification's own text just as it is being opened. --}}
                    <span class="mt-1 flex h-3.5 w-3.5 shrink-0 items-center justify-center">
                        <x-phosphor-spinner class="h-3.5 w-3.5 animate-spin text-zinc-400" wire:loading wire:target="open('{{ $notification->id }}')" />
                    </span>
                </button>
            @empty
                <p class="px-4 py-6 text-center text-sm text-zinc-500 dark:text-zinc-400">Belum ada notifikasi.</p>
            @endforelse
        </div>

        <div class="border-t border-zinc-100 px-4 py-2.5 text-center dark:border-zinc-800">
            <a href="{{ route('notifikasi') }}" wire:navigate class="text-xs font-medium text-accent-600 transition hover:text-accent-700 dark:text-accent-400 dark:hover:text-accent-300">
                Lihat semua notifikasi
            </a>
        </div>
    </x-dropdown-panel>
</div>
