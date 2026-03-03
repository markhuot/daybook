import { Schema } from 'prosemirror-model';

/**
 * Daybook editor schema.
 *
 * Supports: paragraphs, headings, bold, italic, ordered lists, bullet lists,
 * and task lists (with checkboxes).
 */
export const schema = new Schema({
    nodes: {
        doc: {
            content: 'block+',
        },
        paragraph: {
            content: 'inline*',
            group: 'block',
            parseDOM: [{ tag: 'p' }],
            toDOM() {
                return ['p', 0];
            },
        },
        heading: {
            content: 'inline*',
            group: 'block',
            defining: true,
            parseDOM: [{ tag: 'h1' }],
            toDOM() {
                return ['h1', 0];
            },
        },
        text: {
            group: 'inline',
        },
        bullet_list: {
            content: 'list_item+',
            group: 'block',
            parseDOM: [{ tag: 'ul' }],
            toDOM() {
                return ['ul', 0];
            },
        },
        ordered_list: {
            content: 'list_item+',
            group: 'block',
            attrs: { order: { default: 1 } },
            parseDOM: [
                {
                    tag: 'ol',
                    getAttrs(dom) {
                        return {
                            order: (dom as HTMLElement).hasAttribute('start')
                                ? +(dom as HTMLElement).getAttribute('start')!
                                : 1,
                        };
                    },
                },
            ],
            toDOM(node) {
                return node.attrs.order === 1
                    ? ['ol', 0]
                    : ['ol', { start: node.attrs.order }, 0];
            },
        },
        task_list: {
            content: 'task_list_item+',
            group: 'block',
            parseDOM: [{ tag: 'ul[data-type="taskList"]' }],
            toDOM() {
                return ['ul', { 'data-type': 'taskList' }, 0];
            },
        },
        list_item: {
            content: 'paragraph block*',
            parseDOM: [{ tag: 'li' }],
            defining: true,
            toDOM() {
                return ['li', 0];
            },
        },
        task_list_item: {
            content: 'paragraph block*',
            attrs: { checked: { default: false } },
            defining: true,
            parseDOM: [
                {
                    tag: 'li[data-type="taskItem"]',
                    getAttrs(dom) {
                        return {
                            checked:
                                (dom as HTMLElement).getAttribute(
                                    'data-checked',
                                ) === 'true',
                        };
                    },
                },
            ],
            toDOM(node) {
                return [
                    'li',
                    {
                        'data-type': 'taskItem',
                        'data-checked': node.attrs.checked ? 'true' : 'false',
                    },
                    0,
                ];
            },
        },
    },
    marks: {
        bold: {
            parseDOM: [
                { tag: 'strong' },
                { tag: 'b', getAttrs: (node) => (node as HTMLElement).style.fontWeight !== 'normal' && null },
                {
                    style: 'font-weight=400',
                    clearMark: (m) => m.type.name === 'bold',
                },
                { style: 'font-weight', getAttrs: (value) => /^(bold(er)?|[5-9]\d{2,})$/.test(value as string) && null },
            ],
            toDOM() {
                return ['strong', 0];
            },
        },
        italic: {
            parseDOM: [
                { tag: 'i' },
                { tag: 'em' },
                { style: 'font-style=italic' },
            ],
            toDOM() {
                return ['em', 0];
            },
        },
    },
});
