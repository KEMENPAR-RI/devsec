<div class="mx-auto flex max-w-7xl flex-col gap-6">
    {{-- Header --}}
    <div class="enter flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
        <div>
            <h1 class="text-2xl font-semibold tracking-tight text-zinc-900 dark:text-white">Log Audit</h1>
            <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">Jejak setiap perubahan dan upaya masuk - lengkap dengan pelaku, waktu, dan alamat IP. Catatan di sini tidak bisa diubah maupun dihapus.</p>
        </div>
    </div>

    <x-list-card
        :is-empty="$logs->isEmpty()"
        empty-icon="phosphor-clock-counter-clockwise"
        empty-title="Belum ada catatan"
        empty-message="Setiap perubahan situs, role, pengguna, pengaturan, dan setiap upaya masuk akan tercatat di sini."
    >
        <x-slot:toolbar>
            <x-search-input model="search" placeholder="Cari aksi, subjek, pelaku, atau IP..." class="sm:max-w-xs" />

            <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:gap-3">
                <select wire:model.live="group" class="rounded-lg border border-zinc-200 bg-white px-3 py-2 text-sm text-zinc-700 focus:border-accent-500 focus:outline-none focus:ring-2 focus:ring-accent-500/30 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-200">
                    <option value="">Semua Aksi</option>
                    @foreach ($groups as $option)
                        <option value="{{ $option }}">{{ Str::headline($option) }}</option>
                    @endforeach
                </select>

                <select wire:model.live="actor" class="rounded-lg border border-zinc-200 bg-white px-3 py-2 text-sm text-zinc-700 focus:border-accent-500 focus:outline-none focus:ring-2 focus:ring-accent-500/30 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-200">
                    <option value="">Semua Pelaku</option>
                    @foreach ($actors as $person)
                        <option value="{{ $person->uuid }}">{{ $person->name }}</option>
                    @endforeach
                </select>

                @if ($search || $group || $actor)
                    <button type="button" wire:click="resetFilters" class="inline-flex items-center gap-1.5 rounded-lg border border-zinc-200 px-3 py-2 text-sm font-medium text-zinc-600 transition hover:bg-zinc-50 dark:border-zinc-700 dark:text-zinc-300 dark:hover:bg-zinc-800">
                        <x-phosphor-x class="h-4 w-4" />
                        Reset
                    </button>
                @endif
            </div>
        </x-slot:toolbar>

        <div class="divide-y divide-zinc-100 transition-opacity dark:divide-zinc-800" wire:loading.class="opacity-40" wire:target="previousPage,nextPage,gotoPage,search,group,actor">
            @foreach ($logs as $log)
                @php
                    $isAlarming = Str::contains($log->event, ['gagal', 'ditolak', 'dihapus']);
                    $tone = $isAlarming
                        ? 'bg-red-50 text-red-700 dark:bg-red-500/10 dark:text-red-400'
                        : 'bg-zinc-100 text-zinc-700 dark:bg-zinc-800 dark:text-zinc-300';
                @endphp

                <div x-data="{ open: false }" class="px-5 py-3.5">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div class="min-w-0 flex-1">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="rounded-full px-2 py-0.5 text-xs font-medium {{ $tone }}">{{ $log->event }}</span>
                                @if ($log->subject)
                                    <span class="truncate text-sm font-medium text-zinc-900 dark:text-white">{{ $log->subject }}</span>
                                @endif
                            </div>

                            <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">
                                <span class="font-medium text-zinc-700 dark:text-zinc-300">{{ $log->actorLabel() }}</span>
                                @if ($log->ip_address)
                                    &middot; {{ $log->ip_address }}
                                @endif
                                &middot; {{ \App\Support\Dates::display($log->created_at) }}
                            </p>
                        </div>

                        @if ($log->changes)
                            <button type="button" x-on:click="open = ! open" class="inline-flex shrink-0 items-center gap-1 rounded-lg px-2 py-1 text-xs font-medium text-zinc-500 transition hover:bg-zinc-100 dark:text-zinc-400 dark:hover:bg-zinc-800">
                                <span x-text="open ? 'Sembunyikan' : 'Lihat detail'"></span>
                                <x-phosphor-caret-down class="h-3.5 w-3.5 transition" x-bind:class="open && 'rotate-180'" />
                            </button>
                        @endif
                    </div>

                    @if ($log->changes)
                        <div x-show="open" x-collapse x-cloak class="mt-3 overflow-x-auto rounded-lg bg-zinc-50 p-3 dark:bg-zinc-800/50">
                            <table class="w-full text-left text-xs">
                                <tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">
                                    @foreach ($log->changes as $field => $value)
                                        <tr>
                                            <th scope="row" class="whitespace-nowrap py-1.5 pr-4 align-top font-medium text-zinc-600 dark:text-zinc-400">{{ Str::headline($field) }}</th>
                                            <td class="py-1.5 text-zinc-800 dark:text-zinc-200">
                                                @if (is_array($value) && array_key_exists('ke', $value))
                                                    <span class="text-zinc-400 line-through dark:text-zinc-500">{{ \App\Support\AuditValue::render($value['dari']) }}</span>
                                                    <span class="px-1 text-zinc-400">&rarr;</span>
                                                    <span>{{ \App\Support\AuditValue::render($value['ke']) }}</span>
                                                @else
                                                    {{ \App\Support\AuditValue::render($value) }}
                                                @endif
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </div>
            @endforeach
        </div>

        <x-slot:footer>
            {{ $logs->links() }}
        </x-slot:footer>
    </x-list-card>
</div>
