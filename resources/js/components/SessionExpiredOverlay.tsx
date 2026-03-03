import { useState, useEffect, useRef, useCallback, FormEvent } from 'react';
import { usePage } from '@inertiajs/react';

function getCsrfToken(): string {
    const match = document.cookie.match(/XSRF-TOKEN=([^;]+)/);
    return match ? decodeURIComponent(match[1]) : '';
}

interface SharedProps {
    auth: {
        user: { name: string; email: string };
    };
    [key: string]: unknown;
}

export default function SessionExpiredOverlay() {
    const { auth } = usePage<SharedProps>().props;
    const [expired, setExpired] = useState(false);
    const [email, setEmail] = useState('');
    const [password, setPassword] = useState('');
    const [remember, setRemember] = useState(false);
    const [error, setError] = useState('');
    const [submitting, setSubmitting] = useState(false);
    const wasAuthenticated = useRef(false);
    const lastKnownEmail = useRef('');

    // Track authenticated state and remember the user's email
    useEffect(() => {
        if (auth?.user) {
            wasAuthenticated.current = true;
            if (auth.user.email) {
                lastKnownEmail.current = auth.user.email;
            }
        }
    }, [auth?.user]);

    // Pre-fill email when overlay appears
    useEffect(() => {
        if (expired && lastKnownEmail.current) {
            setEmail(lastKnownEmail.current);
        }
    }, [expired]);

    // Poll /auth/check and listen for visibility changes
    useEffect(() => {
        const check = async () => {
            if (!wasAuthenticated.current) return;
            try {
                const res = await fetch('/auth/check', {
                    headers: { Accept: 'application/json' },
                });
                const data = await res.json();
                if (!data.authenticated) {
                    setExpired(true);
                }
            } catch {
                // Network error — skip this check
            }
        };

        const interval = setInterval(check, 60_000);

        const onVisibility = () => {
            if (document.visibilityState === 'visible') {
                check();
            }
        };
        document.addEventListener('visibilitychange', onVisibility);

        return () => {
            clearInterval(interval);
            document.removeEventListener('visibilitychange', onVisibility);
        };
    }, []);

    const handleSubmit = useCallback(
        async (e: FormEvent) => {
            e.preventDefault();
            setSubmitting(true);
            setError('');

            try {
                const res = await fetch('/login', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        Accept: 'application/json',
                        'X-XSRF-TOKEN': getCsrfToken(),
                    },
                    body: JSON.stringify({ email, password, remember }),
                });

                if (res.ok) {
                    setExpired(false);
                    setPassword('');
                    setError('');
                    wasAuthenticated.current = true;
                } else if (res.status === 419) {
                    // CSRF token mismatch — fetch a fresh token and ask user to retry
                    await fetch('/auth/check');
                    setError('Session token expired. Please try again.');
                } else {
                    try {
                        const data = await res.json();
                        setError(
                            data.errors?.email?.[0] ?? 'Login failed. Please try again.',
                        );
                    } catch {
                        setError('Login failed. Please try again.');
                    }
                }
            } catch {
                setError('Network error. Please try again.');
            } finally {
                setSubmitting(false);
            }
        },
        [email, password, remember],
    );

    if (!expired) return null;

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 px-4 backdrop-blur-sm">
            <div className="relative w-full max-w-sm rotate-2 bg-gray-100 px-8 pb-8 pt-10 shadow-[2px_3px_12px_rgba(0,0,0,0.12)] dark:bg-[#131113] dark:shadow-[2px_3px_16px_rgba(0,0,0,0.5)]">
                <div className="absolute -top-3 left-1/2 h-6 w-16 -translate-x-1/2 rotate-[-2deg] bg-highlight/80" />
                <h2 className="mb-2 text-center text-2xl font-bold tracking-tight text-gray-900 dark:text-gray-100">
                    Session expired
                </h2>
                <p className="mb-8 text-center text-sm text-gray-400 dark:text-gray-500">
                    Sign in again to continue where you left off.
                </p>
                <form onSubmit={handleSubmit} className="space-y-8">
                    <div>
                        <label className="block text-xs uppercase tracking-widest text-gray-400 dark:text-gray-500">
                            Email
                        </label>
                        <input
                            type="email"
                            value={email}
                            onChange={(e) => setEmail(e.target.value)}
                            className="mt-1 block w-full border-0 bg-white px-3 py-2 text-[18px] leading-[1.75] text-gray-900 placeholder-gray-300 focus:outline-none focus:ring-0 dark:bg-[#181618] dark:text-gray-100 dark:placeholder-gray-600"
                            autoFocus={!lastKnownEmail.current}
                        />
                    </div>
                    <div>
                        <label className="block text-xs uppercase tracking-widest text-gray-400 dark:text-gray-500">
                            Password
                        </label>
                        <input
                            type="password"
                            value={password}
                            onChange={(e) => setPassword(e.target.value)}
                            className="mt-1 block w-full border-0 bg-white px-3 py-2 text-[18px] leading-[1.75] text-gray-900 placeholder-gray-300 focus:outline-none focus:ring-0 dark:bg-[#181618] dark:text-gray-100 dark:placeholder-gray-600"
                            autoFocus={!!lastKnownEmail.current}
                        />
                    </div>
                    {error && <p className="text-sm text-highlight">{error}</p>}
                    <div className="flex items-center">
                        <button
                            type="button"
                            role="checkbox"
                            aria-checked={remember}
                            onClick={() => setRemember(!remember)}
                            className="flex items-center gap-2 text-xs uppercase tracking-widest text-gray-400 dark:text-gray-500"
                        >
                            <span
                                className={`flex h-5 w-5 items-center justify-center rounded-full border transition-colors ${remember ? 'border-highlight bg-highlight text-white' : 'border-gray-300 dark:border-gray-600'}`}
                            >
                                {remember && (
                                    <svg
                                        className="h-3 w-3"
                                        fill="none"
                                        viewBox="0 0 24 24"
                                        stroke="currentColor"
                                        strokeWidth={3}
                                    >
                                        <path
                                            strokeLinecap="round"
                                            strokeLinejoin="round"
                                            d="M5 13l4 4L19 7"
                                        />
                                    </svg>
                                )}
                            </span>
                            Remember me
                        </button>
                    </div>
                    <button
                        type="submit"
                        disabled={submitting}
                        className="w-full bg-gray-900 px-4 py-3 text-sm font-medium text-white transition hover:bg-gray-800 disabled:opacity-50 dark:bg-gray-100 dark:text-gray-900 dark:hover:bg-gray-200"
                    >
                        {submitting ? 'Signing in\u2026' : 'Sign in'}
                    </button>
                </form>
            </div>
        </div>
    );
}
