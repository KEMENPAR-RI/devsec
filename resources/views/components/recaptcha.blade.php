@props(['action'])

@if ($recaptchaEnabled)
    <script src="https://www.google.com/recaptcha/api.js?render={{ $recaptchaSiteKey }}" async defer></script>
@endif

<script>
    // Defined either way, so a form's submit handler never has to be conditional.
    window.recaptchaSiteKey = @js($recaptchaSiteKey);
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
