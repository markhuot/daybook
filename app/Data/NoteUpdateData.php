<?php

namespace App\Data;

use Spatie\LaravelData\Data;

class NoteUpdateData extends Data
{
    public function __construct(
        public ?array $content = null,
    ) {}
}
