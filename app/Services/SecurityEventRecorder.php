<?php

namespace App\Services;

use App\Models\SecurityEvent;
use App\Models\User;
use Illuminate\Http\Request;

class SecurityEventRecorder
{
    public function record(?User $user, string $type, ?Request $request = null, array $metadata = []): void
    {
        SecurityEvent::query()->create([
            'user_id' => $user?->id,
            'type' => $type,
            'ip_address' => $request?->ip(),
            'user_agent' => mb_substr((string) $request?->userAgent(), 0, 500) ?: null,
            'metadata' => $metadata === [] ? null : $metadata,
            'created_at' => now(),
        ]);
    }
}
