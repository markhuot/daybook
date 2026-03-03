# Daybook

A simple daily journal app. Each day you get a fresh page to write on. Past entries are permanent — you can read them, but you can't change them. Only forward.

The name comes from bookkeeping, where a **daybook** is a chronological journal of daily transactions that, by rule, are never altered after the fact.

## Tech Stack

- **Backend:** Laravel 12
- **Frontend:** React with TypeScript via Inertia.js
- **Editor:** ProseMirror (rich text with markdown-style input)
- **Styling:** Tailwind CSS 4
- **Testing:** Pest
- **Build:** Vite
- **Package Manager:** bun (not npm)

## Getting Started

```bash
# Install dependencies
composer install
bun install

# Set up environment
cp .env.example .env
php artisan key:generate

# Run migrations
php artisan migrate

# Start development servers
composer run dev
```

## Testing

```bash
./vendor/bin/pest
```

## Building for Production

```bash
bun run build
```
