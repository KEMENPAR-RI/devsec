/**
 * Drives the full-page loading overlay (<x-nav-loading-overlay />) that replaced
 * Livewire's navigate progress bar.
 *
 * Plain DOM rather than Alpine on purpose: the overlay has to stay up across the
 * page swap itself, and it is the one element that must still respond while
 * Livewire is tearing the old page down.
 */

/** Never flash the overlay for a swap that finishes almost instantly. */
const SHOW_DELAY = 120;

/** A navigation that never completes must not leave the screen locked. */
const SAFETY_TIMEOUT = 15000;

let showTimer = null;
let safetyTimer = null;

const overlay = () => document.querySelector('[data-nav-loading]');

function show() {
    clearTimeout(showTimer);
    clearTimeout(safetyTimer);

    showTimer = setTimeout(() => {
        const element = overlay();
        if (element) {
            element.hidden = false;
        }
    }, SHOW_DELAY);

    safetyTimer = setTimeout(hide, SAFETY_TIMEOUT);
}

function hide() {
    clearTimeout(showTimer);
    clearTimeout(safetyTimer);

    const element = overlay();
    if (element) {
        element.hidden = true;
    }
}

// wire:navigate swaps.
document.addEventListener('livewire:navigate', show);
document.addEventListener('livewire:navigated', hide);

// Livewire redirects that leave SPA mode, and any full page load leaving the app.
window.addEventListener('beforeunload', show);

/**
 * Restoring from the back/forward cache re-runs no scripts, so a page that was
 * left with the overlay up via beforeunload would come back still covered.
 */
window.addEventListener('pageshow', hide);
window.addEventListener('popstate', hide);

/** Nothing was navigating after all - the user cancelled a download or dialog. */
document.addEventListener('visibilitychange', () => {
    if (document.visibilityState === 'visible') {
        hide();
    }
});

/**
 * Ordinary form posts that end in a redirect. beforeunload alone covers the
 * moment the browser leaves, but that fires late; catching submit puts the
 * overlay up as soon as the request starts. Livewire-handled forms (wire:submit)
 * do not fire a native submit event, so they are untouched here - their buttons
 * keep using <x-loading-label>.
 */
document.addEventListener('submit', (event) => {
    const form = event.target;

    if (!(form instanceof HTMLFormElement) || event.defaultPrevented) {
        return;
    }

    // A GET form targeting the current page still reloads it, so both verbs count.
    if (form.hasAttribute('data-no-nav-loading') || form.target === '_blank') {
        return;
    }

    show();
});
