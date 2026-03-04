import { useEffect, useRef, useCallback, useMemo, useState } from 'react';
import { router } from '@inertiajs/react';
import Editor from '@/components/Editor';
import FloatingMenu from '@/components/FloatingMenu';
import SessionExpiredOverlay from '@/components/SessionExpiredOverlay';

interface NoteEntry {
    id: number | null;
    content: Record<string, unknown> | null;
}

interface Note extends NoteEntry {
    date: string;
}

interface Props {
    note: Note;
    notes: Record<string, NoteEntry>;
    previousContent?: Record<string, unknown> | null;
    weeklySummary?: string | null;
}

function formatWeekday(date: Date): string {
    return date.toLocaleDateString('en-US', { weekday: 'long' });
}

function formatMonth(date: Date): string {
    return date.toLocaleDateString('en-US', { month: 'long' });
}

function formatDay(date: Date): string {
    return date.toLocaleDateString('en-US', { day: 'numeric' });
}

function toDateString(date: Date): string {
    const y = date.getFullYear();
    const m = String(date.getMonth() + 1).padStart(2, '0');
    const d = String(date.getDate()).padStart(2, '0');
    return `${y}-${m}-${d}`;
}

function parseDateLocal(dateStr: string): Date {
    const [y, m, d] = dateStr.split('-').map(Number);
    return new Date(y, m - 1, d);
}

function addDays(dateStr: string, days: number): string {
    const d = parseDateLocal(dateStr);
    d.setDate(d.getDate() + days);
    return toDateString(d);
}

function getCsrfToken(): string {
    const match = document.cookie.match(/XSRF-TOKEN=([^;]+)/);
    return match ? decodeURIComponent(match[1]) : '';
}

