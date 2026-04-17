import axios from 'axios';
window.axios = axios;

window.axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';

/**
 * Next we will register the CSRF Token as a common header with Axios so that
 * all outgoing HTTP requests automatically have it attached. This is just
 * a simple convenience so we don't have to attach every token manually.
 */

// CSRF Token is handled automatically by Axios via the XSRF-TOKEN cookie.
// We do not need to manually set the X-CSRF-TOKEN header from the meta tag,
// as this can lead to stale tokens after login/session regeneration.

import Echo from 'laravel-echo';

import Pusher from 'pusher-js';
window.Pusher = Pusher;

const reverbAppKey = import.meta.env.VITE_REVERB_APP_KEY;

window.Echo = reverbAppKey
    ? new Echo({
        broadcaster: 'reverb',
        key: reverbAppKey,
        wsHost: import.meta.env.VITE_REVERB_HOST ?? window.location.hostname,
        wsPort: import.meta.env.VITE_REVERB_PORT ?? 80,
        wssPort: import.meta.env.VITE_REVERB_PORT ?? 443,
        forceTLS: (import.meta.env.VITE_REVERB_SCHEME ?? 'https') === 'https',
        enabledTransports: ['ws', 'wss'],
    })
    : null;
