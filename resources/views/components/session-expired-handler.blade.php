{{-- Redirect ke login saat sesi expired (419), tanpa dialog bawaan Livewire.
Harus dimuat sebelum @livewireScripts agar listener terpasang sebelum livewire:init. --}}
<script>
    (function () {
        const loginUrl = @js(route('login') . '?expired=1');
        let redirecting = false;

        function redirectToLogin() {
            if (redirecting) return;
            redirecting = true;
            window.location.href = loginUrl;
        }

        function isExpired(status) {
            return status === 419 || status === 401;
        }

        document.addEventListener('livewire:init', function () {
            // Livewire v4.
            if (typeof Livewire.interceptRequest === 'function') {
                Livewire.interceptRequest(function ({ onError }) {
                    onError(function ({ response, preventDefault }) {
                        if (response && isExpired(response.status)) {
                            preventDefault();
                            redirectToLogin();
                        }
                    });
                });

                return;
            }

            // Livewire v3.
            Livewire.hook('request', function ({ fail }) {
                fail(function ({ status, preventDefault }) {
                    if (isExpired(status)) {
                        preventDefault();
                        redirectToLogin();
                    }
                });
            });
        });

        window.addEventListener('session-expired', redirectToLogin);
    })();
</script>
