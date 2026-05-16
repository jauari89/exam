<?php

namespace App\Events;

use App\Models\ProctorEvent;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ProctorEventRecorded implements ShouldBroadcastNow
{
    use Dispatchable, SerializesModels;

    public function __construct(public ProctorEvent $event) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel('exam-session.'.$this->event->exam_session_id)];
    }

    public function broadcastAs(): string
    {
        return 'proctor.event';
    }

    public function broadcastWith(): array
    {
        return [
            'id' => $this->event->id,
            'exam_session_id' => $this->event->exam_session_id,
            'exam_attempt_id' => $this->event->exam_attempt_id,
            'candidate_id' => $this->event->candidate_id,
            'event_type' => $this->event->event_type,
            'severity' => $this->event->severity,
            'payload' => $this->event->payload,
            'occurred_at' => $this->event->occurred_at?->toIso8601String(),
        ];
    }
}
