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
            return { date: displayedDate, id: cached.id, content: cached.content, version: note.version };
        }
        // Fallback to server note if cache miss (shouldn't happen normally)
        if (displayedDate === note.date) {
            return note;
        }
        return { date: displayedDate, id: null, content: null, version: 0 };
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

    // --- Collab: step-sending and receiving ---

    // Per-tab unique client ID for the collab plugin
    const clientIDRef = useRef(
        typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function'
            ? crypto.randomUUID()
            : Math.random().toString(36).slice(2) + Date.now().toString(36),
    );

    // EditorView ref shared with the Editor component
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
                    // Version mismatch — server includes missing steps for catch-up
                    return res.json().then((data: { version: number; steps: unknown[]; clientIDs: (string | number)[] }) => {
                        applyReceivedSteps(data);
                        // After rebase, retry sending our steps
                        sendingRef.current = false;
                        sendSteps();
                    });
                }
                if (!res.ok) {
                    // Other error — retry after delay
                    sendingRef.current = false;
                    setTimeout(sendSteps, 2000);
                    return;
                }
                // Success — confirm our steps with the collab plugin so
                // they move from "unconfirmed" to "confirmed" and the
                // local version advances.
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
    // Only subscribe when we have a note ID (note exists in DB)
    const channelName = displayedNote.id && isToday ? `note.${displayedNote.id}` : '';

    useEcho(
        channelName,
        '.steps.accepted',
        (event: { version: number; steps: unknown[]; clientIDs: (string | number)[] }) => {
            // Skip broadcasts that originated entirely from this tab —
            // those steps were already confirmed in the POST 200 handler.
            const myID = clientIDRef.current;
            if (event.clientIDs.every(id => String(id) === String(myID))) return;
            applyReceivedSteps(event);
        },
        [applyReceivedSteps, channelName],
    );

    // --- Collab: sending steps on editor update ---

    // Called by Editor on every docChanged
    const handleUpdate = useCallback((content: Record<string, unknown>) => {
        // Optimistically update the cache for today
        notesCacheRef.current[localToday] = {
            ...notesCacheRef.current[localToday],
            content,
        };

        // Use collab step-sending instead of full-document save
        scheduleSendSteps();
    }, [localToday, scheduleSendSteps]);

    // Reconnection catch-up: when WebSocket reconnects, fetch missed steps
    // This is handled by Echo's automatic reconnection — after reconnecting,
    // we fetch any steps we missed while disconnected.
    useEffect(() => {
        if (!isToday || !editorViewRef.current) return;

        const handleOnline = () => {
            const view = editorViewRef.current;
            if (!view) return;

            const currentVersion = getVersion(view.state);
            fetch(`/note/steps?since=${currentVersion}`, {
                headers: {
                    'X-XSRF-TOKEN': getCsrfToken(),
                    'Accept': 'application/json',
                },
            })
                .then(res => {
                    if (res.status === 410) {
                        // Steps expired from Redis — full reload needed
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
                    // After catching up, send any pending local steps
                    sendSteps();
                })
                .catch(() => {
                    // Silent failure — will retry on next reconnection
                });
        };

        window.addEventListener('online', handleOnline);
        return () => window.removeEventListener('online', handleOnline);
    }, [isToday, applyReceivedSteps, sendSteps]);

    return (
        <div className="mx-auto flex min-h-screen max-w-4xl flex-col py-12" style={{ fontSize: '18px', lineHeight: '1.75' }}>
            <p className="pl-20 pr-4 text-xs uppercase tracking-widest text-gray-400 dark:text-gray-500">{formatWeekday(noteDate)}</p>
            <div className="relative mb-8 flex items-center gap-3 pl-20 pr-4">
                <a
                    href={`/${prevDate}`}
                    onClick={(e) => { e.preventDefault(); navigateTo(prevDate); }}
                    className="absolute left-12 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-900 dark:text-gray-500 dark:hover:text-gray-200"
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
                    className="mb-8 pl-20 pr-4 w-fit cursor-pointer"
                    onClick={() => setSummaryExpanded(prev => !prev)}
                >
                    <div className="mb-2 flex items-center gap-1">
                        <h2 className="text-xs uppercase tracking-widest text-gray-400 dark:text-gray-500">This week</h2>
                        <svg width="12" height="12" viewBox="0 0 24 24" fill="currentColor" className="text-gray-400 dark:text-gray-500 -translate-y-1">
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
                key={displayedDate}
                content={displayedNote.content}
                previousContent={isToday ? effectivePreviousContent : undefined}
                onUpdate={isToday ? handleUpdate : undefined}
                editable={isToday}
                version={isToday ? note.version : undefined}
                viewRef={isToday ? editorViewRef : undefined}
                clientID={clientIDRef.current}
            />
            <FloatingMenu onNavigate={navigateTo} />
            <SessionExpiredOverlay />
        </div>
    );
}
