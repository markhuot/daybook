import { useState, useRef, useEffect } from 'react';
import { usePage, router } from '@inertiajs/react';

interface AuthUser {
    name: string;
    email: string;
}

interface SharedProps {
    auth: {
        user: AuthUser;
    };
    [key: string]: unknown;
}

export default function FloatingMenu() {
    const { auth } = usePage<SharedProps>().props;
    const [open, setOpen] = useState(false);
    const menuRef = useRef<HTMLDivElement>(null);

    useEffect(() => {
        function handleClickOutside(e: MouseEvent) {
            if (menuRef.current && !menuRef.current.contains(e.target as Node)) {
                setOpen(false);
            }
        }
        if (open) {
            document.addEventListener('mousedown', handleClickOutside);
        }
        return () => document.removeEventListener('mousedown', handleClickOutside);
    }, [open]);

    function handleLogout() {
        router.post('/logout');
    }

    return (
        <div className="fixed bottom-10 left-1/2 z-50 -translate-x-1/2" ref={menuRef}>
            {open && (
                <div className="absolute bottom-full left-0 right-0 mb-2 rounded-xl border border-gray-200 bg-gray-100 p-1 shadow-lg dark:border-gray-700 dark:bg-[#131113]">
                    <button
                        onClick={handleLogout}
                        className="w-full whitespace-nowrap rounded-lg px-3 py-1.5 text-left text-sm text-gray-700 hover:bg-gray-200 dark:text-gray-200 dark:hover:bg-gray-700"
                    >
                        Log out…
                    </button>
                </div>
            )}
            <div className="flex items-center gap-4 rounded-full border border-gray-200 bg-gradient-to-b from-white to-gray-100/90 pl-5 pr-4 py-1 shadow-lg backdrop-blur-sm dark:border-gray-700 dark:from-[#181618] dark:to-[#131113]/90">
                <span className="text-gray-700 dark:text-gray-200">{auth.user.name}</span>
                <button
                    onClick={() => setOpen(!open)}
                    className="-mr-1 flex h-7 w-7 items-center justify-center rounded-full text-gray-400 transition-colors hover:bg-gray-100 hover:text-gray-600 dark:text-gray-400 dark:hover:bg-gray-700 dark:hover:text-gray-200"
                    aria-label="Menu"
                >
                    <svg width="15" height="15" viewBox="0 0 15 15" fill="currentColor">
                        <circle cx="3" cy="7.5" r="1.5" />
                        <circle cx="7.5" cy="7.5" r="1.5" />
                        <circle cx="12" cy="7.5" r="1.5" />
                    </svg>
                </button>
            </div>
        </div>
    );
}