export default function Home({ note, notes: serverNotes, previousContent, weeklySummary }: Props) {
    const localToday = useMemo(() => toDateString(new Date()), []);

    // The date currently displayed. Starts from the server-provided note date.
    const [displayedDate, setDisplayedDate] = useState(note.date);
    const [summaryExpanded, setSummaryExpanded] = useState(false);

    // Cache ref: stores { id, content } keyed by date string.
    // undefined = unknown (fetch from server), { id: null, content: null } = known empty.
    const notesCacheRef = useRef<Record<string, NoteEntry>>({});

    // Seed the cache from server props on every Inertia visit
    useEffect(() => {
        // Merge server notes into cache (server is source of truth for these dates)
        for (const [date, entry] of Object.entries(serverNotes)) {
            notesCacheRef.current[date] = entry;
        }
        // Also ensure the primary note is in the cache
        notesCacheRef.current[note.date] = { id: note.id, content: note.content };
        // Sync displayed date to the server-provided date (happens on Inertia navigations)
        setDisplayedDate(note.date);
    }, [note.date, note.id, note.content, serverNotes]);

    // Derive all display values from displayedDate + cache
    const displayedNote = useMemo((): Note => {
        const cached = notesCacheRef.current[displayedDate];
        if (cached) {
            return { date: displayedDate, id: cached.id, content: cached.content };
        }
        // Fallback to server note if cache miss (shouldn't happen normally)
        if (displayedDate === note.date) {
            return note;
        }
        return { date: displayedDate, id: null, content: null };
    }, [displayedDate, note]);

    const noteDate = useMemo(() => parseDateLocal(displayedDate), [displayedDate]);

    const isToday = useMemo(() => localToday === displayedDate, [displayedDate, localToday]);

    // Safety net: if the backend returned a date that doesn't match local today
    // and no explicit date was requested (we're on "/"), redirect to the correct local date.
    useEffect(() => {
        if (window.location.pathname === '/' && note.date !== localToday) {
            router.visit(`/${localToday}`, { replace: true });
        }
    }, [note.date, localToday]);

    // Update browser title to reflect the displayed date
    useEffect(() => {
        document.title = `Daybook - ${formatWeekday(noteDate)}, ${formatMonth(noteDate)} ${formatDay(noteDate)}`;
    }, [noteDate]);

    const prevDate = useMemo(() => addDays(displayedDate, -1), [displayedDate]);
    const nextDate = useMemo(() => addDays(displayedDate, 1), [displayedDate]);

    const isTomorrow = useMemo(() => {
        const tomorrow = new Date();
        tomorrow.setDate(tomorrow.getDate() + 1);
        return nextDate === toDateString(tomorrow);
    }, [nextDate]);

    // Compute previousContent for today when it has no content.
    // The server always provides a placeholder (AI-generated or static).
    const effectivePreviousContent = useMemo(() => {
        if (!isToday) return undefined;

        // If the displayed note has content, no placeholder needed
        const currentEntry = notesCacheRef.current[displayedDate];
        if (currentEntry?.content) return undefined;

        return previousContent;
    }, [isToday, displayedDate, previousContent]);

    // Navigate to a date: use cache if available, otherwise fall back to Inertia visit
    const navigateTo = useCallback((targetDate: string) => {
        if (targetDate > localToday) return; // Don't navigate to future

        const cached = notesCacheRef.current[targetDate];
        if (cached !== undefined) {
            // Cache hit — render locally, update URL
            setDisplayedDate(targetDate);
            const path = targetDate === localToday ? '/' : `/${targetDate}`;
            window.history.pushState({}, '', path);
        } else {
            // Cache miss — full Inertia visit (will seed cache from server response)
            router.visit(`/${targetDate}`);
        }
    }, [localToday]);

    const timeoutRef = useRef<ReturnType<typeof setTimeout> | null>(null);
    const savingRef = useRef(false);
    const pendingContentRef = useRef<Record<string, unknown> | null>(null);

    const flushSave = useCallback((content: Record<string, unknown>) => {
        savingRef.current = true;
        fetch('/note', {
            method: 'PUT',
            redirect: 'manual',
            headers: {
                'Content-Type': 'application/json',
                'X-XSRF-TOKEN': getCsrfToken(),
                'Accept': 'application/json',
            },
            body: JSON.stringify({ content }),
        }).then(() => {
            savingRef.current = false;
            // If new content queued while we were saving, send it now
            const queued = pendingContentRef.current;
            if (queued) {
                pendingContentRef.current = null;
                flushSave(queued);
            }
        }, () => {
            savingRef.current = false;
            // On failure, retry the queued content (or this content) after a delay
            const queued = pendingContentRef.current ?? content;
            pendingContentRef.current = null;
            setTimeout(() => flushSave(queued), 2000);
        });
    }, []);

    // Use fetch() instead of router.put() to avoid Inertia redirect snap-back
    const handleUpdate = useCallback((content: Record<string, unknown>) => {
        // Optimistically update the cache for today
        notesCacheRef.current[localToday] = {
            ...notesCacheRef.current[localToday],
            content,
        };

        if (timeoutRef.current) {
            clearTimeout(timeoutRef.current);
        }

        timeoutRef.current = setTimeout(() => {
            if (savingRef.current) {
                // A save is already in-flight — queue this content for when it finishes
                pendingContentRef.current = content;
            } else {
                flushSave(content);
            }
        }, 500);
    }, [localToday, flushSave]);

    return (
        <div className="mx-auto flex min-h-screen max-w-4xl flex-col py-12" style={{ fontSize: '18px', lineHeight: '1.75' }}>
            <p className="px-4 pl-20 text-xs uppercase tracking-widest text-gray-400 dark:text-gray-500">{formatWeekday(noteDate)}</p>
            <div className="mb-8 flex items-center gap-3 px-4 pl-20">
                <a
                    href={`/${prevDate}`}
                    onClick={(e) => { e.preventDefault(); navigateTo(prevDate); }}
                    className="-ml-8 text-gray-400 hover:text-gray-900 dark:text-gray-500 dark:hover:text-gray-200"
                    aria-label="Previous day"
                >
                    &larr;
                </a>
                <span className="text-3xl">{formatMonth(noteDate)} <span className="text-highlight">{formatDay(noteDate)}</span></span>
                {!isToday && !isTomorrow && (
                    <a
                        href={`/${nextDate}`}
                        onClick={(e) => { e.preventDefault(); navigateTo(nextDate); }}
                        className="text-gray-400 hover:text-gray-900 dark:text-gray-500 dark:hover:text-gray-200"
                        aria-label="Next day"
                    >
                        &rarr;
                    </a>
                )}
                {!isToday && (
                    <a
                        href={`/${localToday}`}
                        onClick={(e) => { e.preventDefault(); navigateTo(localToday); }}
                        className="text-sm text-gray-400 hover:text-gray-900 dark:text-gray-500 dark:hover:text-gray-200"
                    >
                        Today
                    </a>
                )}
            </div>
            {weeklySummary && isToday && (
                <div
                    className="mb-8 ml-[4.25rem] mr-[2em] w-fit cursor-pointer"
                    onClick={() => setSummaryExpanded(prev => !prev)}
                >
                    <div className="mb-2 flex items-center gap-2">
                        <h2 className="text-xs uppercase tracking-widest text-gray-400 dark:text-gray-500">This week</h2>
                        <svg width="14" height="14" viewBox="0 0 14 14" fill="none" stroke="currentColor" strokeWidth="1" className="text-gray-400 dark:text-gray-500">
                            <circle cx="7" cy="7" r="6" />
                            <line x1="7" y1="6" x2="7" y2="10" />
                            <circle cx="7" cy="4.25" r="0.5" fill="currentColor" stroke="none" />
                        </svg>
                    </div>
                    <div className={`relative ${summaryExpanded ? '' : 'max-h-20 overflow-hidden'}`}>
                        <div
                            className="prose prose-sm prose-gray dark:prose-invert [&_h2]:text-sm [&_h2]:font-medium"
                            dangerouslySetInnerHTML={{ __html: weeklySummary }}
                        />
                        {!summaryExpanded && (
                            <div className="absolute inset-x-0 bottom-0 h-16 bg-gradient-to-t from-white to-transparent dark:from-[#19171B]" />
                        )}
                    </div>
                </div>
            )}
            <Editor
                key={displayedDate}
                content={displayedNote.content}
                previousContent={isToday ? effectivePreviousContent : undefined}
                onUpdate={isToday ? handleUpdate : undefined}
                editable={isToday}
            />
            <FloatingMenu onNavigate={navigateTo} />
            <SessionExpiredOverlay />
        </div>
    );
}
