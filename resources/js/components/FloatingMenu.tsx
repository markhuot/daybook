import { useState, useRef, useEffect } from 'react';
import { router } from '@inertiajs/react';

export default function FloatingMenu() {
    const [menuOpen, setMenuOpen] = useState(false);
    const [promptOpen, setPromptOpen] = useState(false);
    const [query, setQuery] = useState('');
    const menuRef = useRef<HTMLDivElement>(null);

    // Close menu on outside click
    useEffect(() => {
        function handleClickOutside(e: MouseEvent) {
            if (menuRef.current && !menuRef.current.contains(e.target as Node)) {
                setMenuOpen(false);
            }
        }
        if (menuOpen) {
            document.addEventListener('mousedown', handleClickOutside);
        }
        return () => document.removeEventListener('mousedown', handleClickOutside);
    }, [menuOpen]);

    function handleChange(e: React.ChangeEvent<HTMLTextAreaElement>) {
        setQuery(e.target.value);
    }

    function submitPrompt(text: string) {
        const trimmed = text.trim();
        if (trimmed === '') return;

        router.post('/prompt', { prompt: trimmed }, {
            preserveState: true,
            preserveScroll: true,
        });

        setQuery('');
    }

    function handleKeyDown(e: React.KeyboardEvent<HTMLTextAreaElement>) {
        if (e.key === 'Enter' && !e.shiftKey && !e.altKey) {
            e.preventDefault();
            submitPrompt(query);
        }
        if (e.key === 'Escape') {
            setQuery('');
            (e.target as HTMLTextAreaElement).blur();
        }
    }

    function handleLogout() {
        router.post('/logout');
    }

    return (
        <div className="fixed bottom-0 left-0 right-0 z-50 pointer-events-none">
            <div className="mx-auto max-w-4xl pl-20" ref={menuRef}>
                <div className="relative inline-block pointer-events-auto">
                    <div
                        className={`relative transition-transform duration-300 ease-out ${promptOpen ? 'translate-y-0' : 'translate-y-[calc(100%-2rem)]'}`}
                    >
                        <button
                            onClick={() => {
                                setPromptOpen((open) => {
                                    if (open) setMenuOpen(false);
                                    return !open;
                                });
                            }}
                            className="group relative mx-auto flex h-9 items-center gap-2 overflow-hidden rounded-t-xl border border-amber-200/70 bg-gradient-to-r from-amber-100 via-orange-100 to-rose-100 px-5 text-xs font-semibold uppercase tracking-[0.15em] text-amber-800 shadow-[2px_3px_12px_rgba(0,0,0,0.12)] transition-all duration-200 hover:-translate-y-0.5 hover:from-amber-200 hover:to-rose-200 hover:text-amber-900 dark:border-[#3a2c22] dark:from-[#2b2118] dark:via-[#332017] dark:to-[#302030] dark:text-amber-300 dark:shadow-[2px_3px_16px_rgba(0,0,0,0.5)] dark:hover:from-[#3a2c22] dark:hover:to-[#3d2335]"
                            aria-expanded={promptOpen}
                        >
                            <span className="text-sm leading-none transition-transform duration-200 group-hover:rotate-12">
                                {promptOpen ? '▽' : '△'}
                            </span>
                            <span>{promptOpen ? 'Hide prompt' : 'Show prompt'}</span>
                            <span className="h-1.5 w-1.5 rounded-full bg-amber-500/80 shadow-[0_0_10px_rgba(245,158,11,0.55)] dark:bg-amber-300/90" />
                        </button>

                        <div className="relative">
                            {/* Menu popup */}
                            {menuOpen && promptOpen && (
                                <div className="absolute bottom-full left-0 right-0 mb-4 -rotate-2 bg-gray-100 p-1 shadow-[2px_3px_12px_rgba(0,0,0,0.12)] dark:bg-[#131113] dark:shadow-[2px_3px_16px_rgba(0,0,0,0.5)]">
                                    <button
                                        onClick={handleLogout}
                                        className="w-full whitespace-nowrap px-3 py-1.5 text-left text-sm text-gray-700 hover:bg-white dark:text-gray-200 dark:hover:bg-[#181618]"
                                    >
                                        Log out…
                                    </button>
                                </div>
                            )}

                            {/* Main bar */}
                            <div className="rotate-1 bg-gray-100 px-5 py-3 shadow-[2px_3px_12px_rgba(0,0,0,0.12)] dark:bg-[#131113] dark:shadow-[2px_3px_16px_rgba(0,0,0,0.5)]">
                                <div className="flex items-start gap-4">
                                    <textarea
                                        rows={3}
                                        placeholder="Prompt..."
                                        value={query}
                                        onChange={handleChange}
                                        onKeyDown={handleKeyDown}
                                        className="w-64 resize-none bg-transparent text-gray-700 placeholder-gray-400 outline-none dark:text-gray-200 dark:placeholder-gray-500"
                                    />
                                    <button
                                        onClick={() => setMenuOpen(!menuOpen)}
                                        className="-mr-1 flex h-7 w-7 items-center justify-center text-gray-400 transition-colors hover:text-gray-600 dark:text-gray-400 dark:hover:text-gray-200"
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
                        </div>
                    </div>
                </div>
            </div>
        </div>
    );
}
