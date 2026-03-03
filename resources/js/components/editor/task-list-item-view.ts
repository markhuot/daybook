import { Node } from 'prosemirror-model';
import { EditorView, NodeView } from 'prosemirror-view';

/**
 * Format a number of seconds into a human-readable string.
 * Examples: "0s", "45s", "3m", "3m 12s", "1h 30m"
 */
function formatTime(totalSeconds: number): string {
    if (totalSeconds <= 0) return '0s';
    const d = Math.floor(totalSeconds / 86400);
    const h = Math.floor((totalSeconds % 86400) / 3600);
    const m = Math.floor((totalSeconds % 3600) / 60);
    const s = Math.floor(totalSeconds % 60);

    if (d > 0) return `${d}d`;
    if (h > 0) return `${h}h`;
    if (m > 0) return `${m}m`;
    return `${s}s`;
}

function formatTimeFull(totalSeconds: number): string {
    if (totalSeconds <= 0) return '0s';
    const d = Math.floor(totalSeconds / 86400);
    const h = Math.floor((totalSeconds % 86400) / 3600);
    const m = Math.floor((totalSeconds % 3600) / 60);
    const s = Math.floor(totalSeconds % 60);

    const parts: string[] = [];
    if (d > 0) parts.push(`${d}d`);
    if (h > 0) parts.push(`${h}h`);
    if (m > 0) parts.push(`${m}m`);
    if (s > 0 || parts.length === 0) parts.push(`${s}s`);
    return parts.join(' ');
}

/**
 * Parse a freeform time string like "2m 18s", "1h 30m", "45s", or "3m".
 * A bare number is treated as minutes.
 * Returns total seconds, or null if unparseable.
 */
function parseTime(input: string): number | null {
    const trimmed = input.trim();
    if (!trimmed) return 0;

    let total = 0;
    let matched = false;

    const hMatch = trimmed.match(/(\d+)\s*h/);
    const mMatch = trimmed.match(/(\d+)\s*m/);
    const sMatch = trimmed.match(/(\d+)\s*s/);

    if (hMatch) { total += parseInt(hMatch[1]) * 3600; matched = true; }
    if (mMatch) { total += parseInt(mMatch[1]) * 60; matched = true; }
    if (sMatch) { total += parseInt(sMatch[1]); matched = true; }

    if (!matched) {
        const num = parseInt(trimmed);
        if (!isNaN(num)) return num * 60;
        return null;
    }

    return total;
}

const SVG_NS = 'http://www.w3.org/2000/svg';

function createPlayIcon(): SVGSVGElement {
    const svg = document.createElementNS(SVG_NS, 'svg');
    svg.setAttribute('width', '8');
    svg.setAttribute('height', '10');
    svg.setAttribute('viewBox', '0 0 8 10');
    svg.setAttribute('fill', 'currentColor');
    svg.classList.add('task-timer-icon');
    const path = document.createElementNS(SVG_NS, 'path');
    path.setAttribute('d', 'M0 0.5v9l7.5-4.5L0 0.5z');
    svg.appendChild(path);
    return svg;
}

function createPauseIcon(): SVGSVGElement {
    const svg = document.createElementNS(SVG_NS, 'svg');
    svg.setAttribute('width', '8');
    svg.setAttribute('height', '10');
    svg.setAttribute('viewBox', '0 0 8 10');
    svg.setAttribute('fill', 'currentColor');
    svg.classList.add('task-timer-icon');
    const r1 = document.createElementNS(SVG_NS, 'rect');
    r1.setAttribute('x', '0');
    r1.setAttribute('y', '0');
    r1.setAttribute('width', '2.5');
    r1.setAttribute('height', '10');
    r1.setAttribute('rx', '0.5');
    const r2 = document.createElementNS(SVG_NS, 'rect');
    r2.setAttribute('x', '5.5');
    r2.setAttribute('y', '0');
    r2.setAttribute('width', '2.5');
    r2.setAttribute('height', '10');
    r2.setAttribute('rx', '0.5');
    svg.appendChild(r1);
    svg.appendChild(r2);
    return svg;
}

