<?php

namespace App\Http\Controllers;

use App\Models\ChatMeeting;
use App\Services\AuditLogService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Throwable;

class MeetingJoinController extends Controller
{
    /**
     * Handle joining an online meeting room via invite code.
     */
    public function join(Request $request, string $code): RedirectResponse
    {
        $user = $request->user();
        if (! $user) {
            return redirect()->route('login')->with('error', __('Please login to join this meeting.'));
        }

        try {
            $meeting = ChatMeeting::where('invite_code', trim($code))->first();

            if (! $meeting) {
                return redirect()->route('meetings.index')->with('error', __('Invalid or expired meeting invitation link.'));
            }

            if ($meeting->isEnded() || $meeting->isCancelled()) {
                return redirect()->route('meetings.index')->with('warning', __('This meeting has already concluded or was cancelled.'));
            }

            // Determine appropriate team slug for routing
            $teamSlug = null;
            if ($meeting->team && $user->belongsToTeam($meeting->team)) {
                $teamSlug = $meeting->team->slug;
            }

            if (! $teamSlug) {
                $teamSlug = $user->currentTeam?->slug ?? $user->allTeams()->first()?->slug ?? 'default';
            }

            AuditLogService::log(
                event: 'meeting_join_link_accessed',
                description: "User {$user->name} accessed join link for meeting '{$meeting->title}' (#{$meeting->id})",
                auditable: $meeting,
                userId: $user->id
            );

            return redirect()->route('meetings.room', [
                'current_team' => $teamSlug,
                'uuid' => $meeting->uuid,
            ]);
        } catch (Throwable $e) {
            return redirect()->route('meetings.index')->with('error', __('Unable to access meeting at this time. Please try again.'));
        }
    }
}
