<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Summary Generation
    |--------------------------------------------------------------------------
    |
    | Configuration for the AI-generated weekly and monthly summaries.
    | Provider and model control which AI service and model are used.
    | Debounce controls how often summary jobs can re-run for a user.
    | TTL controls how long a cached summary is considered fresh.
    |
    */

    'provider' => env('SUMMARY_PROVIDER', 'openai'),

    'model' => env('SUMMARY_MODEL', 'qwen/qwen3.5-9b'),

    'timeout' => (int) env('SUMMARY_TIMEOUT', 600),

    'debounce_seconds' => (int) env('SUMMARY_DEBOUNCE_SECONDS', 3600),

    'ttl_seconds' => (int) env('SUMMARY_TTL_SECONDS', 86400),

];
