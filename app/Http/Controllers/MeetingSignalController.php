<?php

namespace App\Http\Controllers;

use App\Models\ChatMeeting;
use App\Models\ChatMeetingParticipant;
use App\Services\MeetingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MeetingSignalController extends Controller
{
    public function signal(Request $request, MeetingService $meetingService, ?string $uuid = null): JsonResponse
    {
        $user = $request->user();
        if (! $user) {
            return response()->json(['error' => 'Unauthenticated'], 401);
        }

        // Support both direct route (/meetings/{uuid}/signal) and team-scoped route (/{current_team}/meetings/{uuid}/signal)
        $targetUuid = (string) ($request->route('uuid') ?: $uuid);

        $meeting = ChatMeeting::where('uuid', $targetUuid)->orWhere('invite_code', $targetUuid)->first();
        if (! $meeting) {
            return response()->json(['error' => 'Meeting not found'], 404);
        }

        if ($meeting->isEnded() || $meeting->isCancelled()) {
            return response()->json(['error' => 'Meeting is not active'], 410);
        }

        $isHost = (int) $meeting->host_id === (int) $user->id;
        $isSuperAdmin = $user->hasRole('Super Administrator');
        $isParticipant = ChatMeetingParticipant::where('meeting_id', $meeting->id)
            ->where('user_id', $user->id)
            ->whereNull('deleted_at')
            ->exists();

        if (! $isHost && ! $isSuperAdmin && ! $isParticipant && ! $meeting->isOpenForEveryone()) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $signalType = (string) ($request->input('signalType') ?: $request->input('signal_type') ?: 'signal');
        $payload = (array) ($request->input('payload') ?: []);
        $targetUserId = $request->filled('targetUserId')
            ? (int) $request->input('targetUserId')
            : ($request->filled('target_user_id') ? (int) $request->input('target_user_id') : null);

        $meetingService->sendSignal(
            meetingUuid: $targetUuid,
            sender: $user,
            signalType: $signalType,
            payload: $payload,
            targetUserId: $targetUserId
        );

        return response()->json(['ok' => true]);
    }

    public function sync(Request $request, MeetingService $meetingService, ?string $uuid = null): JsonResponse
    {
        $user = $request->user();
        if (! $user) {
            return response()->json(['error' => 'Unauthenticated'], 401);
        }

        // Support both direct route (/meetings/{uuid}/sync) and team-scoped route (/{current_team}/meetings/{uuid}/sync)
        $targetUuid = (string) ($request->route('uuid') ?: $uuid);

        $sinceTimestamp = (float) $request->query('since', 0);
        $reactions = $meetingService->getRecentReactions($targetUuid, $sinceTimestamp);

        $data = [
            'ok' => true,
            'reactions' => $reactions,
            'timestamp' => microtime(true),
        ];

        // Return cached signals for polling fallback when WebSocket is unavailable.
        if ($request->boolean('include_signals')) {
            $data['signals'] = $meetingService->getRecentSignals($targetUuid, $sinceTimestamp, $user->id);
        }

        return response()->json($data);
    }
}
