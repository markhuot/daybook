import { useState, useRef, useEffect, useCallback } from 'react';
import { router } from '@inertiajs/react';

interface SearchResult {
    date: string;
    snippet: string;
    score: number;
}

interface Props {
    onNavigate?: (date: string) => void;
}

function getCsrfToken(): string {
    const match = document.cookie.match(/XSRF-TOKEN=([^;]+)/);
    return match ? decodeURIComponent(match[1]) : '';
}

function formatResultDate(dateStr: string): string {
    const [y, m, d] = dateStr.split('-').map(Number);
    const date = new Date(y, m - 1, d);
    return date.toLocaleDateString('en-US', { weekday: 'short', month: 'short', day: 'numeric' });
}

export default function FloatingMenu({ onNavigate }: Props) {
    const [menuOpen, setMenuOpen] = useState(false);
    const [query, setQuery] = useState('');
    const [results, setResults] = useState<SearchResult[]>([]);
    const [loading, setLoading] = useState(false);
    const [searched, setSearched] = useState(false);
    const menuRef = useRef<HTMLDivElement>(null);
    const debounceRef = useRef<ReturnType<typeof setTimeout> | null>(null);
    const abortRef = useRef<AbortController | null>(null);

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

    // Close search results on outside click
    useEffect(() => {
        function handleClickOutside(e: MouseEvent) {
            if (menuRef.current && !menuRef.current.contains(e.target as Node)) {
                setQuery('');
                setResults([]);
                setSearched(false);
            }
        }
        if (searched) {
            document.addEventListener('mousedown', handleClickOutside);
        }
        return () => document.removeEventListener('mousedown', handleClickOutside);
    }, [searched]);

    const search = useCallback((q: string) => {
        // Cancel any in-flight request
        abortRef.current?.abort();

        if (q.trim() === '') {
            setResults([]);
            setSearched(false);
            setLoading(false);
            return;
        }

        setLoading(true);
        const controller = new AbortController();
        abortRef.current = controller;

        fetch(`/search?q=${encodeURIComponent(q.trim())}`, {
            headers: {
                'Accept': 'application/json',
                'X-XSRF-TOKEN': getCsrfToken(),
            },
            signal: controller.signal,
        })
            .then((res) => res.json())
            .then((data: SearchResult[]) => {
                setResults(data);
                setSearched(true);
                setLoading(false);
            })
            .catch((err) => {
                if (err.name !== 'AbortError') {
                    setLoading(false);
                }
            });
    }, []);

    function handleInputChange(e: React.ChangeEvent<HTMLInputElement>) {
        const value = e.target.value;
        setQuery(value);

        if (debounceRef.current) {
            clearTimeout(debounceRef.current);
        }

        debounceRef.current = setTimeout(() => search(value), 300);
    }

    function handleKeyDown(e: React.KeyboardEvent<HTMLInputElement>) {
        if (e.key === 'Enter') {
            if (debounceRef.current) {
                clearTimeout(debounceRef.current);
            }
            search(query);
        }
        if (e.key === 'Escape') {
            setQuery('');
            setResults([]);
            setSearched(false);
            (e.target as HTMLInputElement).blur();
        }
    }

    function handleResultClick(date: string) {
        setQuery('');
        setResults([]);
        setSearched(false);
        if (onNavigate) {
            onNavigate(date);
        } else {
            router.visit(`/${date}`);
        }
    }

    function handleLogout() {
        router.post('/logout');
    }

    const showResults = searched && !loading;

    return (
        <div className="fixed bottom-10 left-0 right-0 z-50 pointer-events-none">
            <div className="mx-auto max-w-4xl pl-20" ref={menuRef}>
                <div className="relative inline-block pointer-events-auto">
                    {/* Search results popup */}
                    {showResults && results.length > 0 && (
                        <div className="absolute bottom-full left-0 mb-4 w-[400px] -rotate-1 bg-gray-100 p-1 shadow-[2px_3px_12px_rgba(0,0,0,0.12)] dark:bg-[#131113] dark:shadow-[2px_3px_16px_rgba(0,0,0,0.5)]">
                            <div className="max-h-64 overflow-y-auto">
                                {results.map((r) => (
                                    <button
                                        key={r.date}
                                        onClick={() => handleResultClick(r.date)}
                                        className="block w-full px-3 py-2 text-left hover:bg-white dark:hover:bg-[#181618]"
                                    >
                                        <span className="block text-xs font-medium text-gray-500 dark:text-gray-400">
                                            {formatResultDate(r.date)}
                                        </span>
                                        <span className="block truncate text-sm text-gray-700 dark:text-gray-200">
                                            {r.snippet || '(empty)'}
                                        </span>
                                    </button>
                                ))}
                            </div>
                        </div>
                    )}

                    {/* No results message */}
                    {showResults && results.length === 0 && query.trim() !== '' && (
                        <div className="absolute bottom-full left-0 mb-4 -rotate-1 bg-gray-100 px-4 py-3 shadow-[2px_3px_12px_rgba(0,0,0,0.12)] dark:bg-[#131113] dark:shadow-[2px_3px_16px_rgba(0,0,0,0.5)]">
                            <span className="text-sm text-gray-400 dark:text-gray-500">No matches found</span>
                        </div>
                    )}

                    {/* Menu popup */}
                    {menuOpen && (
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
                    <div className="rotate-1 bg-gray-100 px-5 py-2 shadow-[2px_3px_12px_rgba(0,0,0,0.12)] dark:bg-[#131113] dark:shadow-[2px_3px_16px_rgba(0,0,0,0.5)]">
                        <div className="flex items-center gap-4">
                            <input
                                type="text"
                                placeholder="Search..."
                                value={query}
                                onChange={handleInputChange}
                                onKeyDown={handleKeyDown}
                                className="bg-transparent text-gray-700 placeholder-gray-400 outline-none dark:text-gray-200 dark:placeholder-gray-500"
                            />
                            {loading && (
                                <span className="text-xs text-gray-400 dark:text-gray-500">…</span>
                            )}
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
    );
}
