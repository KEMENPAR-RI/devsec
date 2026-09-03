/**
 * Client half of the installable build: registers the service worker and drives
 * the two bits of UI in the footer - the "Instal aplikasi" button and the
 * offline badge.
 *
 * Written against data attributes rather than Alpine state because the header
 * and footer survive wire:navigate page swaps: the elements are replaced, the
 * captured install event is not, so the buttons are simply re-synced after every
 * navigation instead of being re-initialised.
 */

/** The deferred beforeinstallprompt event, kept until the user asks to install. */
let installPrompt = null;

const isInstalled = () =>
    window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone === true;

/**
 * iOS, including iPadOS - which reports itself as a Mac and is only given away
 * by the touch points. Every browser there is Safari underneath, so none of them
 * fires beforeinstallprompt and none exposes an install API: the home-screen
 * hint is the only thing we can offer.
 */
const isIos = () =>
    /iphone|ipod|ipad/i.test(navigator.userAgent) ||
    (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);

/** Install buttons only exist while there is a prompt to show. */
function syncInstallButtons() {
    document.querySelectorAll('[data-pwa-install]').forEach((element) => {
        element.hidden = installPrompt === null || isInstalled();
    });
}

/**
 * Whether the browser will consider installing at all. Both the service worker
 * and beforeinstallprompt are refused outright off a secure origin, silently -
 * so on a plain-http deployment the install button would simply never appear,
 * which looks like a missing build rather than a missing certificate.
 */
const isSecure = () => window.isSecureContext;

/** The iOS instructions replace the button on the one platform that has no prompt. */
function syncIosHints() {
    const show = isIos() && isSecure() && !isInstalled() && installPrompt === null;

    document.querySelectorAll('[data-pwa-ios]').forEach((element) => {
        element.hidden = !show;
    });
}

/** Says why there is no install button, rather than leaving a blank space. */
function syncInsecureNotices() {
    const show = !isSecure() && !isInstalled();

    document.querySelectorAll('[data-pwa-insecure]').forEach((element) => {
        element.hidden = !show;
    });
}

function syncOfflineBadges() {
    document.querySelectorAll('[data-pwa-offline]').forEach((element) => {
        element.hidden = navigator.onLine;
    });
}

function sync() {
    syncInstallButtons();
    syncIosHints();
    syncInsecureNotices();
    syncOfflineBadges();
}

window.addEventListener('beforeinstallprompt', (event) => {
    // Chromium's own mini-infobar is suppressed so the prompt happens where the
    // user asked for it, from the footer button.
    event.preventDefault();
    installPrompt = event;
    syncInstallButtons();
    syncIosHints();
});

window.addEventListener('appinstalled', () => {
    installPrompt = null;
    syncInstallButtons();
    syncIosHints();
});

window.addEventListener('online', syncOfflineBadges);
window.addEventListener('offline', syncOfflineBadges);
document.addEventListener('livewire:navigated', sync);
document.addEventListener('DOMContentLoaded', sync);

if (document.readyState !== 'loading') sync();

// Delegated, so buttons rendered after this script ran still work.
document.addEventListener('click', async (event) => {
    const trigger = event.target.closest('[data-pwa-install]');
    if (!trigger || installPrompt === null) return;

    event.preventDefault();

    const prompt = installPrompt;
    // A prompt can only be shown once; dropping it first keeps a double click
    // from calling prompt() twice on the same event.
    installPrompt = null;
    syncInstallButtons();

    await prompt.prompt();
    const { outcome } = await prompt.userChoice;

    // Declining is not final - keep the event so the button can come back.
    if (outcome === 'dismissed') {
        installPrompt = prompt;
        syncInstallButtons();
    }
});

if ('serviceWorker' in navigator) {
    window.addEventListener('load', () => {
        // Registration is rejected outright on insecure origins (anything but
        // https or localhost), which is expected on a plain-http staging box and
        // must not surface as an unhandled rejection.
        navigator.serviceWorker.register('/sw.js', { scope: '/' }).catch(() => {});
    });
}
