<script>
    (function () {
        function applyTheme() {
            var stored = localStorage.getItem('theme');
            var prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
            document.documentElement.classList.toggle('dark', stored === 'dark' || (!stored && prefersDark));
        }

        // Run immediately too (not just on the event below) - this <script> sits in
        // <head> specifically to apply the class before first paint and avoid a
        // flash of the wrong theme; livewire:navigated fires slightly later.
        applyTheme();

        // wire:navigate swaps the page without a full reload, and morphs <html>'s
        // attributes against the newly-fetched (server-rendered, always-light-class)
        // markup - which silently strips the "dark" class this script had added,
        // reverting to light mode on every in-app navigation until a hard refresh.
        // livewire:navigated fires after every such transition (the very first page
        // load included), so re-applying there on top of the immediate call above
        // keeps the theme correct across the whole SPA-like navigation lifecycle.
        document.addEventListener('livewire:navigated', applyTheme);
    })();
</script>