export function taskListItemView(
    node: Node,
    view: EditorView,
    getPos: () => number | undefined,
): NodeView {
    const dom = document.createElement('li');
    dom.classList.add('task-list-item');
    dom.dataset.checked = String(node.attrs.checked);

    // ── Timer gutter ───────────────────────────────────────────────
    const timerEl = document.createElement('span');
    timerEl.contentEditable = 'false';
    timerEl.classList.add('task-timer');

    let tickInterval: ReturnType<typeof setInterval> | null = null;
    let clickTimeout: ReturnType<typeof setTimeout> | null = null;
    let isEditing = false;
    let timeTextNode: Text | null = null;

    function getElapsed(): number {
        let elapsed = node.attrs.timerSeconds || 0;
        if (node.attrs.timerRunning && node.attrs.timerStartedAt) {
            elapsed += (Date.now() - node.attrs.timerStartedAt) / 1000;
        }
        return Math.max(0, Math.floor(elapsed));
    }

    function renderTimer() {
        if (isEditing) return;

        const running = node.attrs.timerRunning;
        const seconds = node.attrs.timerSeconds || 0;

        // Clear existing children
        timerEl.textContent = '';
        timeTextNode = null;

        if (!running && seconds === 0) {
            // Idle: show play icon only
            timerEl.className = 'task-timer task-timer--idle';
            timerEl.appendChild(createPlayIcon());
            timerEl.title = '';
        } else if (running) {
            // Running: "3m 12s" + pause icon
            const elapsed = getElapsed();
            timerEl.className = 'task-timer task-timer--running';
            timeTextNode = document.createTextNode(formatTime(elapsed));
            timerEl.appendChild(timeTextNode);
            timerEl.appendChild(createPauseIcon());
            timerEl.title = formatTimeFull(elapsed);
        } else {
            // Paused with time: "3m 12s" (no icon — click to resume)
            timerEl.className = 'task-timer task-timer--paused';
            timeTextNode = document.createTextNode(formatTime(seconds));
            timerEl.appendChild(timeTextNode);
            timerEl.title = formatTimeFull(seconds);
        }
    }

    function startTick() {
        stopTick();
        tickInterval = setInterval(() => {
            if (node.attrs.timerRunning && !isEditing && timeTextNode) {
                const elapsed = getElapsed();
                timeTextNode.textContent = formatTime(elapsed);
                timerEl.title = formatTimeFull(elapsed);
            }
        }, 1000);
    }

    function stopTick() {
        if (tickInterval !== null) {
            clearInterval(tickInterval);
            tickInterval = null;
        }
    }

    function dispatchAttrs(attrs: Record<string, unknown>) {
        const pos = getPos();
        if (pos === undefined) return;
        view.dispatch(
            view.state.tr.setNodeMarkup(pos, null, {
                ...node.attrs,
                ...attrs,
            }),
        );
    }

    function handlePlayPause() {
        if (!view.editable) return;
        if (node.attrs.timerRunning) {
            // Pause
            dispatchAttrs({
                timerSeconds: getElapsed(),
                timerRunning: false,
                timerStartedAt: null,
            });
        } else {
            // Start / resume
            dispatchAttrs({
                timerRunning: true,
                timerStartedAt: Date.now(),
            });
        }
    }

    function handleEdit() {
        if (!view.editable) return;
        isEditing = true;

        // Pause if currently running so we have a stable value
        if (node.attrs.timerRunning) {
            dispatchAttrs({
                timerSeconds: getElapsed(),
                timerRunning: false,
                timerStartedAt: null,
            });
        }

        const input = document.createElement('input');
        input.type = 'text';
        input.classList.add('task-timer-input');
        const currentSeconds = node.attrs.timerSeconds || 0;
        input.value = currentSeconds > 0 ? formatTime(currentSeconds) : '';
        input.placeholder = '0m 0s';

        timerEl.textContent = '';
        timerEl.className = 'task-timer task-timer--editing';
        timerEl.appendChild(input);
        input.focus();
        input.select();

        let committed = false;

        function commit() {
            if (committed) return;
            committed = true;
            isEditing = false;
            const parsed = parseTime(input.value);
            if (parsed !== null) {
                dispatchAttrs({
                    timerSeconds: parsed,
                    timerRunning: false,
                    timerStartedAt: null,
                });
            } else {
                renderTimer();
            }
        }

        function cancel() {
            if (committed) return;
            committed = true;
            isEditing = false;
            renderTimer();
        }

        input.addEventListener('blur', () => commit());
        input.addEventListener('keydown', (e) => {
            if (e.key === 'Enter') {
                e.preventDefault();
                input.blur();
            } else if (e.key === 'Escape') {
                e.preventDefault();
                cancel();
            }
        });
    }

    // Prevent editor focus loss on clicks
    timerEl.addEventListener('mousedown', (e) => {
        e.preventDefault();
    });

    // Single-click with double-click protection
    timerEl.addEventListener('click', (e) => {
        e.preventDefault();
        if (!view.editable || isEditing) return;

        if (clickTimeout) {
            clearTimeout(clickTimeout);
            clickTimeout = null;
            return;
        }

        clickTimeout = setTimeout(() => {
            clickTimeout = null;
            handlePlayPause();
        }, 250);
    });

    // Double-click opens freeform time editor
    timerEl.addEventListener('dblclick', (e) => {
        e.preventDefault();
        e.stopPropagation();
        if (!view.editable) return;
        if (clickTimeout) {
            clearTimeout(clickTimeout);
            clickTimeout = null;
        }
        handleEdit();
    });

    dom.appendChild(timerEl);

    // ── Checkbox ───────────────────────────────────────────────────
    const checkboxWrapper = document.createElement('span');
    checkboxWrapper.contentEditable = 'false';
    checkboxWrapper.classList.add('task-checkbox');

    const checkbox = document.createElement('span');
    checkbox.classList.add('task-checkbox-display');
    checkbox.setAttribute('role', 'checkbox');
    checkbox.setAttribute('aria-checked', String(node.attrs.checked));
    checkbox.tabIndex = -1;

    checkboxWrapper.addEventListener('mousedown', (e) => {
        e.preventDefault(); // keep editor focus
        if (!view.editable) return;
        const pos = getPos();
        if (pos === undefined) return;
        const newChecked = !node.attrs.checked;
        const attrs: Record<string, unknown> = {
            ...node.attrs,
            checked: newChecked,
        };
        // Auto-pause timer when checking off a task
        if (newChecked && node.attrs.timerRunning) {
            attrs.timerSeconds = getElapsed();
            attrs.timerRunning = false;
            attrs.timerStartedAt = null;
        }
        view.dispatch(
            view.state.tr.setNodeMarkup(pos, null, attrs),
        );
    });

    checkboxWrapper.appendChild(checkbox);
    dom.appendChild(checkboxWrapper);

    // ── Content ────────────────────────────────────────────────────
    const contentDOM = document.createElement('div');
    contentDOM.classList.add('task-list-item-content');
    dom.appendChild(contentDOM);

    // ── Initial render ─────────────────────────────────────────────
    renderTimer();
    if (node.attrs.timerRunning) {
        startTick();
    }

    return {
        dom,
        contentDOM,
        stopEvent(event: Event) {
            const target = event.target as HTMLElement;
            return timerEl.contains(target) || checkboxWrapper.contains(target);
        },
        update(updatedNode: Node) {
            if (updatedNode.type !== view.state.schema.nodes.task_list_item) {
                return false;
            }
            node = updatedNode;
            dom.dataset.checked = String(updatedNode.attrs.checked);
            checkbox.setAttribute(
                'aria-checked',
                String(updatedNode.attrs.checked),
            );

            renderTimer();
            if (updatedNode.attrs.timerRunning) {
                startTick();
            } else {
                stopTick();
            }

            return true;
        },
        destroy() {
            stopTick();
            if (clickTimeout) {
                clearTimeout(clickTimeout);
            }
        },
    };
}
