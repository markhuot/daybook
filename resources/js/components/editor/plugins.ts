import { keymap } from 'prosemirror-keymap';
import { baseKeymap, toggleMark, chainCommands, setBlockType } from 'prosemirror-commands';
import { Plugin } from 'prosemirror-state';
import { TextSelection } from 'prosemirror-state';
import {
    splitListItem,
    liftListItem,
    sinkListItem,
} from 'prosemirror-schema-list';
import { history, undo, redo } from 'prosemirror-history';
import {
    inputRules,
    wrappingInputRule,
    textblockTypeInputRule,
    InputRule,
} from 'prosemirror-inputrules';
import { Fragment, MarkType, Node, NodeType } from 'prosemirror-model';
import { schema } from './schema';

/**
 * Markdown-style input rules:
 * - `**text**` or `__text__` for bold
 * - `*text*` or `_text_` for italic
 * - `- ` or `* ` at start of line for bullet list
 * - `1. ` (or any number) at start of line for ordered list
 * - `[] `, `[ ] `, or `[x] ` inside a list item for task list
 */
function markInputRule(pattern: RegExp, markType: MarkType): InputRule {
    return new InputRule(pattern, (state, match, start, end) => {
        const tr = state.tr;
        if (match[1]) {
            const textStart = start + match[0].indexOf(match[1]);
            const textEnd = textStart + match[1].length;
            if (textEnd < end) tr.delete(textEnd, end);
            if (textStart > start) tr.delete(start, textStart);
            end = start + match[1].length;
        }
        tr.addMark(start, end, markType.create());
        tr.removeStoredMark(markType);
        return tr;
    });
}

function wrappingListInputRule(pattern: RegExp, nodeType: NodeType, getAttrs?: (match: RegExpMatchArray) => Record<string, unknown> | null) {
    return wrappingInputRule(pattern, nodeType, getAttrs);
}

/**
 * Input rule that converts a list item into a task list item when
 * the user types `[]`, `[ ]`, or `[x]` followed by a space at the
 * start of a list item.
 *
 * This replaces the entire parent list (bullet or ordered) with a
 * task_list, converting all children to task_list_items.
 */
function taskListInputRule(): InputRule {
    return new InputRule(/^\[([ x])?\]\s$/, (state, match, start, _end) => {
        const checked = match[1] === 'x';
        const $pos = state.doc.resolve(start);

        // Walk up to find a list_item ancestor
        let listItemDepth = -1;
        for (let d = $pos.depth; d >= 0; d--) {
            if ($pos.node(d).type === schema.nodes.list_item) {
                listItemDepth = d;
                break;
            }
        }
        if (listItemDepth === -1) return null;

        // Parent of list_item must be a bullet_list or ordered_list
        const listDepth = listItemDepth - 1;
        if (listDepth < 0) return null;
        const listNode = $pos.node(listDepth);
        if (
            listNode.type !== schema.nodes.bullet_list &&
            listNode.type !== schema.nodes.ordered_list
        ) {
            return null;
        }

        const currentIndex = $pos.index(listDepth);
        const matchLength = match[0].length;

        // Build replacement task_list with task_list_items
        const items: Node[] = [];
        listNode.forEach((child, _offset, index) => {
            let content = child.content;

            if (index === currentIndex) {
                // Strip the matched text from the first paragraph
                const firstPara = content.firstChild;
                if (firstPara) {
                    const strippedPara = firstPara.cut(matchLength);
                    const remaining: Node[] = [strippedPara];
                    for (let i = 1; i < content.childCount; i++) {
                        remaining.push(content.child(i));
                    }
                    content = Fragment.from(remaining);
                }
            }

            items.push(
                schema.nodes.task_list_item.create(
                    { checked: index === currentIndex ? checked : false },
                    content,
                ),
            );
        });

        const newTaskList = schema.nodes.task_list.create(null, items);
        const listStart = $pos.before(listDepth);
        const listEnd = $pos.after(listDepth);

        const tr = state.tr.replaceWith(listStart, listEnd, newTaskList);

        // Place cursor at the start of the current item's paragraph
        let cursorPos = listStart + 1; // inside task_list
        for (let i = 0; i < currentIndex; i++) {
            cursorPos += items[i].nodeSize;
        }
        cursorPos += 1; // inside task_list_item
        cursorPos += 1; // inside paragraph

        tr.setSelection(TextSelection.create(tr.doc, cursorPos));

        return tr;
    });
}

