export default function FeaturePills() {
    return (
        <ul className="space-y-5 rounded-2xl border border-gray-200 bg-white/60 px-8 py-8 text-[18px] leading-[1.75] text-gray-600 dark:border-gray-700/50 dark:bg-[#181618]/60 dark:text-gray-400">
            <li className="flex items-start gap-4">
                <svg className="mt-1 h-6 w-6 flex-shrink-0 text-highlight" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.5" strokeLinecap="round" strokeLinejoin="round">
                    <rect x="3" y="4" width="18" height="18" rx="2" />
                    <line x1="3" y1="10" x2="21" y2="10" />
                    <line x1="16" y1="2" x2="16" y2="6" />
                    <line x1="8" y1="2" x2="8" y2="6" />
                </svg>
                <span>One note per day. No clutter, no folders — just today.</span>
            </li>
            <li className="flex items-start gap-4">
                <svg className="mt-1 h-6 w-6 flex-shrink-0 text-highlight" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.5" strokeLinecap="round" strokeLinejoin="round">
                    <rect x="3" y="11" width="18" height="11" rx="2" />
                    <path d="M7 11V7a5 5 0 0 1 10 0v4" />
                </svg>
                <span>Yesterday is done. Today is yours — make it count.</span>
            </li>
            <li className="flex items-start gap-4">
                <svg className="mt-1 h-6 w-6 flex-shrink-0 text-highlight" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.5" strokeLinecap="round" strokeLinejoin="round">
                    <path d="M12 20h9" />
                    <path d="M16.5 3.5a2.121 2.121 0 1 1 3 3L7 19l-4 1 1-4Z" />
                </svg>
                <span>Just start writing. Markdown shortcuts and rich text, built in.</span>
            </li>
        </ul>
    );
}
