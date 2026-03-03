import { useForm } from '@inertiajs/react';
import { FormEvent } from 'react';

export default function Register() {
    const { data, setData, post, processing, errors } = useForm({
        name: '',
        email: '',
        password: '',
        password_confirmation: '',
    });

    function submit(e: FormEvent) {
        e.preventDefault();
        post('/register');
    }

    return (
        <div className="flex min-h-screen items-center justify-center bg-white px-4 dark:bg-[#181618]">
            <div
                className="relative w-full max-w-sm -rotate-2 bg-gray-100 px-8 pb-8 pt-10 shadow-[2px_3px_12px_rgba(0,0,0,0.12)] dark:bg-[#131113] dark:shadow-[2px_3px_16px_rgba(0,0,0,0.5)]"
            >
                {/* Tape strip */}
                <div className="absolute -top-3 left-1/2 h-6 w-16 -translate-x-1/2 rotate-[2deg] bg-highlight/80" />

                <h1 className="mb-8 text-center text-2xl font-bold tracking-tight text-gray-900 dark:text-gray-100">
                    Create your account
                </h1>

                <form onSubmit={submit} className="space-y-8">
                    <div>
                        <label htmlFor="name" className="block text-xs uppercase tracking-widest text-gray-400 dark:text-gray-500">
                            Name
                        </label>
                        <input
                            id="name"
                            type="text"
                            autoComplete="name"
                            autoFocus
                            value={data.name}
                            onChange={(e) => setData('name', e.target.value)}
                            className="mt-1 block w-full border-0 bg-white px-3 py-2 text-[18px] leading-[1.75] focus:ring-0 focus:outline-none dark:bg-[#181618] dark:text-gray-100"
                        />
                        {errors.name && (
                            <p className="mt-1 text-sm text-highlight">{errors.name}</p>
                        )}
                    </div>

                    <div>
                        <label htmlFor="email" className="block text-xs uppercase tracking-widest text-gray-400 dark:text-gray-500">
                            Email
                        </label>
                        <input
                            id="email"
                            type="email"
                            autoComplete="email"
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
                            autoComplete="new-password"
                            value={data.password}
                            onChange={(e) => setData('password', e.target.value)}
                            className="mt-1 block w-full border-0 bg-white px-3 py-2 text-[18px] leading-[1.75] focus:ring-0 focus:outline-none dark:bg-[#181618] dark:text-gray-100"
                        />
                        {errors.password && (
                            <p className="mt-1 text-sm text-highlight">{errors.password}</p>
                        )}
                    </div>

                    <div>
                        <label htmlFor="password_confirmation" className="block text-xs uppercase tracking-widest text-gray-400 dark:text-gray-500">
                            Confirm password
                        </label>
                        <input
                            id="password_confirmation"
                            type="password"
                            autoComplete="new-password"
                            value={data.password_confirmation}
                            onChange={(e) => setData('password_confirmation', e.target.value)}
                            className="mt-1 block w-full border-0 bg-white px-3 py-2 text-[18px] leading-[1.75] focus:ring-0 focus:outline-none dark:bg-[#181618] dark:text-gray-100"
                        />
                        {errors.password_confirmation && (
                            <p className="mt-1 text-sm text-highlight">{errors.password_confirmation}</p>
                        )}
                    </div>

                    <button
                        type="submit"
                        disabled={processing}
                        className="w-full bg-gray-900 px-4 py-2 text-[18px] leading-[1.75] font-medium text-white hover:bg-gray-800 disabled:opacity-50 dark:bg-gray-100 dark:text-gray-900 dark:hover:bg-gray-200"
                    >
                        Register
                    </button>
                </form>

                <p className="mt-6 text-center text-sm text-gray-500 dark:text-gray-400">
                    Already have an account?{' '}
                    <a href="/login" className="font-medium text-gray-900 hover:underline dark:text-gray-100">
                        Sign in
                    </a>
                </p>
            </div>
        </div>
    );
}
