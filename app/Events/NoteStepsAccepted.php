<?php

namespace App\Events;

use App\Models\Note;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class NoteStepsAccepted implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public Note $note,
        public int $version,
        public array $steps,
        public array $clientIDs,
    ) {}

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel("note.{$this->note->id}"),
        ];
    }

    public function broadcastWith(): array
    {
        return [
            'version' => $this->version,
            'steps' => $this->steps,
            'clientIDs' => $this->clientIDs,
        ];
    }

    public function broadcastAs(): string
    {
        return 'steps.accepted';
    }
}
