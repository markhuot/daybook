import { useEffect, useRef, useCallback, useMemo, useState } from 'react';
import { router } from '@inertiajs/react';
import { EditorView } from 'prosemirror-view';
import { sendableSteps, receiveTransaction, getVersion } from 'prosemirror-collab';
import { Step } from 'prosemirror-transform';
import { useEcho } from '@laravel/echo-react';
import Editor from '@/components/Editor';
import FloatingMenu from '@/components/FloatingMenu';
import SessionExpiredOverlay from '@/components/SessionExpiredOverlay';
import { schema } from '@/components/editor/schema';

interface NoteEntry {
    id: number | null;
    content: Record<string, unknown> | null;
}

interface Note extends NoteEntry {
    date: string;
    version: number;
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

function getCsrfToken(): string {
    const match = document.cookie.match(/XSRF-TOKEN=([^;]+)/);
    return match ? decodeURIComponent(match[1]) : '';
}

export default function Home({ note, notes: serverNotes, previousContent, weeklySummary }: Props) {
    const localToday = useMemo(() => toDateString(new Date()), []);

    const [summaryExpanded, setSummaryExpanded] = useState(false);

    // Scroll container ref for the horizontal scroll-snap
    const scrollContainerRef = useRef<HTMLDivElement>(null);

    // Build a sorted list of all dates in the window
    const sortedDates = useMemo(() => {
        return Object.keys(serverNotes).sort();
    }, [serverNotes]);

    // Find the index of the current note's date so we can scroll to it on mount
    const currentDateIndex = useMemo(() => {
        const idx = sortedDates.indexOf(note.date);
        return idx >= 0 ? idx : sortedDates.length - 1;
    }, [sortedDates, note.date]);

    // Scroll to the current date's panel on mount (no animation)
    useEffect(() => {
        const container = scrollContainerRef.current;
        if (!container) return;
        container.scrollLeft = currentDateIndex * window.innerWidth;
    }, [currentDateIndex]);

    // Track the currently visible date based on scroll position for URL/title updates
    const [visibleDate, setVisibleDate] = useState(note.date);

    useEffect(() => {
        const container = scrollContainerRef.current;
        if (!container) return;

        let ticking = false;
        const handleScroll = () => {
            if (ticking) return;
            ticking = true;
            requestAnimationFrame(() => {
                const index = Math.round(container.scrollLeft / window.innerWidth);
                const clampedIndex = Math.max(0, Math.min(index, sortedDates.length - 1));
                const dateAtIndex = sortedDates[clampedIndex];
                if (dateAtIndex) {
                    setVisibleDate(dateAtIndex);
                }
                ticking = false;
            });
        };

        container.addEventListener('scroll', handleScroll, { passive: true });
        return () => container.removeEventListener('scroll', handleScroll);
    }, [sortedDates]);

    // Update browser URL and title when the visible date changes
    useEffect(() => {
        const path = visibleDate === localToday ? '/' : `/${visibleDate}`;
        if (window.location.pathname !== path) {
            window.history.replaceState({}, '', path);
        }
        const d = parseDateLocal(visibleDate);
        document.title = `Daybook - ${formatWeekday(d)}, ${formatMonth(d)} ${formatDay(d)}`;
    }, [visibleDate, localToday]);

    // Safety net: if the backend returned a date that doesn't match local today
    // and no explicit date was requested (we're on "/"), redirect to the correct local date.
    useEffect(() => {
        if (window.location.pathname === '/' && note.date !== localToday) {
            router.visit(`/${localToday}`, { replace: true });
        }
    }, [note.date, localToday]);

    // Compute previousContent for today when it has no content.
    const effectivePreviousContent = useMemo(() => {
        const todayEntry = serverNotes[localToday];
        if (todayEntry?.content) return undefined;
        return previousContent;
    }, [localToday, serverNotes, previousContent]);

    // --- Collab: step-sending and receiving ---

    // Per-tab unique client ID for the collab plugin
    const clientIDRef = useRef(
        typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function'
            ? crypto.randomUUID()
            : Math.random().toString(36).slice(2) + Date.now().toString(36),
    );

    // EditorView ref shared with the Editor component (for today's editor only)
    const editorViewRef = useRef<EditorView | null>(null);

    // Track whether a step send is in-flight to serialize sends
    const sendingRef = useRef(false);
    // Track whether we need to retry sending after current send completes
    const sendQueuedRef = useRef(false);

    // Debounce timer for batching rapid keystrokes into fewer HTTP requests
    const sendTimerRef = useRef<ReturnType<typeof setTimeout> | null>(null);

    const sendSteps = useCallback(() => {
        const view = editorViewRef.current;
        if (!view || sendingRef.current) {
            if (view && sendingRef.current) {
                sendQueuedRef.current = true;
            }
            return;
        }

        const sendable = sendableSteps(view.state);
        if (!sendable) return;

        sendingRef.current = true;

        fetch('/note/steps', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-XSRF-TOKEN': getCsrfToken(),
                'Accept': 'application/json',
            },
            body: JSON.stringify({
                version: sendable.version,
                steps: sendable.steps.map(s => s.toJSON()),
                clientID: sendable.clientID,
                doc: view.state.doc.toJSON(),
            }),
        })
            .then(res => {
                if (res.status === 409) {
                    return res.json().then((data: { version: number; steps: unknown[]; clientIDs: (string | number)[] }) => {
                        applyReceivedSteps(data);
                        sendingRef.current = false;
                        sendSteps();
                    });
                }
                if (!res.ok) {
                    sendingRef.current = false;
                    setTimeout(sendSteps, 2000);
                    return;
                }
                const currentView = editorViewRef.current;
                if (currentView) {
                    const tr = receiveTransaction(
                        currentView.state,
                        sendable.steps,
                        sendable.steps.map(() => sendable.clientID),
                    );
                    currentView.dispatch(tr);
                }
                sendingRef.current = false;
                if (sendQueuedRef.current) {
                    sendQueuedRef.current = false;
                    sendSteps();
                }
            })
            .catch(() => {
                sendingRef.current = false;
                setTimeout(sendSteps, 2000);
            });
    }, []);

    const scheduleSendSteps = useCallback(() => {
        if (sendTimerRef.current) {
            clearTimeout(sendTimerRef.current);
        }
        sendTimerRef.current = setTimeout(sendSteps, 100);
    }, [sendSteps]);

    const applyReceivedSteps = useCallback((data: { version: number; steps: unknown[]; clientIDs: (string | number)[] }) => {
        const view = editorViewRef.current;
        if (!view || !data.steps || data.steps.length === 0) return;

        const steps = data.steps.map(s => Step.fromJSON(schema, s as Record<string, unknown>));
        const tr = receiveTransaction(view.state, steps, data.clientIDs);
        view.dispatch(tr);
    }, []);

    // Listen for broadcast steps from other tabs via Echo/Reverb
    const todayNoteEntry = serverNotes[localToday];
    const channelName = todayNoteEntry?.id ? `note.${todayNoteEntry.id}` : '';

    useEcho(
        channelName,
        '.steps.accepted',
        (event: { version: number; steps: unknown[]; clientIDs: (string | number)[] }) => {
            const myID = clientIDRef.current;
            if (event.clientIDs.every(id => String(id) === String(myID))) return;
            applyReceivedSteps(event);
        },
        [applyReceivedSteps, channelName],
    );

    // Called by Editor on every docChanged (today's editor only)
    const handleUpdate = useCallback((content: Record<string, unknown>) => {
        void content;
        scheduleSendSteps();
    }, [scheduleSendSteps]);

    // Catch-up logic: fetch any steps we missed while disconnected
    const catchUp = useCallback(() => {
        const view = editorViewRef.current;
        if (!view) return;

        const now = toDateString(new Date());
        if (now !== localToday) {
            window.location.reload();
            return;
        }

        const currentVersion = getVersion(view.state);
        fetch(`/note/steps?since=${currentVersion}`, {
            headers: {
                'X-XSRF-TOKEN': getCsrfToken(),
                'Accept': 'application/json',
            },
        })
            .then(res => {
                if (res.status === 410) {
                    window.location.reload();
                    return;
                }
                if (!res.ok) return;
                return res.json();
            })
            .then((data: { version: number; steps: unknown[]; clientIDs: (string | number)[] } | undefined) => {
                if (data && data.steps && data.steps.length > 0) {
                    applyReceivedSteps(data);
                }
                sendSteps();
            })
            .catch(() => {});
    }, [localToday, applyReceivedSteps, sendSteps]);

    // Re-sync when the browser comes back online
    useEffect(() => {
        const handleOnline = () => catchUp();
        window.addEventListener('online', handleOnline);
        return () => window.removeEventListener('online', handleOnline);
    }, [catchUp]);

    // Re-sync when the page becomes visible again
    useEffect(() => {
        const handleVisibilityChange = () => {
            if (document.visibilityState === 'visible') {
                catchUp();
            }
        };
        document.addEventListener('visibilitychange', handleVisibilityChange);
        return () => document.removeEventListener('visibilitychange', handleVisibilityChange);
    }, [catchUp]);

    return (
        <div
            ref={scrollContainerRef}
            className="daybook-scroll-container"
        >
            {sortedDates.map((dateStr) => {
                const entry = serverNotes[dateStr];
                const dateObj = parseDateLocal(dateStr);
                const isDayToday = dateStr === localToday;

                return (
                    <div key={dateStr} className="daybook-day-panel">
                        <div className="mx-auto flex h-full max-w-4xl flex-col pt-24" style={{ fontSize: '18px', lineHeight: '1.75' }}>
                            <div className="daybook-sticky-header sticky top-0 z-10 mb-8">
                                <p className="pl-20 pr-4 text-xs uppercase tracking-widest text-gray-400 dark:text-gray-500">
                                    {formatWeekday(dateObj)}
                                </p>
                                <div className="relative flex items-center gap-3 pl-20 pr-4">
                                    <span className="text-3xl">
                                        {formatMonth(dateObj)} <span className="text-highlight">{formatDay(dateObj)}</span>
                                    </span>
                                    {!isDayToday && (
                                        <span className="text-sm text-gray-400 dark:text-gray-500">
                                            read only
                                        </span>
                                    )}
                                </div>
                            </div>
                            {weeklySummary && isDayToday && (
                                <div
                                    className="mt-8 mb-8 pl-20 pr-4 w-fit cursor-pointer"
                                    onClick={() => setSummaryExpanded(prev => !prev)}
                                >
                                    <div className="mb-2 flex items-center gap-1">
                                        <h2 className="text-xs uppercase tracking-widest text-yellow-500 dark:text-yellow-600">This week</h2>
                                        <svg width="12" height="12" viewBox="0 0 24 24" fill="currentColor" className="text-yellow-500 dark:text-yellow-600 -translate-y-[3px]">
                                            <path d="M10 0C10 6 6 10 0 10C6 10 10 14 10 20C10 14 14 10 20 10C14 10 10 6 10 0Z" />
                                            <path d="M20 6C20 8 18 10 16 10C18 10 20 12 20 14C20 12 22 10 24 10C22 10 20 8 20 6Z" />
                                            <path d="M18 16C18 18.5 16 20 14 20C16 20 18 22 18 24C18 22 20 20 22 20C20 20 18 18.5 18 16Z" />
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
                                key={dateStr}
                                content={entry?.content ?? null}
                                previousContent={isDayToday ? effectivePreviousContent : undefined}
                                onUpdate={isDayToday ? handleUpdate : undefined}
                                editable={isDayToday}
                                version={isDayToday ? note.version : undefined}
                                viewRef={isDayToday ? editorViewRef : undefined}
                                clientID={clientIDRef.current}
                            />
                        </div>
                    </div>
                );
            })}
            <FloatingMenu />
            <SessionExpiredOverlay />
        </div>
    );
}
