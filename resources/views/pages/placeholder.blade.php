<x-layouts.app :title="$title">
    <div class="mx-auto flex max-w-md flex-col items-center gap-4 py-20 text-center">
        <span class="flex h-14 w-14 items-center justify-center rounded-2xl bg-accent-50 text-accent-600 dark:bg-accent-500/10 dark:text-accent-400">
            <x-dynamic-component :component="'phosphor-'.$icon" class="h-7 w-7" />
        </span>
        <h1 class="text-xl font-semibold tracking-tight text-zinc-900 dark:text-white">{{ $title }}</h1>
        <p class="text-sm text-zinc-500 dark:text-zinc-400">{{ $description }}</p>
    </div>
</x-layouts.app>
