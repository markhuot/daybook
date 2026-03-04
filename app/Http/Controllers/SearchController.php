<?php

namespace App\Http\Controllers;

use App\Jobs\ExtractsNoteText;
use App\Models\NoteEmbedding;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Ai\Embeddings;

class SearchController extends Controller
{
    use ExtractsNoteText;

    public function __invoke(Request $request): JsonResponse
    {
        $query = trim($request->input('q', ''));

        if ($query === '') {
            return response()->json([]);
        }

        $user = $request->user();

        // Get all embeddings for this user's notes
        $noteEmbeddings = NoteEmbedding::whereIn(
            'note_id',
            $user->notes()->select('id')
        )->with('note')->get();

        if ($noteEmbeddings->isEmpty()) {
            return response()->json([]);
        }

        // Embed the search query
        $response = Embeddings::for([$query])
            ->dimensions(config('embeddings.dimensions', 1536))
            ->generate(config('embeddings.provider', 'openai'), config('embeddings.model'));

        $queryEmbedding = $response->first();

        // Score each note by cosine similarity
        $results = $noteEmbeddings
            ->map(function (NoteEmbedding $ne) use ($queryEmbedding) {
                $score = $this->cosineSimilarity($queryEmbedding, $ne->embedding);
                $note = $ne->note;
                $text = $note->content ? $this->extractText($note->content) : '';

                return [
                    'date' => $note->date,
                    'snippet' => mb_substr($text, 0, 120),
                    'score' => $score,
                ];
            })
            ->filter(fn ($r) => $r['score'] > 0.3)
            ->sortByDesc('score')
            ->take(8)
            ->values();

        return response()->json($results);
    }

    private function cosineSimilarity(array $a, array $b): float
    {
        $dot = 0.0;
        $normA = 0.0;
        $normB = 0.0;

        for ($i = 0, $len = count($a); $i < $len; $i++) {
            $dot += $a[$i] * $b[$i];
            $normA += $a[$i] * $a[$i];
            $normB += $b[$i] * $b[$i];
        }

        $denom = sqrt($normA) * sqrt($normB);

        return $denom > 0 ? $dot / $denom : 0.0;
    }
}
