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
            attrs: {
                checked: { default: false },
                timerSeconds: { default: 0 },
                timerRunning: { default: false },
                timerStartedAt: { default: null },
            },
            defining: true,
            parseDOM: [
                {
                    tag: 'li[data-type="taskItem"]',
                    getAttrs(dom) {
                        const el = dom as HTMLElement;
                        return {
                            checked: el.getAttribute('data-checked') === 'true',
                            timerSeconds:
                                parseInt(el.getAttribute('data-timer-seconds') || '0', 10) || 0,
                        };
                    },
                },
            ],
            toDOM(node) {
                const attrs: Record<string, string> = {
                    'data-type': 'taskItem',
                    'data-checked': node.attrs.checked ? 'true' : 'false',
                };
                if (node.attrs.timerSeconds) {
                    attrs['data-timer-seconds'] = String(node.attrs.timerSeconds);
                }
                return ['li', attrs, 0];
            },
        },
    },
    marks: {
        link: {
            attrs: {
                href: {},
                auto: { default: false },
            },
            inclusive: false,
            parseDOM: [
                {
                    tag: 'a[href]',
                    getAttrs(dom) {
                        const href = (dom as HTMLElement).getAttribute('href');
                        const text = (dom as HTMLElement).textContent;
                        return { href, auto: text === href };
                    },
                },
            ],
            toDOM(mark) {
                return ['a', { href: mark.attrs.href }, 0];
            },
        },
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
