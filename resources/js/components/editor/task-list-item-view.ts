import { Node } from 'prosemirror-model';
import { EditorView, NodeView } from 'prosemirror-view';

export function taskListItemView(
    node: Node,
    view: EditorView,
    getPos: () => number | undefined,
): NodeView {
    const dom = document.createElement('li');
    dom.classList.add('task-list-item');
    dom.dataset.checked = String(node.attrs.checked);

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
        view.dispatch(
            view.state.tr.setNodeMarkup(pos, null, {
                ...node.attrs,
                checked: !node.attrs.checked,
            }),
        );
    });

    checkboxWrapper.appendChild(checkbox);
    dom.appendChild(checkboxWrapper);

    const contentDOM = document.createElement('div');
    contentDOM.classList.add('task-list-item-content');
    dom.appendChild(contentDOM);

    return {
        dom,
        contentDOM,
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
            return true;
        },
    };
}