function buildInputRules(): Plugin {
    return inputRules({
        rules: [
            // **bold** and __bold__
            markInputRule(/\*\*([^*]+)\*\*$/, schema.marks.bold),
            markInputRule(/__([^_]+)__$/, schema.marks.bold),

            // *italic* and _italic_
            markInputRule(/(?<![*_])\*([^*]+)\*$/, schema.marks.italic),
            markInputRule(/(?<![*_])_([^_]+)_$/, schema.marks.italic),

            // # at start of line -> heading
            textblockTypeInputRule(/^#\s$/, schema.nodes.heading),

            // - or * at start of line -> bullet list
            wrappingListInputRule(/^\s*[-*]\s$/, schema.nodes.bullet_list),

            // 1. at start of line -> ordered list
            wrappingListInputRule(
                /^\s*(\d+)\.\s$/,
                schema.nodes.ordered_list,
                (match) => ({ order: +match[1] }),
            ),

            // [] or [ ] or [x] followed by space inside a list item -> task list
            taskListInputRule(),
        ],
    });
}

function buildKeymap(): Plugin {
    const listItem = schema.nodes.list_item;
    const taskListItem = schema.nodes.task_list_item;
    const heading = schema.nodes.heading;
    const paragraph = schema.nodes.paragraph;

    // When pressing Enter inside a heading, exit to a new paragraph
    const exitHeading = (state: import('prosemirror-state').EditorState, dispatch?: (tr: import('prosemirror-state').Transaction) => void) => {
        const { $from, empty } = state.selection;
        if (!empty || $from.parent.type !== heading) return false;

        // If the heading is empty, convert it back to a paragraph
        if ($from.parent.content.size === 0) {
            if (dispatch) {
                dispatch(state.tr.setBlockType($from.before(), $from.after(), paragraph));
            }
            return true;
        }

        // At the end of a heading, create a new paragraph below
        if ($from.parentOffset === $from.parent.content.size) {
            if (dispatch) {
                const tr = state.tr;
                const after = $from.after();
                tr.insert(after, paragraph.create());
                tr.setSelection(TextSelection.create(tr.doc, after + 1));
                dispatch(tr);
            }
            return true;
        }

        return false;
    };

    return keymap({
        'Mod-z': undo,
        'Mod-Shift-z': redo,
        'Mod-y': redo,
        'Mod-b': toggleMark(schema.marks.bold),
        'Mod-i': toggleMark(schema.marks.italic),
        Enter: chainCommands(
            exitHeading,
            splitListItem(taskListItem, { checked: false }),
            splitListItem(listItem),
            baseKeymap.Enter,
        ),
        'Shift-Tab': chainCommands(
            liftListItem(taskListItem),
            liftListItem(listItem),
        ),
        Tab: chainCommands(
            sinkListItem(taskListItem),
            sinkListItem(listItem),
        ),
        'Mod-Shift-l': (state, dispatch) => {
            const { $from } = state.selection;
            for (let d = $from.depth; d >= 0; d--) {
                if ($from.node(d).type === taskListItem) {
                    if (dispatch) {
                        const pos = $from.before(d);
                        const node = $from.node(d);
                        dispatch(
                            state.tr.setNodeMarkup(pos, null, {
                                ...node.attrs,
                                checked: !node.attrs.checked,
                            }),
                        );
                    }
                    return true;
                }
            }
            return false;
        },
        Backspace: chainCommands(
            (state, dispatch) => {
                const { $from, empty } = state.selection;
                if (!empty || $from.parent.type !== heading || $from.parentOffset !== 0) return false;
                if (dispatch) {
                    dispatch(state.tr.setBlockType($from.before(), $from.after(), paragraph));
                }
                return true;
            },
            baseKeymap.Backspace,
        ),
    });
}

export function buildPlugins(): Plugin[] {
    return [
        buildInputRules(),
        history(),
        buildKeymap(),
        keymap(baseKeymap),
    ];
}
