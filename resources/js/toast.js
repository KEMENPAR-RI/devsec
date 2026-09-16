import Swal from 'sweetalert2';

/**
 * Toast notifications, styled to match the app rather than SweetAlert2's default
 * theme. Colours and spacing are driven by the classes in resources/css/app.css
 * (.app-toast*), so dark mode follows the same `.dark` class on <html> that the
 * rest of the UI does - a JS-side colour lookup would go stale the moment the
 * theme toggle runs mid-session.
 */
const Toast = Swal.mixin({
    toast: true,
    position: 'top-end',
    showConfirmButton: false,
    timer: 4000,
    timerProgressBar: true,
    customClass: {
        popup: 'app-toast',
        title: 'app-toast-title',
        timerProgressBar: 'app-toast-progress',
    },
    // Pausing on hover is what makes a short timer acceptable for a long error
    // message: anyone still reading can keep it on screen by pointing at it.
    didOpen: (toast) => {
        toast.addEventListener('mouseenter', Swal.stopTimer);
        toast.addEventListener('mouseleave', Swal.resumeTimer);
    },
});

/** Errors stay longer than successes - there is usually something to act on. */
const TIMERS = {
    success: 4000,
    info: 4500,
    warning: 6000,
    error: 7000,
};

export function toast(message, type = 'success') {
    if (!message) {
        return;
    }

    const icon = TIMERS[type] ? type : 'success';

    Toast.fire({
        icon,
        // `title` is rendered as text by SweetAlert2, not HTML, so a message
        // carrying a user-supplied name (a website URL, a username) cannot inject
        // markup here.
        title: String(message),
        timer: TIMERS[icon],
    });
}

// Livewire dispatches this from PHP: $this->dispatch('toast', message: '...', type: 'error')
window.addEventListener('toast', (event) => {
    toast(event.detail?.message, event.detail?.type);
});

// Exposed for the inline @script in the layout that replays server-side flashes.
window.appToast = toast;

/**
 * A summary toast when a form fails validation.
 *
 * The per-field @error messages stay where they are - they are what tells someone
 * which input to fix. This only covers the case those cannot: a long form (the
 * Pengaturan tabs, the website modal) where the offending field is scrolled out
 * of view, so the submit button appears to do nothing at all.
 */
document.addEventListener('livewire:init', () => {
    if (typeof Livewire?.hook !== 'function') {
        return;
    }

    Livewire.hook('commit', ({ succeed }) => {
        succeed(({ snapshot }) => {
            let errors;

            try {
                errors = JSON.parse(snapshot)?.memo?.errors;
            } catch {
                return;
            }

            const count = errors ? Object.keys(errors).length : 0;

            if (count === 0) {
                return;
            }

            const first = errors[Object.keys(errors)[0]];
            const message = Array.isArray(first) ? first[0] : first;

            toast(
                count > 1 ? `${message} (+${count - 1} kesalahan lain)` : message,
                'error',
            );
        });
    });
});

export default toast;
