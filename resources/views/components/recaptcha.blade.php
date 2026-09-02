@props(['action'])

@php $recaptcha = app(\App\Services\Security\Recaptcha::class); @endphp

{{--
    Google reCAPTCHA v3 for one public form. Renders nothing at all when it isn't
    configured, so a fresh install (and the test suite) never loads Google.

    Usage - the form drives the submit itself, because the token has to be minted
    *before* Livewire's request goes out:

        <x-recaptcha action="login" />
        <form @submit.prevent="$recaptcha(() => $wire.login())">

    $recaptcha(callback) fetches a fresh token, puts it on the component's
    $recaptchaToken property, and then runs the callback. With reCAPTCHA off it
    just runs the callback straight away.
--}}

@if ($recaptcha->enabled())
    <script src="https://www.google.com/recaptcha/api.js?render={{ $recaptcha->siteKey() }}" async defer></script>
@endif

<script>
    // Defined either way, so a form's submit handler never has to be conditional.
    window.recaptchaSiteKey = @js($recaptcha->enabled() ? $recaptcha->siteKey() : null);
    window.recaptchaAction = @js($action);

    document.addEventListener('alpine:init', () => {
        Alpine.magic('recaptcha', (el) => async (run) => {
            const component = window.Livewire.find(el.closest('[wire\\:id]').getAttribute('wire:id'));

            if (window.recaptchaSiteKey && window.grecaptcha) {
                try {
                    const token = await new Promise((resolve, reject) => {
                        grecaptcha.ready(() => {
                            grecaptcha
                                .execute(window.recaptchaSiteKey, { action: window.recaptchaAction })
                                .then(resolve, reject);
                        });
                    });

                    component.set('recaptchaToken', token, false);
                } catch (error) {
                    // Leave the token empty and let the server decide - it treats an
                    // unreachable Google as "allow", but a missing token as "retry".
                    console.error('reCAPTCHA gagal dijalankan', error);
                }
            }

            return run();
        });
    });
</script>
