import { useEffect, useRef, useCallback, useMemo, useState, useLayoutEffect } from 'react';
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

type AnimPhase = 'idle' | 'exiting' | 'entering';
type AnimDirection = 'backward' | 'forward';

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

    // --- Header date transition animation ---
    // `renderedDate` is the date currently shown in the header. It lags behind
    // `displayedDate` during animations so the old text stays visible while it
    // slides out, and only swaps to the new date once the exit animation ends.
    const [renderedDate, setRenderedDate] = useState(note.date);
    // 'idle' = no animation, 'exiting' = old date sliding out, 'entering' = new date sliding in
    const [animPhase, setAnimPhase] = useState<AnimPhase>('idle');
    // Direction: 'backward' = navigating to an earlier day, 'forward' = navigating to a later day
    const [animDirection, setAnimDirection] = useState<AnimDirection>('backward');
    const headerRef = useRef<HTMLSpanElement>(null);
    const weekdayRef = useRef<HTMLParagraphElement>(null);
    // Pending date to apply after exit animation completes
    const pendingDateRef = useRef<string | null>(null);

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
        setRenderedDate(note.date);
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
    // renderedNoteDate is what the header actually displays — it lags behind during animations
    const renderedNoteDate = useMemo(() => parseDateLocal(renderedDate), [renderedDate]);

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

    // Navigate to a date: use cache if available, otherwise fall back to Inertia visit.
    // When cached, triggers a slide animation on the header date.
    const navigateTo = useCallback((targetDate: string) => {
        if (targetDate > localToday) return; // Don't navigate to future

        const cached = notesCacheRef.current[targetDate];
        if (cached !== undefined) {
            // If an animation is already in progress, reset elements to neutral
            // so the new animation starts cleanly.
            if (animPhase !== 'idle') {
                const header = headerRef.current;
                const weekday = weekdayRef.current;
                if (header) {
                    header.style.transition = 'none';
                    header.style.transform = '';
                    header.style.opacity = '1';
                }
                if (weekday) {
                    weekday.style.transition = 'none';
                    weekday.style.transform = '';
                    weekday.style.opacity = '1';
                }
                // Sync renderedDate to the current displayedDate so
                // the outgoing text is what was last targeted
                setRenderedDate(displayedDate);
            }

            // Cache hit — animate the header transition
            const direction = targetDate < displayedDate ? 'backward' : 'forward';
            setAnimDirection(direction);
            pendingDateRef.current = targetDate;
            setAnimPhase('exiting');

            // Update the underlying displayed date and URL immediately
            // (this drives the editor swap via `key={displayedDate}`)
            setDisplayedDate(targetDate);
            const path = targetDate === localToday ? '/' : `/${targetDate}`;
            window.history.pushState({}, '', path);
        } else {
            // Cache miss — full Inertia visit (will seed cache from server response)
            router.visit(`/${targetDate}`);
        }
    }, [localToday, displayedDate, animPhase]);

    // --- Header animation: drive the exit → swap → enter sequence ---
    useLayoutEffect(() => {
        if (animPhase === 'idle') return;

        const header = headerRef.current;
        const weekday = weekdayRef.current;
        if (!header || !weekday) {
            // No refs — skip animation and just sync
            if (pendingDateRef.current) {
                setRenderedDate(pendingDateRef.current);
                pendingDateRef.current = null;
            }
            setAnimPhase('idle');
            return;
        }

        // Exit direction: backward navigation → old text exits right, forward → exits left
        const exitTranslate = animDirection === 'backward' ? 'translateX(24px)' : 'translateX(-24px)';
        const enterStart = animDirection === 'backward' ? 'translateX(-24px)' : 'translateX(24px)';

        if (animPhase === 'exiting') {
            // Start the exit: slide + fade out
            header.style.transition = 'transform 150ms ease-in, opacity 150ms ease-in';
            weekday.style.transition = 'transform 150ms ease-in, opacity 150ms ease-in';
            header.style.transform = exitTranslate;
            header.style.opacity = '0';
            weekday.style.transform = exitTranslate;
            weekday.style.opacity = '0';

            const onExitEnd = () => {
                header.removeEventListener('transitionend', onExitEnd);
                // Swap to the new date while invisible
                if (pendingDateRef.current) {
                    setRenderedDate(pendingDateRef.current);
                    pendingDateRef.current = null;
                }
                // Position for enter: start off-screen on the opposite side
                header.style.transition = 'none';
                weekday.style.transition = 'none';
                header.style.transform = enterStart;
                weekday.style.transform = enterStart;
                // Force reflow so the browser registers the new position
                header.offsetHeight;
                setAnimPhase('entering');
            };

            header.addEventListener('transitionend', onExitEnd, { once: true });
            return;
        }

        if (animPhase === 'entering') {
            // Slide + fade in to resting position
            requestAnimationFrame(() => {
                header.style.transition = 'transform 150ms ease-out, opacity 150ms ease-out';
                weekday.style.transition = 'transform 150ms ease-out, opacity 150ms ease-out';
                header.style.transform = 'translateX(0)';
                header.style.opacity = '1';
                weekday.style.transform = 'translateX(0)';
                weekday.style.opacity = '1';

                const onEnterEnd = () => {
                    header.removeEventListener('transitionend', onEnterEnd);
                    // Clean up inline styles
                    header.style.transition = '';
                    header.style.transform = '';
                    header.style.opacity = '';
                    weekday.style.transition = '';
                    weekday.style.transform = '';
                    weekday.style.opacity = '';
                    setAnimPhase('idle');
                };

                header.addEventListener('transitionend', onEnterEnd, { once: true });
            });
        }
    }, [animPhase, animDirection]);

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

    // Catch-up logic: fetch any steps we missed while disconnected or
    // backgrounded (e.g. phone locked, tab hidden, network drop).
    const catchUp = useCallback(() => {
        const view = editorViewRef.current;
        if (!view) return;

        // If the date has rolled over while we were away, reload so the
        // user gets today's fresh note instead of editing yesterday's.
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
                // Silent failure — will retry on next event
            });
    }, [localToday, applyReceivedSteps, sendSteps]);

    // Re-sync when the browser comes back online (network reconnection)
    useEffect(() => {
        if (!isToday) return;

        const handleOnline = () => catchUp();

        window.addEventListener('online', handleOnline);
        return () => window.removeEventListener('online', handleOnline);
    }, [isToday, catchUp]);

    // Re-sync when the page becomes visible again (e.g. user unlocks phone,
    // switches back to this tab after it was backgrounded for a while).
    useEffect(() => {
        if (!isToday) return;

        const handleVisibilityChange = () => {
            if (document.visibilityState === 'visible') {
                catchUp();
            }
        };

        document.addEventListener('visibilitychange', handleVisibilityChange);
        return () => document.removeEventListener('visibilitychange', handleVisibilityChange);
    }, [isToday, catchUp]);

    return (
        <div className="mx-auto flex min-h-screen max-w-4xl flex-col py-12" style={{ fontSize: '18px', lineHeight: '1.75' }}>
            <p ref={weekdayRef} className="pl-20 pr-4 text-xs uppercase tracking-widest text-gray-400 dark:text-gray-500">{formatWeekday(renderedNoteDate)}</p>
            <div className="relative mb-8 flex items-center gap-3 pl-20 pr-4">
                <div
                    tabIndex={0}
                    role="group"
                    aria-label={`${formatMonth(renderedNoteDate)} ${formatDay(renderedNoteDate)}, use left and right arrow keys to change date`}
                    className="flex items-center gap-3 outline-none focus-visible:ring-2 focus-visible:ring-gray-400 focus-visible:rounded dark:focus-visible:ring-gray-500"
                    onKeyDown={(e) => {
                        if (e.key === 'ArrowLeft') {
                            e.preventDefault();
                            navigateTo(prevDate);
                        } else if (e.key === 'ArrowRight' && !isToday && !isTomorrow) {
                            e.preventDefault();
                            navigateTo(nextDate);
                        }
                    }}
                >
                    <span
                        onClick={() => navigateTo(prevDate)}
                        className="absolute left-12 top-1/2 -translate-y-1/2 cursor-pointer text-gray-400 hover:text-gray-900 dark:text-gray-500 dark:hover:text-gray-200"
                        aria-hidden="true"
                    >
                        &larr;
                    </span>
                    <span ref={headerRef} className="text-3xl">{formatMonth(renderedNoteDate)} <span className="text-highlight">{formatDay(renderedNoteDate)}</span></span>
                    {!isToday && !isTomorrow && (
                        <span
                            onClick={() => navigateTo(nextDate)}
                            className="cursor-pointer text-gray-400 hover:text-gray-900 dark:text-gray-500 dark:hover:text-gray-200"
                            aria-hidden="true"
                        >
                            &rarr;
                        </span>
                    )}
                </div>
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
                key={displayedDate}
                content={displayedNote.content}
                previousContent={isToday ? effectivePreviousContent : undefined}
                onUpdate={isToday ? handleUpdate : undefined}
                editable={isToday}
                version={isToday ? note.version : undefined}
                viewRef={isToday ? editorViewRef : undefined}
                clientID={clientIDRef.current}
            />
            <FloatingMenu />
            <SessionExpiredOverlay />
        </div>
    );
}
