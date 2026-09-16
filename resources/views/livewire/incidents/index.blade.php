<div class="mx-auto flex max-w-7xl flex-col gap-6" wire:poll.10s.visible>
    @if (session('status'))
        <div class="enter flex items-center gap-2 rounded-lg bg-emerald-50 px-4 py-2.5 text-sm text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-400">
            <x-phosphor-check-circle class="h-4 w-4 shrink-0" />
            {{ session('status') }}
        </div>
    @endif

    {{-- Header --}}
    <div class="enter flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
        <div>
            <h1 class="text-2xl font-semibold tracking-tight text-zinc-900 dark:text-white">Insiden</h1>
            <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">Down time, anomali serangan, SSL, perubahan DNS, dan masa berlaku domain - dikelompokkan per situs per hari. Temuan judol ada di halaman Deteksi Judol.</p>
        </div>

        <div class="flex shrink-0 flex-wrap items-center gap-2 self-start sm:self-auto">
            @can('insiden.delete')
                <x-confirm-dialog
                    title="Hapus Semua Data Insiden?"
                    message="Semua insiden website (down time, serangan, SSL) akan dihapus, apa pun filter yang aktif. Temuan judol tidak ikut terhapus. Tindakan ini tidak bisa dibatalkan."
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

    <x-list-card
        :is-empty="$groups->isEmpty()"
        empty-icon="phosphor-shield-check"
        empty-message="Tidak ada insiden yang cocok dengan filter ini."
    >
        <x-slot:toolbar>
            <x-search-input model="search" placeholder="Cari judul atau situs..." class="sm:max-w-xs" />
            <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:gap-3">
                <select wire:model.live="type" class="rounded-lg border border-zinc-200 bg-white px-3 py-2 text-sm text-zinc-700 focus:border-accent-500 focus:outline-none focus:ring-2 focus:ring-accent-500/30 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-200">
                    <option value="">Semua Jenis</option>
                    <option value="downtime">Down Time</option>
                    <option value="attack">Serangan</option>
                    <option value="ssl">SSL</option>
                    <option value="dns">DNS</option>
                    <option value="domain">Masa Berlaku Domain</option>
                    <option value="reputation">Reputasi Domain</option>
                </select>
                <select wire:model.live="status" class="rounded-lg border border-zinc-200 bg-white px-3 py-2 text-sm text-zinc-700 focus:border-accent-500 focus:outline-none focus:ring-2 focus:ring-accent-500/30 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-200">
                    <option value="">Semua Status</option>
                    <option value="open">Terbuka</option>
                    <option value="resolved">Selesai</option>
                </select>
            </div>
        </x-slot:toolbar>

        <div class="divide-y divide-zinc-100 transition-opacity dark:divide-zinc-800" wire:loading.class="opacity-40" wire:target="previousPage,nextPage,gotoPage,search,type,status">
            @foreach ($groups as $group)
                @php
                    $site = $websites[$group->site_key] ?? null;
                    $items = $incidentsByGroup[\App\Support\DailyGroups::key($group->site_key, $group->day)] ?? collect();
                    $openCount = $items->where('status', 'open')->count();
                @endphp

                <x-list-group
                    :title="$site?->name ?? 'Situs sudah dihapus'"
                    :href="$site ? route('situs.show', $site) : null"
                    :day="\Illuminate\Support\Carbon::parse($group->day)"
                    :count="$group->total"
                    unit="insiden"
                    :open="$loop->first"
                    :tone="$openCount ? 'red' : 'emerald'"
                    icon="phosphor-bell-ringing"
                >
                    <x-slot:summary>
                        @if ($openCount)
                            <span class="rounded-full bg-red-50 px-2 py-0.5 text-xs font-medium text-red-700 dark:bg-red-500/10 dark:text-red-400">{{ $openCount }} terbuka</span>
                        @else
                            <span class="rounded-full bg-emerald-50 px-2 py-0.5 text-xs font-medium text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-400">Semua selesai</span>
                        @endif
                    </x-slot:summary>

                    <div class="divide-y divide-zinc-100 dark:divide-zinc-800">
                        @foreach ($items as $incident)
                            @php $lines = $incident->detailLines(); @endphp
                            <div x-data="{ open: false }">
                                <button
                                    type="button"
                                    @click="open = !open"
                                    class="flex w-full items-center gap-3 py-3 pl-5 pr-5 text-left transition hover:bg-white sm:pl-[4.25rem] dark:hover:bg-zinc-900/40"
                                >
                                    <span @class([
                                        'flex h-7 w-7 shrink-0 items-center justify-center rounded-full',
                                        'bg-red-50 text-red-600 dark:bg-red-500/10 dark:text-red-400' => $incident->severity === 'critical',
                                        'bg-amber-50 text-amber-600 dark:bg-amber-500/10 dark:text-amber-400' => $incident->severity === 'warning',
                                        'bg-zinc-100 text-zinc-600 dark:bg-zinc-800 dark:text-zinc-400' => $incident->severity === 'info',
                                    ])>
                                        @if ($incident->type === 'downtime')
                                            <x-phosphor-wifi-x class="h-3.5 w-3.5" />
                                        @elseif ($incident->type === 'judol')
                                            <x-phosphor-shield-warning class="h-3.5 w-3.5" />
                                        @elseif ($incident->type === 'ssl')
                                            <x-phosphor-lock-key class="h-3.5 w-3.5" />
                                        @else
                                            <x-phosphor-warning class="h-3.5 w-3.5" />
                                        @endif
                                    </span>

                                    <span class="min-w-0 flex-1">
                                        <span class="block truncate text-sm text-zinc-900 dark:text-white">{{ $incident->title }}</span>
                                        <span class="block font-mono text-xs text-zinc-400" title="Terdeteksi {{ app_datetime_full($incident->detected_at) }}">{{ app_datetime($incident->detected_at) }}</span>
                                    </span>

                                    <span @class([
                                        'shrink-0 rounded-full px-2.5 py-1 text-xs font-medium',
                                        'bg-red-50 text-red-700 dark:bg-red-500/10 dark:text-red-400' => $incident->status === 'open',
                                        'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-400' => $incident->status === 'resolved',
                                    ])>
                                        {{ $incident->status === 'open' ? 'Terbuka' : 'Selesai' }}
                                    </span>

                                    <x-phosphor-caret-down class="h-4 w-4 shrink-0 text-zinc-400 transition-transform duration-200" x-bind:class="open ? 'rotate-180' : ''" />
                                </button>

                                <div x-show="open" x-cloak x-transition class="px-5 pb-4 sm:pl-[5.75rem]">
                                    @if ($incident->description)
                                        <p class="mt-1 break-words text-sm text-zinc-600 dark:text-zinc-300">{{ $incident->description }}</p>
                                    @endif

                                    @if (! empty($lines))
                                        <dl class="mt-3 grid grid-cols-1 gap-x-6 gap-y-1.5 rounded-lg bg-white px-3.5 py-3 text-xs sm:grid-cols-2 dark:bg-zinc-900/60">
                                            @foreach ($lines as $label => $value)
                                                <div class="flex items-baseline justify-between gap-3 sm:justify-start">
                                                    <dt class="shrink-0 text-zinc-400">{{ $label }}</dt>
                                                    <dd class="flex-wrap font-mono text-zinc-700 dark:text-zinc-300">{{ $value }}</dd>
                                                </div>
                                            @endforeach
                                        </dl>
                                    @endif

                                    {{-- AI root-cause analysis (App\Services\Monitoring\IncidentInvestigator) --}}
                                    @if ($incident->ai_analysis)
                                        <div class="mt-3 flex items-start gap-2.5 rounded-lg bg-accent-50/60 px-3.5 py-3 dark:bg-accent-500/5">
                                            <x-phosphor-sparkle class="mt-0.5 h-4 w-4 shrink-0 text-accent-500" />
                                            <div class="min-w-0 flex-1">
                                                <p class="text-xs font-medium text-accent-700 dark:text-accent-400">
                                                    Analisis AI &middot; {{ app_datetime($incident->ai_analyzed_at) }}
                                                </p>
                                                <p class="mt-1 whitespace-pre-line text-sm text-zinc-700 dark:text-zinc-300">{{ $incident->ai_analysis }}</p>
                                            </div>
                                        </div>
                                    @endif

                                    @if ($error = ($investigateErrors[$incident->id] ?? null))
                                        <p class="mt-3 flex items-center gap-1.5 text-xs text-red-600 dark:text-red-400">
                                            <x-phosphor-warning-circle class="h-3.5 w-3.5 shrink-0" />
                                            {{ $error }}
                                        </p>
                                    @endif

                                    <div class="mt-3 flex flex-wrap items-center gap-x-2 gap-y-1.5">
                                        @can('insiden.update')
                                            <button
                                                type="button"
                                                wire:click="investigate('{{ $incident->uuid }}')"
                                                wire:loading.attr="disabled"
                                                wire:target="investigate('{{ $incident->uuid }}')"
                                                class="inline-flex items-center whitespace-nowrap text-xs font-medium text-zinc-500 transition hover:text-zinc-700 disabled:opacity-60 dark:text-zinc-400 dark:hover:text-zinc-200"
                                            >
                                                <x-loading-label target="investigate('{{ $incident->uuid }}')" loading="Menganalisis..." spinner="h-3.5 w-3.5" gap="gap-1.5">
                                                    <x-phosphor-sparkle class="h-3.5 w-3.5 shrink-0" />
                                                    <span>{{ $incident->ai_analysis ? 'Selidiki Ulang' : 'Selidiki Penyebab' }}</span>
                                                </x-loading-label>
                                            </button>
                                            @if ($incident->status === 'open')
                                                <span class="text-zinc-300 dark:text-zinc-700">&middot;</span>
                                                <button
                                                    type="button"
                                                    wire:click="resolve('{{ $incident->uuid }}')"
                                                    wire:loading.attr="disabled"
                                                    wire:target="resolve('{{ $incident->uuid }}')"
                                                    class="inline-flex items-center whitespace-nowrap text-xs font-medium text-zinc-500 transition hover:text-zinc-700 disabled:opacity-60 dark:text-zinc-400 dark:hover:text-zinc-200"
                                                >
                                                    <x-loading-label target="resolve('{{ $incident->uuid }}')" loading="Menandai..." spinner="h-3.5 w-3.5" gap="gap-1.5">
                                                        Tandai Selesai
                                                    </x-loading-label>
                                                </button>
                                            @endif
                                        @endcan
                                        @can('insiden.delete')
                                            <span class="text-zinc-300 dark:text-zinc-700">&middot;</span>
                                            <x-confirm-dialog
                                                title="Hapus insiden ini?"
                                                message="{{ $incident->title }} akan dihapus permanen dari riwayat. Tindakan ini tidak bisa dibatalkan."
                                                confirm-text="Ya, Hapus"
                                                variant="danger"
                                                target="delete('{{ $incident->uuid }}')"
                                            >
                                                <x-slot:trigger>
                                                    <button type="button" class="inline-flex items-center gap-1.5 whitespace-nowrap text-xs font-medium text-zinc-500 transition hover:text-red-600 dark:text-zinc-400 dark:hover:text-red-400">
                                                        <x-phosphor-trash class="h-3.5 w-3.5" />
                                                        Hapus
                                                    </button>
                                                </x-slot:trigger>
                                                <form wire:submit="delete('{{ $incident->uuid }}')" x-ref="form"></form>
                                            </x-confirm-dialog>
                                        @endcan
                                    </div>
                                </div>
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
