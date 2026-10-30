{{-- Runs before first paint so the page never flashes the wrong theme, and owns the theme logic:
     window.theme.pref() reads the saved choice, window.theme.apply() sets <html class="dark">.
     It also follows the OS setting while the choice is "system". The themeToggle component in
     app.js only stores the choice and calls apply(). Storage can throw (private windows). --}}
<script @if ($nonce = \Illuminate\Support\Facades\Vite::cspNonce()) nonce="{{ $nonce }}" @endif>
    (function () {
        var media = window.matchMedia('(prefers-color-scheme: dark)');

        window.theme = {
            pref: function () {
                // Dark is the default look; "system" is an explicit choice.
                try { return localStorage.getItem('theme') || 'dark'; } catch (e) { return 'dark'; }
            },
            apply: function (pref) {
                pref = pref || window.theme.pref();
                document.documentElement.classList.toggle('dark', pref === 'dark' || (pref !== 'light' && media.matches));
            },
        };

        window.theme.apply();
        media.addEventListener('change', function () { window.theme.apply(); });
    })();
</script>
