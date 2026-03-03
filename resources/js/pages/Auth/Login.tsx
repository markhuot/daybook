import { useForm } from '@inertiajs/react';
import { FormEvent } from 'react';

export default function Login() {
    const { data, setData, post, processing, errors } = useForm({
        email: '',
        password: '',
        remember: false,
    });

    function submit(e: FormEvent) {
        e.preventDefault();
        post('/login');
    }

    return (
        <div className="flex min-h-screen items-center justify-center bg-white px-4 dark:bg-[#181618]">
            <div
                className="relative w-full max-w-sm rotate-2 bg-gray-100 px-8 pb-8 pt-10 shadow-[2px_3px_12px_rgba(0,0,0,0.12)] dark:bg-[#131113] dark:shadow-[2px_3px_16px_rgba(0,0,0,0.5)]"
            >
                {/* Tape strip */}
                <div className="absolute -top-3 left-1/2 h-6 w-16 -translate-x-1/2 rotate-[-2deg] bg-highlight/80" />

                <h1 className="mb-8 text-center text-2xl font-bold tracking-tight text-gray-900 dark:text-gray-100">
                    Sign in to Daybook
                </h1>

                <form onSubmit={submit} className="space-y-8">
                    <div>
                        <label htmlFor="email" className="block text-xs uppercase tracking-widest text-gray-400 dark:text-gray-500">
                            Email
                        </label>
                        <input
                            id="email"
                            type="email"
                            autoComplete="email"
                            autoFocus
                            value={data.email}
                            onChange={(e) => setData('email', e.target.value)}
                            className="mt-1 block w-full border-0 bg-white px-3 py-2 text-[18px] leading-[1.75] focus:ring-0 focus:outline-none dark:bg-[#181618] dark:text-gray-100"
                        />
                        {errors.email && (
                            <p className="mt-1 text-sm text-highlight">{errors.email}</p>
                        )}
                    </div>

                    <div>
                        <label htmlFor="password" className="block text-xs uppercase tracking-widest text-gray-400 dark:text-gray-500">
                            Password
                        </label>
                        <input
                            id="password"
                            type="password"
                            autoComplete="current-password"
                            value={data.password}
                            onChange={(e) => setData('password', e.target.value)}
                            className="mt-1 block w-full border-0 bg-white px-3 py-2 text-[18px] leading-[1.75] focus:ring-0 focus:outline-none dark:bg-[#181618] dark:text-gray-100"
                        />
                        {errors.password && (
                            <p className="mt-1 text-sm text-highlight">{errors.password}</p>
                        )}
                    </div>

                    <div className="flex items-center gap-2">
                        <button
                            type="button"
                            role="checkbox"
                            aria-checked={data.remember}
                            onClick={() => setData('remember', !data.remember)}
                            className={`relative flex h-[1em] w-[1em] flex-shrink-0 items-center justify-center rounded-full border-2 ${
                                data.remember
                                    ? 'border-black bg-black dark:border-white dark:bg-white'
                                    : 'border-[#d1d5db] dark:border-[#4b5563]'
                            }`}
                        >
                            {data.remember && (
                                <span
                                    className="absolute border-white dark:border-black"
                                    style={{
                                        width: 7,
                                        height: 11,
                                        borderStyle: 'solid',
                                        borderWidth: '0 2px 2px 0',
                                        transform: 'translate(0, -1px) rotate(45deg)',
                                    }}
                                />
                            )}
                        </button>
                        <label
                            onClick={() => setData('remember', !data.remember)}
                            className="cursor-pointer text-sm text-gray-600 dark:text-gray-400"
                        >
                            Remember me
                        </label>
                    </div>

                    <button
                        type="submit"
                        disabled={processing}
                        className="w-full bg-gray-900 px-4 py-2 text-[18px] leading-[1.75] font-medium text-white hover:bg-gray-800 disabled:opacity-50 dark:bg-gray-100 dark:text-gray-900 dark:hover:bg-gray-200"
                    >
                        Sign in
                    </button>
                </form>

                <p className="mt-6 text-center text-sm text-gray-500 dark:text-gray-400">
                    Don't have an account?{' '}
                    <a href="/register" className="font-medium text-gray-900 hover:underline dark:text-gray-100">
                        Register
                    </a>
                </p>
            </div>
        </div>
    );
}
