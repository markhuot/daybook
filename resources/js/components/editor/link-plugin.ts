import {
    Plugin,
    PluginKey,
    EditorState,
    TextSelection,
    Transaction,
} from 'prosemirror-state';
import { Mark, Node } from 'prosemirror-model';
import { Decoration, DecorationSet } from 'prosemirror-view';
import { InputRule } from 'prosemirror-inputrules';
import { schema } from './schema';

// ---------------------------------------------------------------------------
// Types & key
// ---------------------------------------------------------------------------

interface LinkExpandState {
    /** The document range of the currently-expanded markdown link, or null. */
    expanded: { from: number; to: number } | null;
}

export const linkExpandKey = new PluginKey<LinkExpandState>('linkExpand');

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

/**
 * Find the contiguous range of a particular link mark around `pos`.
 * Consecutive text nodes sharing the same link mark (same href) are
 * treated as a single range.
 */
function findLinkRange(
    doc: Node,
    pos: number,
    linkMark: Mark,
): { from: number; to: number; mark: Mark } | null {
    const $pos = doc.resolve(pos);
    const parent = $pos.parent;
    const start = $pos.start();

    let rangeFrom = -1;
    let rangeTo = -1;
    let offset = 0;

    for (let i = 0; i < parent.childCount; i++) {
        const child = parent.child(i);
        const childFrom = start + offset;
        const childTo = childFrom + child.nodeSize;

        const hasMark = child.marks.some(
            (m) =>
                m.type === linkMark.type &&
                m.attrs.href === linkMark.attrs.href,
        );

        if (hasMark) {
            if (rangeFrom === -1) rangeFrom = childFrom;
            rangeTo = childTo;
        } else {
            if (rangeFrom !== -1 && rangeFrom <= pos && pos <= rangeTo) {
                return { from: rangeFrom, to: rangeTo, mark: linkMark };
            }
            rangeFrom = -1;
            rangeTo = -1;
        }

        offset += child.nodeSize;
    }

    if (rangeFrom !== -1 && rangeFrom <= pos && pos <= rangeTo) {
        return { from: rangeFrom, to: rangeTo, mark: linkMark };
    }

    return null;
}

/**
 * Return the link mark + range at the current cursor position, or null.
 */
function getCursorLink(
    state: EditorState,
): { from: number; to: number; mark: Mark } | null {
    const sel = state.selection;
    if (!(sel instanceof TextSelection) || !sel.$cursor) return null;

    const linkMark = sel.$cursor
        .marks()
        .find((m) => m.type === schema.marks.link);
    if (!linkMark) return null;

    return findLinkRange(state.doc, sel.$cursor.pos, linkMark);
}

// ---------------------------------------------------------------------------
// Expand / collapse helpers
// ---------------------------------------------------------------------------

/**
 * Expand a markdown-style link: replace the marked display text with the
 * raw markdown `[text](href)` (plain, no link mark).
 */
function expandLink(
    state: EditorState,
    range: { from: number; to: number; mark: Mark },
    cursorPos: number,
): Transaction {
    const text = state.doc.textBetween(range.from, range.to);
    const href = range.mark.attrs.href;
    const expandedText = `[${text}](${href})`;

    const tr = state.tr;

    // Replace marked text with plain expanded markdown
    tr.replaceWith(range.from, range.to, schema.text(expandedText));

    // Position the cursor: the display-text portion starts after '['
    const offsetInText = cursorPos - range.from;
    const newCursorPos = range.from + 1 + offsetInText;
    tr.setSelection(TextSelection.create(tr.doc, newCursorPos));

    tr.setMeta(linkExpandKey, {
        expanded: { from: range.from, to: range.from + expandedText.length },
    });
    tr.setMeta('addToHistory', false);

    return tr;
}

/**
 * Collapse previously-expanded markdown text back into a link mark.
 *
 * If the text still matches `[text](url)`, a link is restored.
 * If the text is a bare URL, a bare-URL link is created.
 * Otherwise the text is left as-is (the user broke the syntax).
 */
function collapseLink(
    state: EditorState,
    expanded: { from: number; to: number },
): Transaction {
    const text = state.doc.textBetween(expanded.from, expanded.to);
    const tr = state.tr;

    const mdMatch = text.match(/^\[([^\]]*)\]\(([^)]*)\)$/);
    if (mdMatch && mdMatch[1] && mdMatch[2]) {
        const linkText = mdMatch[1];
        const href = mdMatch[2];
        const mark = schema.marks.link.create({ href });
        tr.replaceWith(
            expanded.from,
            expanded.to,
            schema.text(linkText, [mark]),
        );
    } else if (/^https?:\/\/\S+$/.test(text)) {
        const mark = schema.marks.link.create({ href: text, auto: true });
        tr.addMark(expanded.from, expanded.to, mark);
    }
    // else: leave as plain text — user deleted the link structure

    tr.setMeta(linkExpandKey, { expanded: null });
    tr.setMeta('addToHistory', false);

    return tr;
}

// ---------------------------------------------------------------------------
// Plugin
// ---------------------------------------------------------------------------

/**
 * ProseMirror plugin that:
 *
 * 1. Expands markdown-style links (`[text](url)`) when the cursor enters
 *    the display text, revealing the full markdown syntax for editing.
 * 2. Collapses them back to a styled link when the cursor leaves.
 * 3. Keeps bare-URL links (`auto: true`) in sync: the `href` attribute
 *    tracks the text content while the user edits.
 * 4. Adds decorations to dim the markdown syntax (`[`, `](`, `)`) and
 *    style the URL portion while a link is expanded.
 */
