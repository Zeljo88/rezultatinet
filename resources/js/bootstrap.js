import axios from 'axios';
window.axios = axios;

window.axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';

// Laravel Echo + Pusher — dynamic import (non-blocking)
// Starts loading immediately but doesn't block initial render
// since app.js itself is loaded as type="module" (deferred)
window._echoPromise = null;

window.initEcho = function() {
    if (window.Echo) return Promise.resolve(window.Echo);
    if (window._echoPromise) return window._echoPromise;

    window._echoPromise = Promise.all([
        import('laravel-echo'),
        import('pusher-js'),
    ]).then(([{ default: Echo }, { default: Pusher }]) => {
        window.Pusher = Pusher;
        window.Echo = new Echo({
            broadcaster: 'reverb',
            key: import.meta.env.VITE_REVERB_APP_KEY,
            wsHost: import.meta.env.VITE_REVERB_HOST,
            wsPort: import.meta.env.VITE_REVERB_PORT ?? 8080,
            wssPort: import.meta.env.VITE_REVERB_PORT ?? 443,
            forceTLS: (import.meta.env.VITE_REVERB_SCHEME ?? 'https') === 'https',
            enabledTransports: ['ws', 'wss'],
        });
        // Signal Livewire that Echo is ready
        document.dispatchEvent(new Event('echo:ready'));
        return window.Echo;
    });

    return window._echoPromise;
};

// Auto-init Echo on pages with live components
// Done immediately (but non-blocking) since app.js is a module/deferred
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function() {
        if (document.querySelector('[data-live-scores]') || document.querySelector('[data-echo-init]')) {
            window.initEcho();
        }
    });
} else {
    // Already loaded
    if (document.querySelector('[data-live-scores]') || document.querySelector('[data-echo-init]')) {
        window.initEcho();
    }
}
