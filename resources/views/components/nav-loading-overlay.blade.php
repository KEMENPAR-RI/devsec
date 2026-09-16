{{--
    Full-screen loading shown while the page is being replaced - the wire:navigate
    swap, a Livewire redirect, or an ordinary form post that lands somewhere else.
    Driven by resources/js/navigation-loading.js through the `hidden` attribute
    rather than Alpine, because this element must survive - and keep working
    during - the navigation that removes the rest of the page.
--}}
<div
    data-nav-loading
    hidden
    role="status"
    aria-live="polite"
    aria-label="Memuat halaman"
    class="fixed inset-0 z-[100] flex items-center justify-center bg-white/70 backdrop-blur-sm transition-opacity duration-150 dark:bg-zinc-950/70"
>
    <div class="flex flex-col items-center gap-3">
        <x-phosphor-spinner class="h-8 w-8 animate-spin text-accent-600 dark:text-accent-400" />
        <p class="text-sm font-medium text-zinc-600 dark:text-zinc-300">Memuat...</p>
    </div>
</div>
