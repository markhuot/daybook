<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Note Embedding Generation
    |--------------------------------------------------------------------------
    |
    | Configuration for vector embeddings generated from note content.
    | Provider and model control which AI service creates the embeddings.
    | Debounce controls how often the embedding job can re-run per user.
    |
    */

    'provider' => env('EMBEDDING_PROVIDER', 'openai'),

    'model' => env('EMBEDDING_MODEL', 'text-embedding-3-small'),

    'dimensions' => (int) env('EMBEDDING_DIMENSIONS', 1536),

    'debounce_seconds' => (int) env('EMBEDDING_DEBOUNCE_SECONDS', 3600),

];
