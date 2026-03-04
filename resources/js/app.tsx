import { createInertiaApp } from '@inertiajs/react';
import { createRoot } from 'react-dom/client';
import { configureEcho } from '@laravel/echo-react';

configureEcho({
    broadcaster: 'reverb',
    key: import.meta.env.VITE_REVERB_APP_KEY,
    wsHost: import.meta.env.VITE_REVERB_HOST,
    wsPort: import.meta.env.VITE_REVERB_PORT ?? 80,
    wssPort: import.meta.env.VITE_REVERB_PORT ?? 443,
    forceTLS: (import.meta.env.VITE_REVERB_SCHEME ?? 'https') === 'https',
    enabledTransports: ['ws', 'wss'],
});

// Set timezone cookie so the backend can determine "today" in the user's local timezone
const timezone = Intl.DateTimeFormat().resolvedOptions().timeZone;
document.cookie = `timezone=${encodeURIComponent(timezone)};path=/;SameSite=Lax`;

createInertiaApp({
    resolve: (name) => {
        const pages = import.meta.glob('./pages/**/*.tsx', { eager: true });
        return pages[`./pages/${name}.tsx`];
    },
    setup({ el, App, props }) {
        createRoot(el).render(<App {...props} />);
    },
});
