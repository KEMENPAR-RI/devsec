<button
    type="button"
    @click="
        document.documentElement.classList.toggle('dark');
        localStorage.setItem('theme', document.documentElement.classList.contains('dark') ? 'dark' : 'light');
    "
    {{ $attributes->merge(['class' => 'relative inline-flex h-9 w-9 items-center justify-center rounded-lg text-zinc-500 transition hover:bg-zinc-100 hover:text-zinc-700 dark:text-zinc-400 dark:hover:bg-zinc-800 dark:hover:text-zinc-200']) }}
    aria-label="Ganti tema terang/gelap"
>
    <x-phosphor-sun class="h-5 w-5 rotate-0 scale-100 transition-all duration-300 motion-reduce:transition-none dark:-rotate-90 dark:scale-0" />
    <x-phosphor-moon class="absolute h-5 w-5 rotate-90 scale-0 transition-all duration-300 motion-reduce:transition-none dark:rotate-0 dark:scale-100" />
</button>
