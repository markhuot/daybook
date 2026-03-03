import { createInertiaApp } from '@inertiajs/react';
import { createRoot } from 'react-dom/client';

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
