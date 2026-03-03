# Daybook - Agent Guidelines

## Project Overview

Daybook is a daily journal application where users get one note per day. Past entries are immutable — they can be read but never edited. Only today's entry is writable.

This is a core design constraint, not a bug. Do not add features that allow editing past entries.

## Architecture

- **Backend:** Laravel 12 (PHP) — routes, controllers, models, and API logic
- **Frontend:** React with TypeScript via Inertia.js — no separate API; Inertia bridges Laravel and React
- **Editor:** ProseMirror — rich text editor with markdown-style input rules
- **Styling:** Tailwind CSS 4
- **Testing:** Pest (PHP test framework built on PHPUnit)
- **Build:** Vite with the Laravel Vite plugin
- **Package Manager:** bun (use `bun install`, `bun add`, `bun run build` — not npm)

### Key Directories

- `app/` — Laravel application code (models, controllers, middleware, etc.)
- `resources/js/` — React/TypeScript frontend code
- `resources/js/pages/` — Inertia page components (maps to route responses)
- `resources/js/components/` — Shared React components
- `resources/js/components/editor/` — ProseMirror schema, plugins, and configuration
- `resources/css/` — Stylesheets (Tailwind + ProseMirror editor styles)
- `resources/views/` — Blade templates (only `app.blade.php` as the Inertia root)
- `routes/web.php` — Web routes
- `database/migrations/` — Database schema migrations
- `tests/` — Pest test files

### How Inertia Works Here

Routes return `Inertia::render('PageName', $props)` instead of Blade views. The page name maps to a file in `resources/js/pages/`. Props are passed directly to the React component. There is no REST API — form submissions use Inertia's `router.post()`/`router.put()` methods.

### Editor Architecture

The text editor uses ProseMirror directly (no wrapper library like Tiptap). It is structured as:

- `resources/js/components/editor/schema.ts` — ProseMirror document schema defining the node and mark types (paragraph, bullet_list, ordered_list, list_item, bold, italic)
- `resources/js/components/editor/plugins.ts` — Keyboard shortcuts, input rules, and history. Exports `buildPlugins()` which returns the full plugin array.
- `resources/js/components/Editor.tsx` — React component that mounts ProseMirror into a DOM ref. This is the public-facing component used by pages.

Markdown-style input rules are supported:
- `**text**` or `__text__` → bold
- `*text*` or `_text_` → italic
- `- ` or `* ` at start of line → bullet list
- `1. ` at start of line → ordered list

Keyboard shortcuts: `Cmd+B` (bold), `Cmd+I` (italic), `Tab`/`Shift+Tab` (list nesting), `Cmd+Z`/`Cmd+Shift+Z` (undo/redo).

Editor styles live in `resources/css/app.css` targeting `.ProseMirror` classes.

## Commands

- `composer run dev` — Start all dev servers
- `bun run dev` — Vite dev server only
- `bun run build` — Production build
- `./vendor/bin/pest` — Run all tests
- `./vendor/bin/pest --filter="test name"` — Run a specific test
- `php artisan migrate` — Run database migrations
- `php artisan make:model ModelName -m` — Create model with migration
- `php artisan make:controller ControllerName` — Create controller

## Code Conventions

- PHP follows PSR-12 via Laravel Pint (`./vendor/bin/pint`)
- React components use TypeScript (`.tsx` files)
- Page components are in `resources/js/pages/` and are default exports
- Shared components are in `resources/js/components/`
- Use `@/*` path alias for imports (maps to `resources/js/*`)
- Use Pest's functional syntax for tests (`it()`, `test()`, `expect()`)
- Database uses SQLite by default for development
- Use bun for all JS package management and scripts, not npm

## Core Domain Rules

1. Each user gets exactly one note per calendar day
2. Only today's note is editable
3. Past notes are read-only — this is enforced at the application level, not just the UI
4. Notes are displayed in reverse chronological order (most recent first)
