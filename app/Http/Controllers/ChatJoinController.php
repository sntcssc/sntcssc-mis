<?php

namespace App\Http\Controllers;

use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Models\ChatParticipant;
use App\Services\AuditLogService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ChatJoinController extends Controller
{
    /**
     * Handle joining a group or channel via invite code.
     */
    public function join(Request $request, string $code): RedirectResponse
    {
        $user = $request->user();
        if (! $user) {
            return redirect()->route('login')->with('error', __('Please login to join this conversation.'));
        }

        $conversation = ChatConversation::where('invite_code', $code)->first();

        if (! $conversation) {
            return redirect()->route('admin.chat.index')->with('error', __('Invalid or expired invitation link.'));
        }

        // Check if already participant
        $existing = $conversation->participants()->where('user_id', $user->id)->first();
        $teamSlug = $user->currentTeam?->slug ?? $user->allTeams()->first()?->slug ?? 'default';

        if ($existing && $existing->left_at === null) {
            return redirect()->to(route('admin.chat.index', ['current_team' => $teamSlug]).'?c='.$conversation->uuid);
        }

        DB::transaction(function () use ($conversation, $user, $existing) {
            if ($existing) {
                $existing->update([
                    'left_at' => null,
                    'role' => ChatParticipant::ROLE_MEMBER,
                ]);
            } else {
                ChatParticipant::create([
                    'conversation_id' => $conversation->id,
                    'user_id' => $user->id,
                    'role' => ChatParticipant::ROLE_MEMBER,
                    'joined_at' => now(),
                ]);
            }

            // System notice
            ChatMessage::create([
                'conversation_id' => $conversation->id,
                'user_id' => $user->id,
                'body' => __(':name joined the conversation via invite link.', ['name' => $user->name]),
                'type' => ChatMessage::TYPE_SYSTEM,
                'created_by' => $user->id,
            ]);

            AuditLogService::log(
                event: 'chat_joined_via_invite',
                description: "User {$user->name} joined conversation '{$conversation->title}' via invite code {$conversation->invite_code}",
                auditable: $conversation,
                userId: $user->id
            );
        });

        $teamSlug = $user->currentTeam?->slug ?? $user->allTeams()->first()?->slug ?? 'default';

        return redirect()->to(route('admin.chat.index', ['current_team' => $teamSlug]).'?c='.$conversation->uuid)
            ->with('success', __('You have successfully joined :title!', ['title' => $conversation->title]));
    }
}
