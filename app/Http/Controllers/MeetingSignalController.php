<?php

namespace App\Http\Controllers;

use App\Models\ChatMeeting;
use App\Models\ChatMeetingParticipant;
use App\Services\MeetingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

class MeetingSignalController extends Controller
{
    /**
     * Maximum serialized WebRTC signal payload accepted per request.
     * SDP offers/answers are a few KB; this ceiling only rejects abuse.
     */
    protected const MAX_SIGNAL_PAYLOAD_BYTES = 60000;

    public function signal(Request $request, MeetingService $meetingService, ?string $uuid = null): JsonResponse
    {
        $user = $request->user();
        if (! $user) {
            return response()->json(['error' => 'Unauthenticated'], 401);
        }

        // Support both direct route (/meetings/{uuid}/signal) and team-scoped route (/{current_team}/meetings/{uuid}/signal)
        $targetUuid = (string) ($request->route('uuid') ?: $uuid);

        try {
            $meeting = ChatMeeting::where('uuid', $targetUuid)->orWhere('invite_code', $targetUuid)->first();
            if (! $meeting) {
                return response()->json(['error' => 'Meeting not found'], 404);
            }

            if ($meeting->isEnded() || $meeting->isCancelled()) {
                return response()->json(['error' => 'Meeting is not active'], 410);
            }

            if (! $this->canAccessMeeting($meeting, $user)) {
                return response()->json(['error' => 'Unauthorized'], 403);
            }

            $validated = $request->validate([
                'signalType' => ['nullable', 'string', 'max:64'],
                'signal_type' => ['nullable', 'string', 'max:64'],
                'payload' => ['nullable', 'array'],
                'targetUserId' => ['nullable', 'integer'],
                'target_user_id' => ['nullable', 'integer'],
            ]);

            $signalType = (string) ($validated['signalType'] ?? $validated['signal_type'] ?? 'signal');

            if (strlen((string) json_encode($validated['payload'] ?? [])) > self::MAX_SIGNAL_PAYLOAD_BYTES) {
                throw ValidationException::withMessages([
                    'payload' => __('WebRTC signal payload is too large.'),
                ]);
            }

            $payload = (array) ($validated['payload'] ?? []);
            $targetUserId = isset($validated['targetUserId'])
                ? (int) $validated['targetUserId']
                : (isset($validated['target_user_id']) ? (int) $validated['target_user_id'] : null);

            $meetingService->sendSignal(
                meetingUuid: $targetUuid,
                sender: $user,
                signalType: $signalType,
                payload: $payload,
                targetUserId: $targetUserId
            );

            return response()->json(['ok' => true]);
        } catch (ValidationException $e) {
            return response()->json(['error' => $e->validator->errors()->first()], 422);
        } catch (Throwable $e) {
            Log::error('Meeting signal error: '.$e->getMessage(), ['uuid' => $targetUuid, 'user_id' => $user->id]);

            return response()->json(['error' => 'Unable to deliver signal at this time.'], 500);
        }
    }

    public function sync(Request $request, MeetingService $meetingService, ?string $uuid = null): JsonResponse
    {
        $user = $request->user();
        if (! $user) {
            return response()->json(['error' => 'Unauthenticated'], 401);
        }

        // Support both direct route (/meetings/{uuid}/sync) and team-scoped route (/{current_team}/meetings/{uuid}/sync)
        $targetUuid = (string) ($request->route('uuid') ?: $uuid);

        try {
            $meeting = ChatMeeting::where('uuid', $targetUuid)->orWhere('invite_code', $targetUuid)->first();
            if (! $meeting) {
                return response()->json(['error' => 'Meeting not found'], 404);
            }

            if (! $this->canAccessMeeting($meeting, $user)) {
                return response()->json(['error' => 'Unauthorized'], 403);
            }

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
        } catch (Throwable $e) {
            Log::error('Meeting sync error: '.$e->getMessage(), ['uuid' => $targetUuid, 'user_id' => $user->id]);

            return response()->json(['error' => 'Unable to synchronize at this time.'], 500);
        }
    }

    /**
     * Shared access check: host, co-host, participant or open-access meeting.
     */
    protected function canAccessMeeting(ChatMeeting $meeting, $user): bool
    {
        if ((int) $meeting->host_id === (int) $user->id) {
            return true;
        }

        if ($user->hasRole('Super Administrator')) {
            return true;
        }

        $isParticipant = ChatMeetingParticipant::where('meeting_id', $meeting->id)
            ->where('user_id', $user->id)
            ->whereNull('deleted_at')
            ->exists();

        return $isParticipant || $meeting->isOpenForEveryone();
    }
}
