<?php

namespace App\Http\Controllers;

use App\Jobs\RunPrompt;
use Illuminate\Http\Request;

class PromptController extends Controller
{
    public function __invoke(Request $request)
    {
        $validated = $request->validate([
            'prompt' => ['required', 'string', 'max:5000'],
        ]);

        RunPrompt::dispatch($validated['prompt']);

        return back();
    }
}