export function linkExpandPlugin(): Plugin {
    return new Plugin<LinkExpandState>({
        key: linkExpandKey,

        state: {
            init() {
                return { expanded: null };
            },
            apply(tr, value) {
                // Explicit state from our own transactions takes priority.
                const meta = tr.getMeta(linkExpandKey);
                if (meta !== undefined) return meta;

                if (!value.expanded) return value;

                // Map the expanded range through any document changes.
                const from = tr.mapping.map(value.expanded.from, 1);
                const to = tr.mapping.map(value.expanded.to, -1);
                if (from >= to) return { expanded: null };

                return { expanded: { from, to } };
            },
        },

        appendTransaction(transactions, _oldState, newState) {
            // Never re-process our own transactions.
            for (const tr of transactions) {
                if (tr.getMeta(linkExpandKey) !== undefined) return null;
            }

            const pluginState = linkExpandKey.getState(newState);
            const sel = newState.selection;

            // ----------------------------------------------------------
            // 1. A link is currently expanded — check if cursor left it.
            // ----------------------------------------------------------
            if (pluginState?.expanded) {
                const { from, to } = pluginState.expanded;

                if (sel instanceof TextSelection && sel.$cursor) {
                    const cursorPos = sel.$cursor.pos;
                    if (cursorPos >= from && cursorPos <= to) {
                        // Still inside — nothing to do.
                        return null;
                    }
                }

                // Cursor left (or selection is a range) — collapse.
                return collapseLink(newState, pluginState.expanded);
            }

            // ----------------------------------------------------------
            // 2. No expanded link — see if the cursor entered one.
            // ----------------------------------------------------------
            if (!(sel instanceof TextSelection) || !sel.$cursor) return null;

            const linkRange = getCursorLink(newState);
            if (!linkRange) return null;

            const text = newState.doc.textBetween(linkRange.from, linkRange.to);
            const href = linkRange.mark.attrs.href;
            const isAuto = linkRange.mark.attrs.auto;

            // Bare-URL link — keep href in sync when the user edits.
            if (isAuto) {
                if (
                    text !== href &&
                    transactions.some((tr) => tr.docChanged)
                ) {
                    const tr = newState.tr;
                    if (/^https?:\/\/\S*$/.test(text)) {
                        tr.addMark(
                            linkRange.from,
                            linkRange.to,
                            schema.marks.link.create({
                                href: text,
                                auto: true,
                            }),
                        );
                    } else {
                        // No longer looks like a URL — remove the link.
                        tr.removeMark(
                            linkRange.from,
                            linkRange.to,
                            schema.marks.link,
                        );
                    }
                    tr.setMeta(linkExpandKey, { expanded: null });
                    tr.setMeta('addToHistory', false);
                    return tr;
                }
                return null;
            }

            // Markdown-style link — expand so the user can edit text & url.
            if (text !== href) {
                return expandLink(newState, linkRange, sel.$cursor.pos);
            }

            return null;
        },

        props: {
            /**
             * While a link is expanded, add decorations that dim the
             * markdown syntax characters and style the URL portion.
             */
            decorations(state) {
                const pluginState = linkExpandKey.getState(state);
                if (!pluginState?.expanded) return DecorationSet.empty;

                const { from, to } = pluginState.expanded;
                const text = state.doc.textBetween(from, to);
                const decorations: Decoration[] = [];

                // Opening '['
                decorations.push(
                    Decoration.inline(from, from + 1, {
                        class: 'link-syntax',
                    }),
                );

                // Find the '](' separator
                const closeBracket = text.indexOf('](');
                if (closeBracket !== -1) {
                    // ']('
                    decorations.push(
                        Decoration.inline(
                            from + closeBracket,
                            from + closeBracket + 2,
                            { class: 'link-syntax' },
                        ),
                    );

                    // URL between '(' and ')'
                    decorations.push(
                        Decoration.inline(from + closeBracket + 2, to - 1, {
                            class: 'link-url',
                        }),
                    );

                    // Closing ')'
                    if (text.endsWith(')')) {
                        decorations.push(
                            Decoration.inline(to - 1, to, {
                                class: 'link-syntax',
                            }),
                        );
                    }
                }

                return DecorationSet.create(state.doc, decorations);
            },
        },
    });
}

// ---------------------------------------------------------------------------
// Input rules
// ---------------------------------------------------------------------------

/**
 * Bare URL input rule.
 *
 * Typing `https://example.com ` (URL followed by a space) wraps the URL
 * text in a link mark with `auto: true`.
 */
export function urlInputRule(): InputRule {
    const regex = /(^|\s)(https?:\/\/\S+)\s$/;
    return new InputRule(regex, (state, match, start, _end) => {
        const urlStart = start + match[1].length;
        const urlEnd = urlStart + match[2].length;
        const mark = schema.marks.link.create({
            href: match[2],
            auto: true,
        });

        // Insert the actual whitespace character the user typed (may be
        // a non-breaking space \u00A0 on macOS) instead of a hardcoded ' '.
        const typedWhitespace = match[0].slice(-1);

        const tr = state.tr;
        tr.addMark(urlStart, urlEnd, mark);
        tr.insertText(typedWhitespace, urlEnd);
        tr.removeStoredMark(schema.marks.link);
        return tr;
    });
}

/**
 * Markdown link input rule.
 *
 * Typing `[link text](https://example.com)` replaces the markdown syntax
 * with just the display text wrapped in a link mark.
 */
export function markdownLinkInputRule(): InputRule {
    return new InputRule(
        /\[([^\]]+)\]\(([^)]+)\)$/,
        (state, match, start, end) => {
            const linkText = match[1];
            const href = match[2];
            const mark = schema.marks.link.create({ href });

            const tr = state.tr;
            tr.replaceWith(start, end, schema.text(linkText, [mark]));
            return tr;
        },
    );
}
