import { useEffect, useRef, useState, useCallback, useMemo } from 'react';
import { EditorState, TextSelection } from 'prosemirror-state';
import { EditorView } from 'prosemirror-view';
import { DOMSerializer, Node } from 'prosemirror-model';
import { schema } from './editor/schema';
import { buildPlugins } from './editor/plugins';
import { taskListItemView } from './editor/task-list-item-view';
import { linkExpandKey } from './editor/link-plugin';

interface EditorProps {
    content?: Record<string, unknown> | null;
    previousContent?: Record<string, unknown> | null;
    onUpdate?: (content: Record<string, unknown>) => void;
    editable?: boolean;
}

function renderContentToHTML(content: Record<string, unknown>): string {
    try {
        const doc = Node.fromJSON(schema, content);
        const serializer = DOMSerializer.fromSchema(schema);
        const fragment = serializer.serializeFragment(doc.content);
        const container = document.createElement('div');
        container.appendChild(fragment);
        return container.innerHTML;
    } catch {
        return '';
    }
}

function hasTextContent(content: Record<string, unknown>): boolean {
    try {
        const doc = Node.fromJSON(schema, content);
        return doc.textContent.trim().length > 0;
    } catch {
        return false;
    }
}

export default function Editor({ content, previousContent, onUpdate, editable = true }: EditorProps) {
    const editorRef = useRef<HTMLDivElement>(null);
    const viewRef = useRef<EditorView | null>(null);
    const onUpdateRef = useRef(onUpdate);
    onUpdateRef.current = onUpdate;

    const editableRef = useRef(editable);
    editableRef.current = editable;

    const initialContentRef = useRef(content);

    const previousHasText = useMemo(() => {
        return !!previousContent && hasTextContent(previousContent);
    }, [previousContent]);

    const [showPlaceholder, setShowPlaceholder] = useState(
        !content && previousHasText,
    );
    const showPlaceholderRef = useRef(showPlaceholder);
    showPlaceholderRef.current = showPlaceholder;

    const placeholderHTML = useMemo(() => {
        if (!previousContent || !previousHasText) return '';
        return renderContentToHTML(previousContent);
    }, [previousContent, previousHasText]);

    const handleAccept = useCallback(() => {
        if (!viewRef.current || !previousContent) return;
        const newDoc = Node.fromJSON(schema, previousContent);
        const tr = viewRef.current.state.tr.replaceWith(
            0,
            viewRef.current.state.doc.content.size,
            newDoc.content,
        );
        tr.setSelection(TextSelection.create(tr.doc, 1));
        viewRef.current.dispatch(tr);
        viewRef.current.focus();
        // dispatchTransaction handles hiding the placeholder and calling onUpdate
    }, [previousContent]);

    const handleAcceptRef = useRef(handleAccept);
    handleAcceptRef.current = handleAccept;

    useEffect(() => {
        if (!editorRef.current) return;

        const doc = initialContentRef.current
            ? Node.fromJSON(schema, initialContentRef.current)
            : undefined;

        const state = EditorState.create({
            schema,
            doc,
            plugins: buildPlugins(),
        });

        const view = new EditorView(editorRef.current, {
            state,
            editable: () => editableRef.current,
            nodeViews: {
                task_list_item: taskListItemView,
            },
            handleKeyDown: (_view, event) => {
                if (event.key === ' ' && showPlaceholderRef.current) {
                    event.preventDefault();
                    handleAcceptRef.current();
                    return true;
                }
                return false;
            },
            dispatchTransaction(transaction) {
                const newState = view.state.apply(transaction);
                view.updateState(newState);

                if (transaction.docChanged) {
                    if (showPlaceholderRef.current) {
                        setShowPlaceholder(false);
                    }

                    // Suppress saves while a link is being edited in
                    // expanded form — the document is in a temporary
                    // state and will be collapsed back before saving.
                    const linkState = linkExpandKey.getState(newState);
                    if (linkState?.expanded) return;

                    if (onUpdateRef.current) {
                        onUpdateRef.current(newState.doc.toJSON());
                    }
                }
            },
        });
        viewRef.current = view;

        if (editableRef.current) {
            view.focus();
            const tr = view.state.tr.setSelection(
                TextSelection.create(view.state.doc, 1),
            );
            view.dispatch(tr);
        }

        return () => {
            view.destroy();
        };
    }, []);

    useEffect(() => {
        if (viewRef.current) {
            viewRef.current.setProps({ editable: () => editable });
        }
    }, [editable]);

    return (
        <div className="editor-wrapper relative flex flex-1 flex-col">
            {showPlaceholder && placeholderHTML && (
                <>
                    <div className="pointer-events-none absolute inset-0 select-none">
                        <div
                            className="ProseMirror placeholder-ghost"
                            dangerouslySetInnerHTML={{ __html: placeholderHTML }}
                        />
                    </div>
                    <div className="pointer-events-auto absolute inset-x-0 top-4 z-10 flex justify-center">
                        <button
                            data-testid="continue-button"
                            onClick={handleAccept}
                            className="rounded-full border border-purple-200 bg-gradient-to-r from-purple-500/90 via-indigo-500/90 to-blue-500/90 px-5 py-1.5 text-sm font-medium text-white shadow-[0_3px_12px_2px_rgba(168,85,247,0.25)] backdrop-blur-sm transition-all hover:shadow-[0_8px_18px_4px_rgba(168,85,247,0.4)] hover:brightness-110 dark:border-purple-700 dark:shadow-[0_3px_12px_2px_rgba(168,85,247,0.2)] dark:hover:shadow-[0_8px_18px_4px_rgba(168,85,247,0.35)]"
                        >
                            Start with a suggestion <span className="ml-1 opacity-50">Space</span>
                        </button>
                    </div>
                </>
            )}
            <div ref={editorRef} className="editor flex flex-1 flex-col" />
        </div>
    );
}
