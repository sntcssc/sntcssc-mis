<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ChatCall;
use App\Models\ChatConversation;
use App\Models\ChatMeeting;
use App\Models\ChatMeetingParticipant;
use App\Models\ChatMessage;
use App\Models\ChatParticipant;
use App\Models\User;
use App\Services\AuditLogService;
use App\Services\ChatService;
use App\Services\MeetingService;
use App\Services\WebRtcCallService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Throwable;

class ChatApiController extends Controller
{
    public function __construct(
        protected ChatService $chatService,
        protected WebRtcCallService $webRtcCallService,
        protected MeetingService $meetingService
    ) {}

    /**
     * Resolve the active user context (via Sanctum/token, session, or API key owner).
     */
    protected function resolveUser(Request $request): ?User
    {
        if ($apiKeyUser = $request->attributes->get('api_key')?->user) {
            return $apiKeyUser;
        }

        return $request->user()
            ?? Auth::guard('sanctum')->user()
            ?? ($request->has('user_id') && ($request->user()?->hasRole('Super Administrator') || $request->attributes->get('api_key')?->hasScope('*'))
                ? User::find($request->input('user_id'))
                : null);
    }

    /**
     * Standard JSON success response envelope.
     */
    protected function success(mixed $data, ?array $meta = null, int $status = 200): JsonResponse
    {
        $payload = [
            'success' => true,
            'data' => $data,
        ];

        if ($meta !== null) {
            $payload['meta'] = $meta;
        }

        return response()->json($payload, $status);
    }

    /**
     * Standard JSON error response envelope.
     */
    protected function error(string $code, string $message, int $status = 400, ?array $details = null): JsonResponse
    {
        $payload = [
            'success' => false,
            'error' => [
                'code' => $code,
                'message' => $message,
            ],
        ];

        if ($details !== null) {
            $payload['error']['details'] = $details;
        }

        return response()->json($payload, $status);
    }

    /**
     * Helper to verify if the user is an active participant or super admin.
     */
    protected function checkConversationAccess(ChatConversation $conversation, User $user): bool
    {
        if ($user->hasRole('Super Administrator')) {
            return true;
        }

        return $conversation->participants()
            ->where('user_id', $user->id)
            ->whereNull('left_at')
            ->exists();
    }

    // =========================================================================
    // 1. DIAGNOSTICS & ICE CONFIGURATION
    // =========================================================================

    /**
     * Realtime WebSockets, Reverb, and WebRTC service status telemetry.
     */
    public function status(Request $request): JsonResponse
    {
        try {
            $user = $this->resolveUser($request);
            $realtimeStatus = $this->webRtcCallService->getRealtimeStatus();

            $activeCallsCount = ChatCall::where('status', 'connected')->count();
            $ongoingMeetingsCount = ChatMeeting::where('status', 'in_progress')->count();

            return $this->success([
                'realtime' => $realtimeStatus,
                'ice_configured' => ! empty($this->webRtcCallService->getIceServers()),
                'telemetry' => [
                    'active_calls' => $activeCallsCount,
                    'ongoing_meetings' => $ongoingMeetingsCount,
                    'server_time' => Carbon::now()->toIso8601String(),
                ],
                'authenticated_user' => $user ? [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                ] : null,
            ]);
        } catch (Throwable $e) {
            Log::error('ChatApi::status error: '.$e->getMessage(), ['trace' => $e->getTraceAsString()]);

            return $this->error('TELEMETRY_ERROR', 'Failed to retrieve realtime status.', 500);
        }
    }

    /**
     * Retrieve configured STUN/TURN ICE servers for WebRTC audio/video sessions.
     */
    public function iceServers(Request $request): JsonResponse
    {
        try {
            $iceServers = $this->webRtcCallService->getIceServers();

            return $this->success([
                'ice_servers' => $iceServers,
                'ice_transport_policy' => 'all',
                'ice_candidate_pool_size' => 10,
            ]);
        } catch (Throwable $e) {
            Log::error('ChatApi::iceServers error: '.$e->getMessage());

            return $this->error('ICE_CONFIG_ERROR', 'Failed to retrieve ICE server configuration.', 500);
        }
    }

    // =========================================================================
    // 2. CONVERSATIONS MANAGEMENT
    // =========================================================================

    /**
     * Browse and paginate conversations for authenticated user.
     */
    public function indexConversations(Request $request): JsonResponse
    {
        $user = $this->resolveUser($request);
        if (! $user) {
            return $this->error('UNAUTHENTICATED', 'Authentication required.', 401);
        }

        try {
            $type = $request->input('type'); // direct, group, channel
            $search = $request->input('search');
            $perPage = max(1, min((int) ($request->input('per_page', 20)), 100));

            $query = ChatConversation::query()
                ->whereHas('participants', fn ($q) => $q->where('user_id', $user->id)->whereNull('left_at'))
                ->with([
                    'participants.user:id,name,email,avatar',
                    'lastMessage.sender:id,name,avatar',
                ])
                ->withCount([
                    'messages as unread_count' => function ($q) use ($user) {
                        $q->where('user_id', '!=', $user->id)
                            ->whereDoesntHave('statuses', fn ($sq) => $sq->where('user_id', $user->id)->where('status', 'read'));
                    },
                ]);

            if ($type) {
                $query->where('type', $type);
            }

            if ($search) {
                $query->where(function ($q) use ($search) {
                    $q->where('title', 'like', "%{$search}%")
                        ->orWhere('description', 'like', "%{$search}%")
                        ->orWhereHas('participants.user', fn ($uq) => $uq->where('name', 'like', "%{$search}%"));
                });
            }

            $conversations = $query->latest('updated_at')->paginate($perPage);

            $transformed = collect($conversations->items())->map(function (ChatConversation $conv) use ($user) {
                return [
                    'id' => $conv->id,
                    'uuid' => $conv->uuid,
                    'type' => $conv->type,
                    'title' => $conv->displayNameFor($user),
                    'raw_title' => $conv->title,
                    'description' => $conv->description,
                    'avatar_url' => $conv->displayAvatarFor($user),
                    'is_channel' => $conv->isChannel(),
                    'is_group' => $conv->isGroup(),
                    'is_direct' => $conv->isDirect(),
                    'unread_count' => (int) $conv->unread_count,
                    'last_message' => $conv->lastMessage ? [
                        'id' => $conv->lastMessage->id,
                        'body' => $conv->lastMessage->body,
                        'type' => $conv->lastMessage->type,
                        'created_at' => $conv->lastMessage->created_at->toIso8601String(),
                        'sender' => $conv->lastMessage->sender ? [
                            'id' => $conv->lastMessage->sender->id,
                            'name' => $conv->lastMessage->sender->name,
                        ] : null,
                    ] : null,
                    'participants_count' => $conv->participants->count(),
                    'updated_at' => $conv->updated_at->toIso8601String(),
                ];
            });

            return $this->success($transformed, [
                'current_page' => $conversations->currentPage(),
                'per_page' => $conversations->perPage(),
                'total' => $conversations->total(),
                'last_page' => $conversations->lastPage(),
            ]);
        } catch (Throwable $e) {
            Log::error('ChatApi::indexConversations error: '.$e->getMessage(), ['trace' => $e->getTraceAsString()]);

            return $this->error('CONVERSATION_LIST_ERROR', 'Failed to retrieve conversations.', 500);
        }
    }

    /**
     * Start or fetch existing direct 1-on-1 conversation with a user.
     */
    public function startDirect(Request $request): JsonResponse
    {
        $user = $this->resolveUser($request);
        if (! $user) {
            return $this->error('UNAUTHENTICATED', 'Authentication required.', 401);
        }

        $validator = Validator::make($request->all(), [
            'recipient_id' => ['required', 'integer', 'exists:users,id', 'different:user_id'],
        ]);

        if ($validator->fails()) {
            return $this->error('VALIDATION_ERROR', 'Validation failed.', 422, $validator->errors()->toArray());
        }

        $recipientId = (int) $request->input('recipient_id');
        if ($recipientId === (int) $user->id) {
            return $this->error('INVALID_RECIPIENT', 'Cannot create a direct conversation with yourself.', 422);
        }

        $recipient = User::findOrFail($recipientId);

        try {
            $conversation = $this->chatService->findOrCreateDirectConversation($user, $recipient);

            return $this->success([
                'id' => $conversation->id,
                'uuid' => $conversation->uuid,
                'type' => $conversation->type,
                'title' => $conversation->displayNameFor($user),
                'avatar_url' => $conversation->displayAvatarFor($user),
                'recipient' => [
                    'id' => $recipient->id,
                    'name' => $recipient->name,
                    'email' => $recipient->email,
                    'avatar' => $recipient->avatar,
                ],
                'created_at' => $conversation->created_at->toIso8601String(),
            ], status: 201);
        } catch (Throwable $e) {
            Log::error('ChatApi::startDirect error: '.$e->getMessage());

            return $this->error('DIRECT_CHAT_ERROR', 'Failed to initiate direct conversation.', 500);
        }
    }

    /**
     * Create group conversation with title, description, and participants.
     */
    public function createGroup(Request $request): JsonResponse
    {
        $user = $this->resolveUser($request);
        if (! $user) {
            return $this->error('UNAUTHENTICATED', 'Authentication required.', 401);
        }

        $validator = Validator::make($request->all(), [
            'title' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:500'],
            'participant_ids' => ['required', 'array', 'min:1'],
            'participant_ids.*' => ['integer', 'exists:users,id'],
        ]);

        if ($validator->fails()) {
            return $this->error('VALIDATION_ERROR', 'Validation failed.', 422, $validator->errors()->toArray());
        }

        try {
            DB::beginTransaction();

            $participantIds = array_map('intval', (array) $request->input('participant_ids'));
            $title = (string) $request->input('title');
            $description = $request->input('description');

            $conversation = $this->chatService->createGroupConversation(
                creator: $user,
                title: $title,
                participantUserIds: $participantIds,
                description: $description
            );

            AuditLogService::log(
                event: 'chat_group_created',
                description: "User #{$user->id} created chat group '{$title}' with ".count($participantIds).' initial members',
                auditable: $conversation,
                userId: $user->id
            );

            DB::commit();

            return $this->success([
                'id' => $conversation->id,
                'uuid' => $conversation->uuid,
                'type' => $conversation->type,
                'title' => $conversation->title,
                'description' => $conversation->description,
                'participants_count' => $conversation->participants()->count(),
                'created_at' => $conversation->created_at->toIso8601String(),
            ], status: 201);
        } catch (Throwable $e) {
            DB::rollBack();
            Log::error('ChatApi::createGroup error: '.$e->getMessage());

            return $this->error('GROUP_CREATION_ERROR', 'Failed to create group conversation.', 500);
        }
    }

    /**
     * Create announcement or discussion channel.
     */
    public function createChannel(Request $request): JsonResponse
    {
        $user = $this->resolveUser($request);
        if (! $user) {
            return $this->error('UNAUTHENTICATED', 'Authentication required.', 401);
        }

        $validator = Validator::make($request->all(), [
            'title' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:500'],
            'is_broadcast_only' => ['nullable', 'boolean'],
            'participant_ids' => ['nullable', 'array'],
            'participant_ids.*' => ['integer', 'exists:users,id'],
        ]);

        if ($validator->fails()) {
            return $this->error('VALIDATION_ERROR', 'Validation failed.', 422, $validator->errors()->toArray());
        }

        try {
            DB::beginTransaction();

            $title = (string) $request->input('title');
            $description = $request->input('description');
            $isBroadcastOnly = (bool) $request->input('is_broadcast_only', false);
            $participantIds = array_map('intval', (array) $request->input('participant_ids', []));

            $channel = $this->chatService->createChannel(
                creator: $user,
                title: $title,
                subscriberUserIds: $participantIds,
                description: $description,
                isBroadcastOnly: $isBroadcastOnly
            );

            AuditLogService::log(
                event: 'chat_channel_created',
                description: "User #{$user->id} created channel '{$title}' (broadcast_only: ".($isBroadcastOnly ? 'yes' : 'no').')',
                auditable: $channel,
                userId: $user->id
            );

            DB::commit();

            return $this->success([
                'id' => $channel->id,
                'uuid' => $channel->uuid,
                'type' => $channel->type,
                'title' => $channel->title,
                'description' => $channel->description,
                'is_broadcast_only' => (bool) $channel->is_broadcast_only,
                'created_at' => $channel->created_at->toIso8601String(),
            ], status: 201);
        } catch (Throwable $e) {
            DB::rollBack();
            Log::error('ChatApi::createChannel error: '.$e->getMessage());

            return $this->error('CHANNEL_CREATION_ERROR', 'Failed to create channel.', 500);
        }
    }

    /**
     * Retrieve single conversation details, active members, and settings.
     */
    public function showConversation(Request $request, int|string $id): JsonResponse
    {
        $user = $this->resolveUser($request);
        if (! $user) {
            return $this->error('UNAUTHENTICATED', 'Authentication required.', 401);
        }

        $conversation = is_numeric($id)
            ? ChatConversation::find($id)
            : ChatConversation::where('uuid', $id)->first();

        if (! $conversation) {
            return $this->error('NOT_FOUND', 'Conversation not found.', 404);
        }

        if (! $this->checkConversationAccess($conversation, $user)) {
            return $this->error('FORBIDDEN', 'Access denied to this conversation.', 403);
        }

        $participants = $conversation->participants()
            ->with('user:id,name,email,avatar,status')
            ->whereNull('left_at')
            ->get()
            ->map(fn (ChatParticipant $p) => [
                'id' => $p->id,
                'user_id' => $p->user_id,
                'name' => $p->user?->name,
                'email' => $p->user?->email,
                'avatar' => $p->user?->avatar,
                'role' => $p->role,
                'is_admin' => $p->role === 'admin',
                'is_muted' => (bool) $p->is_muted,
                'joined_at' => $p->created_at?->toIso8601String(),
            ]);

        $pinnedMessages = $conversation->messages()
            ->where('is_pinned', true)
            ->with('sender:id,name,avatar')
            ->latest('pinned_at')
            ->take(5)
            ->get()
            ->map(fn (ChatMessage $m) => [
                'id' => $m->id,
                'body' => $m->body,
                'pinned_at' => $m->pinned_at?->toIso8601String(),
                'sender' => $m->sender ? [
                    'id' => $m->sender->id,
                    'name' => $m->sender->name,
                ] : null,
            ]);

        return $this->success([
            'id' => $conversation->id,
            'uuid' => $conversation->uuid,
            'type' => $conversation->type,
            'title' => $conversation->displayNameFor($user),
            'raw_title' => $conversation->title,
            'description' => $conversation->description,
            'avatar_url' => $conversation->displayAvatarFor($user),
            'invite_code' => $conversation->invite_code,
            'is_broadcast_only' => (bool) $conversation->is_broadcast_only,
            'can_post' => $conversation->canPost($user),
            'is_admin' => $conversation->isUserAdmin($user->id),
            'participants' => $participants,
            'pinned_messages' => $pinnedMessages,
            'created_at' => $conversation->created_at->toIso8601String(),
            'updated_at' => $conversation->updated_at->toIso8601String(),
        ]);
    }

    /**
     * Update conversation title, description, or broadcast parameters.
     */
    public function updateConversation(Request $request, int|string $id): JsonResponse
    {
        $user = $this->resolveUser($request);
        if (! $user) {
            return $this->error('UNAUTHENTICATED', 'Authentication required.', 401);
        }

        $conversation = is_numeric($id)
            ? ChatConversation::find($id)
            : ChatConversation::where('uuid', $id)->first();

        if (! $conversation) {
            return $this->error('NOT_FOUND', 'Conversation not found.', 404);
        }

        if (! $conversation->isUserAdmin($user->id) && ! $user->hasRole('Super Administrator')) {
            return $this->error('FORBIDDEN', 'Only conversation admins can update attributes.', 403);
        }

        $validator = Validator::make($request->all(), [
            'title' => ['sometimes', 'required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:500'],
            'is_broadcast_only' => ['nullable', 'boolean'],
        ]);

        if ($validator->fails()) {
            return $this->error('VALIDATION_ERROR', 'Validation failed.', 422, $validator->errors()->toArray());
        }

        try {
            DB::beginTransaction();

            $oldValues = $conversation->only(['title', 'description', 'is_broadcast_only']);

            if ($request->has('title')) {
                $conversation->title = (string) $request->input('title');
            }
            if ($request->has('description')) {
                $conversation->description = $request->input('description');
            }
            if ($request->has('is_broadcast_only')) {
                $conversation->is_broadcast_only = (bool) $request->input('is_broadcast_only');
            }

            $conversation->save();

            AuditLogService::log(
                event: 'chat_conversation_updated',
                description: "Updated conversation #{$conversation->id} attributes",
                auditable: $conversation,
                oldValues: $oldValues,
                newValues: $conversation->only(['title', 'description', 'is_broadcast_only']),
                userId: $user->id
            );

            DB::commit();

            return $this->success([
                'id' => $conversation->id,
                'title' => $conversation->title,
                'description' => $conversation->description,
                'is_broadcast_only' => (bool) $conversation->is_broadcast_only,
                'updated_at' => $conversation->updated_at->toIso8601String(),
            ]);
        } catch (Throwable $e) {
            DB::rollBack();
            Log::error('ChatApi::updateConversation error: '.$e->getMessage());

            return $this->error('UPDATE_ERROR', 'Failed to update conversation.', 500);
        }
    }

    /**
     * Leave conversation or soft-delete if admin.
     */
    public function archiveConversation(Request $request, int|string $id): JsonResponse
    {
        $user = $this->resolveUser($request);
        if (! $user) {
            return $this->error('UNAUTHENTICATED', 'Authentication required.', 401);
        }

        $conversation = is_numeric($id)
            ? ChatConversation::find($id)
            : ChatConversation::where('uuid', $id)->first();

        if (! $conversation) {
            return $this->error('NOT_FOUND', 'Conversation not found.', 404);
        }

        try {
            DB::beginTransaction();

            if ($conversation->isUserAdmin($user->id) || $user->hasRole('Super Administrator')) {
                // Soft delete conversation
                $conversation->delete();
                $action = 'archived';
            } else {
                // Remove participant record
                $participant = $conversation->participants()->where('user_id', $user->id)->first();
                if ($participant) {
                    $participant->update(['left_at' => Carbon::now()]);
                }
                $action = 'left';
            }

            AuditLogService::log(
                event: 'chat_conversation_'.$action,
                description: "User #{$user->id} {$action} conversation #{$conversation->id}",
                auditable: $conversation,
                userId: $user->id
            );

            DB::commit();

            return $this->success(['action' => $action, 'message' => "Successfully {$action} conversation."]);
        } catch (Throwable $e) {
            DB::rollBack();
            Log::error('ChatApi::archiveConversation error: '.$e->getMessage());

            return $this->error('ARCHIVE_ERROR', 'Failed to archive or leave conversation.', 500);
        }
    }

    /**
     * Mark all messages in a conversation as read by the user.
     */
    public function markAsRead(Request $request, int|string $id): JsonResponse
    {
        $user = $this->resolveUser($request);
        if (! $user) {
            return $this->error('UNAUTHENTICATED', 'Authentication required.', 401);
        }

        $conversation = is_numeric($id)
            ? ChatConversation::find($id)
            : ChatConversation::where('uuid', $id)->first();

        if (! $conversation) {
            return $this->error('NOT_FOUND', 'Conversation not found.', 404);
        }

        try {
            $updatedCount = $this->chatService->markConversationAsRead($conversation, $user);

            return $this->success([
                'conversation_id' => $conversation->id,
                'marked_read_count' => $updatedCount,
            ]);
        } catch (Throwable $e) {
            Log::error('ChatApi::markAsRead error: '.$e->getMessage());

            return $this->error('MARK_READ_ERROR', 'Failed to mark conversation as read.', 500);
        }
    }

    /**
     * Broadcast typing indicator event to other conversation participants.
     */
    public function typing(Request $request, int|string $id): JsonResponse
    {
        $user = $this->resolveUser($request);
        if (! $user) {
            return $this->error('UNAUTHENTICATED', 'Authentication required.', 401);
        }

        $conversation = is_numeric($id)
            ? ChatConversation::find($id)
            : ChatConversation::where('uuid', $id)->first();

        if (! $conversation) {
            return $this->error('NOT_FOUND', 'Conversation not found.', 404);
        }

        if (! $this->checkConversationAccess($conversation, $user)) {
            return $this->error('FORBIDDEN', 'Access denied to this conversation.', 403);
        }

        $isTyping = $request->boolean('is_typing', true);

        try {
            $dispatched = $this->chatService->broadcastTypingIndicator($conversation, $user, $isTyping);

            return $this->success([
                'conversation_id' => $conversation->id,
                'user_id' => $user->id,
                'is_typing' => $isTyping,
                'dispatched' => $dispatched,
            ]);
        } catch (Throwable $e) {
            return $this->error('TYPING_BROADCAST_ERROR', $e->getMessage(), 500);
        }
    }

    /**
     * Add participant(s) to group or channel.
     */
    public function addParticipant(Request $request, int|string $id): JsonResponse
    {
        $user = $this->resolveUser($request);
        if (! $user) {
            return $this->error('UNAUTHENTICATED', 'Authentication required.', 401);
        }

        $conversation = is_numeric($id)
            ? ChatConversation::find($id)
            : ChatConversation::where('uuid', $id)->first();

        if (! $conversation) {
            return $this->error('NOT_FOUND', 'Conversation not found.', 404);
        }

        if (! $conversation->isUserAdmin($user->id) && ! $user->hasRole('Super Administrator')) {
            return $this->error('FORBIDDEN', 'Only conversation admins can add members.', 403);
        }

        $validator = Validator::make($request->all(), [
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'role' => ['nullable', 'string', 'in:member,moderator,admin'],
        ]);

        if ($validator->fails()) {
            return $this->error('VALIDATION_ERROR', 'Validation failed.', 422, $validator->errors()->toArray());
        }

        $userToAdd = User::findOrFail((int) $request->input('user_id'));
        $role = (string) $request->input('role', 'member');

        try {
            DB::beginTransaction();

            $participant = $this->chatService->addParticipant(
                conversation: $conversation,
                userToAdd: $userToAdd,
                actor: $user,
                role: $role
            );

            AuditLogService::log(
                event: 'chat_participant_added',
                description: "User #{$user->id} added #{$userToAdd->id} ({$userToAdd->name}) to conversation #{$conversation->id} as {$role}",
                auditable: $conversation,
                userId: $user->id
            );

            DB::commit();

            return $this->success([
                'id' => $participant->id,
                'user_id' => $participant->user_id,
                'role' => $participant->role,
                'conversation_id' => $conversation->id,
            ], status: 201);
        } catch (Throwable $e) {
            DB::rollBack();
            Log::error('ChatApi::addParticipant error: '.$e->getMessage());

            return $this->error('ADD_PARTICIPANT_ERROR', $e->getMessage(), 500);
        }
    }

    /**
     * Remove a participant from group or channel.
     */
    public function removeParticipant(Request $request, int|string $id, int $userId): JsonResponse
    {
        $user = $this->resolveUser($request);
        if (! $user) {
            return $this->error('UNAUTHENTICATED', 'Authentication required.', 401);
        }

        $conversation = is_numeric($id)
            ? ChatConversation::find($id)
            : ChatConversation::where('uuid', $id)->first();

        if (! $conversation) {
            return $this->error('NOT_FOUND', 'Conversation not found.', 404);
        }

        $userToRemove = User::findOrFail($userId);

        try {
            DB::beginTransaction();

            $this->chatService->removeParticipant($conversation, $userToRemove, $user);

            AuditLogService::log(
                event: 'chat_participant_removed',
                description: "User #{$user->id} removed #{$userToRemove->id} ({$userToRemove->name}) from conversation #{$conversation->id}",
                auditable: $conversation,
                userId: $user->id
            );

            DB::commit();

            return $this->success(['message' => 'Participant successfully removed.']);
        } catch (ValidationException $e) {
            DB::rollBack();

            return $this->error('REMOVE_DENIED', $e->validator->errors()->first(), 422);
        } catch (Throwable $e) {
            DB::rollBack();
            Log::error('ChatApi::removeParticipant error: '.$e->getMessage());

            return $this->error('REMOVE_ERROR', 'Failed to remove participant.', 500);
        }
    }

    /**
     * Update participant role in group/channel (admin, moderator, member).
     */
    public function updateParticipantRole(Request $request, int|string $id, int $userId): JsonResponse
    {
        $user = $this->resolveUser($request);
        if (! $user) {
            return $this->error('UNAUTHENTICATED', 'Authentication required.', 401);
        }

        $conversation = is_numeric($id)
            ? ChatConversation::find($id)
            : ChatConversation::where('uuid', $id)->first();

        if (! $conversation) {
            return $this->error('NOT_FOUND', 'Conversation not found.', 404);
        }

        $validator = Validator::make($request->all(), [
            'role' => ['required', 'string', 'in:member,moderator,admin'],
        ]);

        if ($validator->fails()) {
            return $this->error('VALIDATION_ERROR', 'Validation failed.', 422, $validator->errors()->toArray());
        }

        $userToUpdate = User::findOrFail($userId);
        $newRole = (string) $request->input('role');

        try {
            DB::beginTransaction();

            $this->chatService->updateParticipantRole($conversation, $userToUpdate, $newRole, $user);

            AuditLogService::log(
                event: 'chat_role_updated',
                description: "User #{$user->id} changed role of #{$userToUpdate->id} in conversation #{$conversation->id} to {$newRole}",
                auditable: $conversation,
                userId: $user->id
            );

            DB::commit();

            return $this->success([
                'conversation_id' => $conversation->id,
                'user_id' => $userToUpdate->id,
                'new_role' => $newRole,
            ]);
        } catch (ValidationException $e) {
            DB::rollBack();

            return $this->error('ROLE_CHANGE_DENIED', $e->validator->errors()->first(), 422);
        } catch (Throwable $e) {
            DB::rollBack();

            return $this->error('ROLE_UPDATE_ERROR', $e->getMessage(), 500);
        }
    }

    // =========================================================================
    // 3. MESSAGING & ATTACHMENTS
    // =========================================================================

    /**
     * Retrieve paginated message history for a conversation.
     */
    public function getMessages(Request $request, int|string $id): JsonResponse
    {
        $user = $this->resolveUser($request);
        if (! $user) {
            return $this->error('UNAUTHENTICATED', 'Authentication required.', 401);
        }

        $conversation = is_numeric($id)
            ? ChatConversation::find($id)
            : ChatConversation::where('uuid', $id)->first();

        if (! $conversation) {
            return $this->error('NOT_FOUND', 'Conversation not found.', 404);
        }

        if (! $this->checkConversationAccess($conversation, $user)) {
            return $this->error('FORBIDDEN', 'Access denied to this conversation.', 403);
        }

        try {
            $perPage = max(1, min((int) ($request->input('per_page', 50)), 100));
            $beforeId = $request->input('before_id');

            $query = $conversation->messages()
                ->visibleForUser($user->id)
                ->where('is_deleted_for_everyone', false)
                ->with([
                    'sender:id,name,email,avatar',
                    'attachments',
                    'reactions',
                    'replyTo.sender:id,name',
                ]);

            if ($beforeId) {
                $query->where('id', '<', (int) $beforeId);
            }

            $messages = $query->latest('id')->paginate($perPage);

            $formatted = collect($messages->items())->map(function (ChatMessage $msg) use ($user) {
                $userReactions = $msg->reactions->where('user_id', $user->id)->pluck('emoji')->toArray();

                return [
                    'id' => $msg->id,
                    'conversation_id' => $msg->conversation_id,
                    'sender' => $msg->sender ? [
                        'id' => $msg->sender->id,
                        'name' => $msg->sender->name,
                        'email' => $msg->sender->email,
                        'avatar' => $msg->sender->avatar,
                    ] : null,
                    'is_me' => (int) $msg->user_id === (int) $user->id,
                    'body' => $msg->body,
                    'type' => $msg->type,
                    'is_pinned' => (bool) $msg->is_pinned,
                    'pinned_at' => $msg->pinned_at?->toIso8601String(),
                    'edited_at' => $msg->edited_at?->toIso8601String(),
                    'reply_to' => $msg->replyTo ? [
                        'id' => $msg->replyTo->id,
                        'body' => $msg->replyTo->body,
                        'sender_name' => $msg->replyTo->sender?->name,
                    ] : null,
                    'attachments' => $msg->attachments->map(fn ($att) => [
                        'id' => $att->id,
                        'file_name' => $att->file_name,
                        'file_url' => $att->url,
                        'file_type' => $att->file_type,
                        'file_size' => $att->file_size,
                        'is_image' => $att->isImage(),
                    ]),
                    'reactions' => $msg->reactions->groupBy('emoji')->map(fn ($r, $emoji) => [
                        'emoji' => $emoji,
                        'count' => count($r),
                        'reacted_by_me' => in_array($emoji, $userReactions, true),
                    ])->values(),
                    'created_at' => $msg->created_at->toIso8601String(),
                ];
            });

            return $this->success($formatted, [
                'current_page' => $messages->currentPage(),
                'per_page' => $messages->perPage(),
                'total' => $messages->total(),
                'has_more' => $messages->hasMorePages(),
            ]);
        } catch (Throwable $e) {
            Log::error('ChatApi::getMessages error: '.$e->getMessage());

            return $this->error('MESSAGES_FETCH_ERROR', 'Failed to retrieve message history.', 500);
        }
    }

    /**
     * Send text or media message to a conversation.
     */
    public function sendMessage(Request $request, int|string $id): JsonResponse
    {
        $user = $this->resolveUser($request);
        if (! $user) {
            return $this->error('UNAUTHENTICATED', 'Authentication required.', 401);
        }

        $conversation = is_numeric($id)
            ? ChatConversation::find($id)
            : ChatConversation::where('uuid', $id)->first();

        if (! $conversation) {
            return $this->error('NOT_FOUND', 'Conversation not found.', 404);
        }

        if (! $conversation->canPost($user)) {
            return $this->error('FORBIDDEN', 'You do not have permission to post messages in this conversation.', 403);
        }

        $validator = Validator::make($request->all(), [
            'body' => ['nullable', 'string', 'max:5000'],
            'reply_to_id' => ['nullable', 'integer', 'exists:chat_messages,id'],
            'attachments' => ['nullable', 'array', 'max:10'],
            'attachments.*' => ['file', 'max:25600'], // 25MB max
        ]);

        if ($validator->fails()) {
            return $this->error('VALIDATION_ERROR', 'Validation failed.', 422, $validator->errors()->toArray());
        }

        $body = $request->input('body');
        $replyToId = $request->input('reply_to_id');
        $files = $request->file('attachments', []);

        if (empty(trim((string) $body)) && empty($files)) {
            return $this->error('EMPTY_MESSAGE', 'Message body or at least one file attachment is required.', 422);
        }

        try {
            $message = $this->chatService->sendMessage(
                conversation: $conversation,
                sender: $user,
                body: $body,
                replyToId: $replyToId ? (int) $replyToId : null,
                attachments: (array) $files
            );

            return $this->success([
                'id' => $message->id,
                'conversation_id' => $message->conversation_id,
                'body' => $message->body,
                'type' => $message->type,
                'created_at' => $message->created_at->toIso8601String(),
                'sender' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'avatar' => $user->avatar,
                ],
                'attachments_count' => $message->attachments()->count(),
            ], status: 201);
        } catch (Throwable $e) {
            Log::error('ChatApi::sendMessage error: '.$e->getMessage(), ['trace' => $e->getTraceAsString()]);

            return $this->error('SEND_MESSAGE_ERROR', $e->getMessage(), 500);
        }
    }

    /**
     * Edit message content (within allowable window).
     */
    public function editMessage(Request $request, int|string $messageId): JsonResponse
    {
        $user = $this->resolveUser($request);
        if (! $user) {
            return $this->error('UNAUTHENTICATED', 'Authentication required.', 401);
        }

        $message = ChatMessage::findOrFail((int) $messageId);

        $validator = Validator::make($request->all(), [
            'body' => ['required', 'string', 'max:5000'],
        ]);

        if ($validator->fails()) {
            return $this->error('VALIDATION_ERROR', 'Validation failed.', 422, $validator->errors()->toArray());
        }

        try {
            $updatedMessage = $this->chatService->editMessage($message, $user, (string) $request->input('body'));

            return $this->success([
                'id' => $updatedMessage->id,
                'body' => $updatedMessage->body,
                'edited_at' => $updatedMessage->edited_at?->toIso8601String(),
            ]);
        } catch (ValidationException $e) {
            return $this->error('EDIT_DENIED', $e->validator->errors()->first(), 422);
        } catch (Throwable $e) {
            Log::error('ChatApi::editMessage error: '.$e->getMessage());

            return $this->error('EDIT_ERROR', 'Failed to edit message.', 500);
        }
    }

    /**
     * Delete message (for current user only or for everyone).
     */
    public function deleteMessage(Request $request, int|string $messageId): JsonResponse
    {
        $user = $this->resolveUser($request);
        if (! $user) {
            return $this->error('UNAUTHENTICATED', 'Authentication required.', 401);
        }

        $message = ChatMessage::findOrFail((int) $messageId);
        $mode = $request->input('mode', 'me'); // 'me' or 'everyone'

        try {
            if ($mode === 'everyone') {
                $this->chatService->deleteMessageForEveryone($message, $user);
                $action = 'deleted_for_everyone';

                AuditLogService::log(
                    event: 'chat_message_deleted_everyone',
                    description: "User #{$user->id} deleted message #{$message->id} for everyone in conversation #{$message->conversation_id}",
                    auditable: $message,
                    userId: $user->id
                );
            } else {
                $this->chatService->deleteMessageForMe($message, $user);
                $action = 'deleted_for_me';
            }

            return $this->success([
                'id' => $message->id,
                'mode' => $mode,
                'action' => $action,
            ]);
        } catch (ValidationException $e) {
            return $this->error('DELETE_DENIED', $e->validator->errors()->first(), 422);
        } catch (Throwable $e) {
            Log::error('ChatApi::deleteMessage error: '.$e->getMessage());

            return $this->error('DELETE_ERROR', 'Failed to delete message.', 500);
        }
    }

    /**
     * Pin message to conversation header.
     */
    public function pinMessage(Request $request, int|string $messageId): JsonResponse
    {
        $user = $this->resolveUser($request);
        if (! $user) {
            return $this->error('UNAUTHENTICATED', 'Authentication required.', 401);
        }

        $message = ChatMessage::findOrFail((int) $messageId);

        try {
            $this->chatService->pinMessage($message, $user);

            return $this->success([
                'id' => $message->id,
                'is_pinned' => true,
                'pinned_at' => $message->fresh()->pinned_at?->toIso8601String(),
            ]);
        } catch (ValidationException $e) {
            return $this->error('PIN_DENIED', $e->validator->errors()->first(), 422);
        } catch (Throwable $e) {
            return $this->error('PIN_ERROR', $e->getMessage(), 500);
        }
    }

    /**
     * Unpin message from conversation header.
     */
    public function unpinMessage(Request $request, int|string $messageId): JsonResponse
    {
        $user = $this->resolveUser($request);
        if (! $user) {
            return $this->error('UNAUTHENTICATED', 'Authentication required.', 401);
        }

        $message = ChatMessage::findOrFail((int) $messageId);

        try {
            $this->chatService->unpinMessage($message, $user);

            return $this->success([
                'id' => $message->id,
                'is_pinned' => false,
            ]);
        } catch (ValidationException $e) {
            return $this->error('UNPIN_DENIED', $e->validator->errors()->first(), 422);
        } catch (Throwable $e) {
            return $this->error('UNPIN_ERROR', $e->getMessage(), 500);
        }
    }

    /**
     * Toggle emoji reaction on message.
     */
    public function reactMessage(Request $request, int|string $messageId): JsonResponse
    {
        $user = $this->resolveUser($request);
        if (! $user) {
            return $this->error('UNAUTHENTICATED', 'Authentication required.', 401);
        }

        $message = ChatMessage::findOrFail((int) $messageId);

        $validator = Validator::make($request->all(), [
            'emoji' => ['required', 'string', 'max:16'],
        ]);

        if ($validator->fails()) {
            return $this->error('VALIDATION_ERROR', 'Validation failed.', 422, $validator->errors()->toArray());
        }

        $emoji = (string) $request->input('emoji');

        try {
            $reactionsSummary = $this->chatService->toggleReaction($message, $user, $emoji);

            return $this->success([
                'message_id' => $message->id,
                'emoji' => $emoji,
                'summary' => $reactionsSummary,
            ]);
        } catch (Throwable $e) {
            return $this->error('REACTION_ERROR', $e->getMessage(), 500);
        }
    }

    /**
     * Bulk personalized broadcast message dispatch.
     */
    public function broadcast(Request $request): JsonResponse
    {
        $user = $this->resolveUser($request);
        if (! $user) {
            return $this->error('UNAUTHENTICATED', 'Authentication required.', 401);
        }

        if (! $user->can('chat.broadcast') && ! $user->hasRole('Super Administrator')) {
            return $this->error('FORBIDDEN', 'Broadcast permission (chat.broadcast) required.', 403);
        }

        $validator = Validator::make($request->all(), [
            'body' => ['required', 'string', 'max:5000'],
            'title' => ['nullable', 'string', 'max:120'],
            'recipient_user_ids' => ['required', 'array', 'min:1'],
            'recipient_user_ids.*' => ['integer', 'exists:users,id'],
        ]);

        if ($validator->fails()) {
            return $this->error('VALIDATION_ERROR', 'Validation failed.', 422, $validator->errors()->toArray());
        }

        $recipientIds = array_map('intval', (array) $request->input('recipient_user_ids'));
        $body = (string) $request->input('body');
        $title = $request->input('title');

        try {
            DB::beginTransaction();

            $dispatchedCount = $this->chatService->sendBroadcastMessage(
                sender: $user,
                body: $body,
                recipientUserIds: $recipientIds,
                title: $title
            );

            AuditLogService::log(
                event: 'chat_broadcast_sent',
                description: "User #{$user->id} sent broadcast '{$title}' to {$dispatchedCount} recipients",
                userId: $user->id
            );

            DB::commit();

            return $this->success([
                'dispatched_count' => $dispatchedCount,
                'title' => $title,
            ]);
        } catch (Throwable $e) {
            DB::rollBack();
            Log::error('ChatApi::broadcast error: '.$e->getMessage());

            return $this->error('BROADCAST_ERROR', 'Failed to dispatch broadcast messages.', 500);
        }
    }

    // =========================================================================
    // 4. WEBRTC CALLS & SIGNALING
    // =========================================================================

    /**
     * Initiate 1-on-1 audio or video call in conversation.
     */
    public function initiateCall(Request $request): JsonResponse
    {
        $user = $this->resolveUser($request);
        if (! $user) {
            return $this->error('UNAUTHENTICATED', 'Authentication required.', 401);
        }

        $validator = Validator::make($request->all(), [
            'conversation_id' => ['required', 'integer', 'exists:chat_conversations,id'],
            'call_type' => ['required', 'string', 'in:audio,video'],
        ]);

        if ($validator->fails()) {
            return $this->error('VALIDATION_ERROR', 'Validation failed.', 422, $validator->errors()->toArray());
        }

        $conversation = ChatConversation::findOrFail((int) $request->input('conversation_id'));
        $callType = (string) $request->input('call_type');

        if (! $this->checkConversationAccess($conversation, $user)) {
            return $this->error('FORBIDDEN', 'Access denied to this conversation.', 403);
        }

        try {
            DB::beginTransaction();

            if ($conversation->isDirect()) {
                $otherParticipant = $conversation->participants()->where('user_id', '!=', $user->id)->with('user')->first();
                if (! $otherParticipant || ! $otherParticipant->user) {
                    return $this->error('NO_RECIPIENT', 'No other participant found in direct conversation.', 422);
                }
                $call = $this->webRtcCallService->initiateCall($user, $otherParticipant->user, $callType, $conversation->id);
            } else {
                $call = $this->webRtcCallService->initiateGroupCall($user, $conversation, $callType);
            }

            AuditLogService::log(
                event: 'chat_call_initiated',
                description: "User #{$user->id} initiated {$callType} call #{$call->uuid} in conversation #{$conversation->id}",
                auditable: $call,
                userId: $user->id
            );

            DB::commit();

            return $this->success([
                'uuid' => $call->uuid,
                'conversation_id' => $call->conversation_id,
                'call_type' => $call->type,
                'status' => $call->status,
                'caller_id' => $call->caller_id,
                'receiver_id' => $call->receiver_id,
                'ice_servers' => $this->webRtcCallService->getIceServers(),
                'created_at' => $call->created_at->toIso8601String(),
            ], status: 201);
        } catch (Throwable $e) {
            DB::rollBack();
            Log::error('ChatApi::initiateCall error: '.$e->getMessage());

            return $this->error('CALL_INIT_ERROR', $e->getMessage(), 500);
        }
    }

    /**
     * Initiate group audio or video call in conversation.
     */
    public function initiateGroupCall(Request $request): JsonResponse
    {
        $user = $this->resolveUser($request);
        if (! $user) {
            return $this->error('UNAUTHENTICATED', 'Authentication required.', 401);
        }

        $validator = Validator::make($request->all(), [
            'conversation_id' => ['required', 'integer', 'exists:chat_conversations,id'],
            'call_type' => ['required', 'string', 'in:audio,video'],
        ]);

        if ($validator->fails()) {
            return $this->error('VALIDATION_ERROR', 'Validation failed.', 422, $validator->errors()->toArray());
        }

        $conversation = ChatConversation::findOrFail((int) $request->input('conversation_id'));
        $callType = (string) $request->input('call_type');

        if (! $this->checkConversationAccess($conversation, $user)) {
            return $this->error('FORBIDDEN', 'Access denied to this conversation.', 403);
        }

        try {
            DB::beginTransaction();

            $call = $this->webRtcCallService->initiateGroupCall($user, $conversation, $callType);

            AuditLogService::log(
                event: 'chat_group_call_initiated',
                description: "User #{$user->id} initiated group {$callType} call #{$call->uuid} in conversation #{$conversation->id}",
                auditable: $call,
                userId: $user->id
            );

            DB::commit();

            return $this->success([
                'uuid' => $call->uuid,
                'conversation_id' => $call->conversation_id,
                'call_type' => $call->type,
                'status' => $call->status,
                'caller_id' => $call->caller_id,
                'is_group' => true,
                'ice_servers' => $this->webRtcCallService->getIceServers(),
                'created_at' => $call->created_at->toIso8601String(),
            ], status: 201);
        } catch (Throwable $e) {
            DB::rollBack();
            Log::error('ChatApi::initiateGroupCall error: '.$e->getMessage());

            return $this->error('GROUP_CALL_INIT_ERROR', $e->getMessage(), 500);
        }
    }

    /**
     * Relay WebRTC call signaling message (SDP offer, answer, ICE candidate, hangup, mode switch).
     */
    public function signalCall(Request $request, string $callUuid): JsonResponse
    {
        $user = $this->resolveUser($request);
        if (! $user) {
            return $this->error('UNAUTHENTICATED', 'Authentication required.', 401);
        }

        $validator = Validator::make($request->all(), [
            'signal_type' => ['nullable', 'string', 'max:64'],
            'signalType' => ['nullable', 'string', 'max:64'],
            'payload' => ['nullable', 'array'],
            'target_user_id' => ['nullable', 'integer'],
            'targetUserId' => ['nullable', 'integer'],
        ]);

        if ($validator->fails()) {
            return $this->error('VALIDATION_ERROR', 'Validation failed.', 422, $validator->errors()->toArray());
        }

        $signalType = (string) ($request->input('signal_type') ?? $request->input('signalType') ?? 'signal');
        $payload = (array) ($request->input('payload') ?? []);
        $targetUserId = $request->input('target_user_id') ?? $request->input('targetUserId');

        $signalData = [
            'signal_type' => $signalType,
            'signalType' => $signalType,
            'payload' => $payload,
            'target_user_id' => $targetUserId ? (int) $targetUserId : null,
            'targetUserId' => $targetUserId ? (int) $targetUserId : null,
        ];

        try {
            $relayed = $this->webRtcCallService->sendSignal(
                callUuid: $callUuid,
                sender: $user,
                signalType: $signalType,
                payload: $payload,
                recipientUserId: $targetUserId ? (int) $targetUserId : null
            );

            return $this->success([
                'call_uuid' => $callUuid,
                'signal_type' => $signalType,
                'relayed' => $relayed,
            ]);
        } catch (Throwable $e) {
            Log::error('ChatApi::signalCall error: '.$e->getMessage());

            return $this->error('SIGNAL_ERROR', $e->getMessage(), 500);
        }
    }

    /**
     * Accept incoming 1-on-1 call.
     */
    public function acceptCall(Request $request, string $callUuid): JsonResponse
    {
        $user = $this->resolveUser($request);
        if (! $user) {
            return $this->error('UNAUTHENTICATED', 'Authentication required.', 401);
        }

        try {
            $call = $this->webRtcCallService->acceptCall($callUuid, $user);

            return $this->success([
                'uuid' => $call->uuid,
                'status' => $call->status,
                'started_at' => $call->started_at?->toIso8601String(),
                'ice_servers' => $this->webRtcCallService->getIceServers(),
            ]);
        } catch (Throwable $e) {
            return $this->error('ACCEPT_CALL_ERROR', $e->getMessage(), 500);
        }
    }

    /**
     * Reject or decline incoming call.
     */
    public function rejectCall(Request $request, string $callUuid): JsonResponse
    {
        $user = $this->resolveUser($request);
        if (! $user) {
            return $this->error('UNAUTHENTICATED', 'Authentication required.', 401);
        }

        $reason = (string) $request->input('reason', 'declined');

        try {
            $call = $this->webRtcCallService->rejectCall($callUuid, $user, $reason);

            return $this->success([
                'uuid' => $call->uuid,
                'status' => $call->status,
                'reason' => $reason,
            ]);
        } catch (Throwable $e) {
            return $this->error('REJECT_CALL_ERROR', $e->getMessage(), 500);
        }
    }

    /**
     * Join an active group call.
     */
    public function joinGroupCall(Request $request, string $callUuid): JsonResponse
    {
        $user = $this->resolveUser($request);
        if (! $user) {
            return $this->error('UNAUTHENTICATED', 'Authentication required.', 401);
        }

        try {
            $call = $this->webRtcCallService->joinGroupCall($callUuid, $user);

            return $this->success([
                'uuid' => $call->uuid,
                'status' => $call->status,
                'participants' => $call->participants()->with('user:id,name,avatar')->get(),
                'ice_servers' => $this->webRtcCallService->getIceServers(),
            ]);
        } catch (Throwable $e) {
            return $this->error('JOIN_GROUP_CALL_ERROR', $e->getMessage(), 500);
        }
    }

    /**
     * Leave call.
     */
    public function leaveCall(Request $request, string $callUuid): JsonResponse
    {
        $user = $this->resolveUser($request);
        if (! $user) {
            return $this->error('UNAUTHENTICATED', 'Authentication required.', 401);
        }

        try {
            $call = $this->webRtcCallService->leaveCall($callUuid, $user);

            return $this->success([
                'uuid' => $call->uuid,
                'status' => $call->status,
            ]);
        } catch (Throwable $e) {
            return $this->error('LEAVE_CALL_ERROR', $e->getMessage(), 500);
        }
    }

    /**
     * End call for everyone.
     */
    public function endCall(Request $request, string $callUuid): JsonResponse
    {
        $user = $this->resolveUser($request);
        if (! $user) {
            return $this->error('UNAUTHENTICATED', 'Authentication required.', 401);
        }

        try {
            $call = $this->webRtcCallService->endCall($callUuid, $user);

            AuditLogService::log(
                event: 'chat_call_ended',
                description: "User #{$user->id} terminated call #{$call->uuid} (duration: {$call->duration_seconds}s)",
                auditable: $call,
                userId: $user->id
            );

            return $this->success([
                'uuid' => $call->uuid,
                'status' => $call->status,
                'duration_seconds' => $call->duration_seconds,
            ]);
        } catch (Throwable $e) {
            return $this->error('END_CALL_ERROR', $e->getMessage(), 500);
        }
    }

    /**
     * Get live status and participants for a call.
     */
    public function callStatus(Request $request, string $callUuid): JsonResponse
    {
        $user = $this->resolveUser($request);
        if (! $user) {
            return $this->error('UNAUTHENTICATED', 'Authentication required.', 401);
        }

        $call = ChatCall::where('uuid', $callUuid)->with(['caller:id,name,avatar', 'receiver:id,name,avatar', 'participants.user:id,name,avatar'])->first();
        if (! $call) {
            return $this->error('NOT_FOUND', 'Call session not found.', 404);
        }

        return $this->success([
            'uuid' => $call->uuid,
            'type' => $call->type,
            'status' => $call->status,
            'caller' => $call->caller,
            'receiver' => $call->receiver,
            'duration_seconds' => $call->duration_seconds,
            'started_at' => $call->started_at?->toIso8601String(),
            'ended_at' => $call->ended_at?->toIso8601String(),
            'participants' => $call->participants,
        ]);
    }

    /**
     * Query user call logs and history.
     */
    public function callHistory(Request $request): JsonResponse
    {
        $user = $this->resolveUser($request);
        if (! $user) {
            return $this->error('UNAUTHENTICATED', 'Authentication required.', 401);
        }

        $perPage = max(1, min((int) ($request->input('per_page', 20)), 100));

        $calls = ChatCall::query()
            ->where(function (Builder $q) use ($user) {
                $q->where('caller_id', $user->id)
                    ->orWhere('receiver_id', $user->id)
                    ->orWhereHas('participants', fn ($pq) => $pq->where('user_id', $user->id));
            })
            ->with(['caller:id,name,avatar', 'receiver:id,name,avatar', 'conversation:id,title,type'])
            ->latest('id')
            ->paginate($perPage);

        $transformed = collect($calls->items())->map(fn (ChatCall $c) => [
            'uuid' => $c->uuid,
            'type' => $c->type,
            'status' => $c->status,
            'is_outgoing' => (int) $c->caller_id === (int) $user->id,
            'duration_seconds' => $c->duration_seconds,
            'other_party' => (int) $c->caller_id === (int) $user->id ? $c->receiver : $c->caller,
            'conversation' => $c->conversation ? [
                'id' => $c->conversation->id,
                'title' => $c->conversation->title,
                'type' => $c->conversation->type,
            ] : null,
            'started_at' => $c->started_at?->toIso8601String(),
            'ended_at' => $c->ended_at?->toIso8601String(),
            'created_at' => $c->created_at->toIso8601String(),
        ]);

        return $this->success($transformed, [
            'current_page' => $calls->currentPage(),
            'per_page' => $calls->perPage(),
            'total' => $calls->total(),
        ]);
    }

    // =========================================================================
    // 5. ONLINE MEETINGS & VIDEO CONFERENCING
    // =========================================================================

    /**
     * Query scheduled and past video conferencing meetings.
     */
    public function indexMeetings(Request $request): JsonResponse
    {
        $user = $this->resolveUser($request);
        if (! $user) {
            return $this->error('UNAUTHENTICATED', 'Authentication required.', 401);
        }

        $status = $request->input('status'); // scheduled, in_progress, ended, cancelled
        $search = $request->input('search');
        $perPage = max(1, min((int) ($request->input('per_page', 20)), 100));

        $query = ChatMeeting::query()
            ->with(['host:id,name,email,avatar'])
            ->withCount('participants');

        if (! $user->hasRole('Super Administrator')) {
            $query->where(function (Builder $q) use ($user) {
                $q->where('host_id', $user->id)
                    ->orWhereHas('participants', fn ($pq) => $pq->where('user_id', $user->id))
                    ->orWhere('access_mode', ChatMeeting::ACCESS_OPEN);
            });
        }

        if ($status) {
            $query->where('status', $status);
        }

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%")
                    ->orWhere('invite_code', 'like', "%{$search}%");
            });
        }

        $meetings = $query->latest('scheduled_at')->latest('id')->paginate($perPage);

        $transformed = collect($meetings->items())->map(fn (ChatMeeting $m) => [
            'uuid' => $m->uuid,
            'title' => $m->title,
            'description' => $m->description,
            'meeting_type' => $m->meeting_type,
            'status' => $m->status,
            'invite_code' => $m->invite_code,
            'has_passcode' => (bool) $m->require_passcode,
            'waiting_room_enabled' => (bool) $m->waiting_room_enabled,
            'start_time' => $m->start_time?->toIso8601String(),
            'duration_minutes' => $m->duration_minutes,
            'is_host' => (int) $m->host_id === (int) $user->id,
            'host' => $m->host ? [
                'id' => $m->host->id,
                'name' => $m->host->name,
                'avatar' => $m->host->avatar,
            ] : null,
            'participants_count' => $m->participants_count,
        ]);

        return $this->success($transformed, [
            'current_page' => $meetings->currentPage(),
            'per_page' => $meetings->perPage(),
            'total' => $meetings->total(),
        ]);
    }

    /**
     * Create instant meeting or schedule future conference.
     */
    public function createMeeting(Request $request): JsonResponse
    {
        $user = $this->resolveUser($request);
        if (! $user) {
            return $this->error('UNAUTHENTICATED', 'Authentication required.', 401);
        }

        $validator = Validator::make($request->all(), [
            'title' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:500'],
            'meeting_type' => ['nullable', 'string', 'in:instant,scheduled,recurring,open'],
            'start_time' => ['nullable', 'date'],
            'duration_minutes' => ['nullable', 'integer', 'min:5', 'max:1440'],
            'passcode' => ['nullable', 'string', 'max:32'],
            'require_passcode' => ['nullable', 'boolean'],
            'waiting_room_enabled' => ['nullable', 'boolean'],
            'allow_screen_share' => ['nullable', 'boolean'],
            'mute_upon_entry' => ['nullable', 'boolean'],
        ]);

        if ($validator->fails()) {
            return $this->error('VALIDATION_ERROR', 'Validation failed.', 422, $validator->errors()->toArray());
        }

        try {
            DB::beginTransaction();

            $isInstant = $request->input('meeting_type') === 'instant' || ! $request->has('start_time');
            $title = (string) $request->input('title', 'Meeting');
            $description = $request->input('description');
            $mode = (string) $request->input('mode', ChatMeeting::MODE_VIDEO);
            $passcode = $request->input('passcode');
            $accessMode = (string) $request->input('access_mode', ChatMeeting::ACCESS_OPEN);
            $coHostUserIds = array_map('intval', (array) $request->input('co_host_user_ids', []));
            $waitingRoomEnabled = (bool) $request->input('waiting_room_enabled', false);
            $allowScreenShare = (bool) $request->input('allow_screen_share', true);
            $muteUponEntry = (bool) $request->input('mute_upon_entry', false);

            if ($isInstant) {
                $meeting = $this->meetingService->createInstantMeeting(
                    host: $user,
                    title: $title,
                    mode: $mode,
                    accessMode: $accessMode,
                    description: $description,
                    coHostUserIds: $coHostUserIds
                );
            } else {
                $startTime = $request->input('start_time') ?? $request->input('scheduled_at') ?? now()->addMinutes(10);
                $durationMinutes = (int) $request->input('duration_minutes', 60);
                $inviteeUserIds = array_map('intval', (array) $request->input('invitee_user_ids', []));

                $meeting = $this->meetingService->scheduleMeeting(
                    host: $user,
                    title: $title,
                    scheduledAt: $startTime,
                    endsAt: null,
                    mode: $mode,
                    accessMode: $accessMode,
                    durationMinutes: $durationMinutes,
                    description: $description,
                    passcode: $passcode,
                    inviteeUserIds: $inviteeUserIds,
                    coHostUserIds: $coHostUserIds
                );
            }

            $settings = (array) ($meeting->settings ?? []);
            $settings['waiting_room_enabled'] = $waitingRoomEnabled;
            $settings['screen_share_enabled'] = $allowScreenShare;
            $settings['mute_upon_entry'] = $muteUponEntry;
            $meeting->update(['settings' => $settings]);
            $meeting->refresh();

            AuditLogService::log(
                event: 'chat_meeting_created',
                description: "User #{$user->id} created meeting '{$meeting->title}' (#{$meeting->uuid})",
                auditable: $meeting,
                userId: $user->id
            );

            DB::commit();

            return $this->success([
                'uuid' => $meeting->uuid,
                'title' => $meeting->title,
                'description' => $meeting->description,
                'meeting_type' => $meeting->meeting_type,
                'status' => $meeting->status,
                'invite_code' => $meeting->invite_code,
                'passcode' => $meeting->passcode,
                'waiting_room_enabled' => (bool) $meeting->waiting_room_enabled,
                'start_time' => $meeting->start_time?->toIso8601String(),
                'duration_minutes' => $meeting->duration_minutes,
                'join_url' => url("/meetings/room/{$meeting->uuid}"),
            ], status: 201);
        } catch (Throwable $e) {
            DB::rollBack();
            Log::error('ChatApi::createMeeting error: '.$e->getMessage());

            return $this->error('MEETING_CREATE_ERROR', $e->getMessage(), 500);
        }
    }

    /**
     * Retrieve single meeting profile, host info, and settings.
     */
    public function showMeeting(Request $request, string $uuid): JsonResponse
    {
        $user = $this->resolveUser($request);
        if (! $user) {
            return $this->error('UNAUTHENTICATED', 'Authentication required.', 401);
        }

        $meeting = ChatMeeting::where('uuid', $uuid)->orWhere('invite_code', $uuid)
            ->with(['host:id,name,email,avatar', 'participants.user:id,name,avatar'])
            ->first();

        if (! $meeting) {
            return $this->error('NOT_FOUND', 'Meeting not found.', 404);
        }

        return $this->success([
            'uuid' => $meeting->uuid,
            'title' => $meeting->title,
            'description' => $meeting->description,
            'meeting_type' => $meeting->meeting_type,
            'status' => $meeting->status,
            'invite_code' => $meeting->invite_code,
            'require_passcode' => (bool) $meeting->require_passcode,
            'waiting_room_enabled' => (bool) $meeting->waiting_room_enabled,
            'allow_screen_share' => (bool) $meeting->allow_screen_share,
            'mute_upon_entry' => (bool) $meeting->mute_upon_entry,
            'lock_meeting' => (bool) $meeting->lock_meeting,
            'start_time' => $meeting->start_time?->toIso8601String(),
            'duration_minutes' => $meeting->duration_minutes,
            'is_host' => (int) $meeting->host_id === (int) $user->id,
            'host' => $meeting->host,
            'participants_count' => $meeting->participants->count(),
            'participants' => $meeting->participants->map(fn ($p) => [
                'id' => $p->id,
                'user_id' => $p->user_id,
                'name' => $p->user?->name,
                'role' => $p->role,
                'status' => $p->status,
                'is_muted' => (bool) $p->is_muted,
                'video_enabled' => (bool) $p->video_enabled,
                'hand_raised' => (bool) $p->hand_raised,
            ]),
        ]);
    }

    /**
     * Update meeting attributes or schedule.
     */
    public function updateMeeting(Request $request, string $uuid): JsonResponse
    {
        $user = $this->resolveUser($request);
        if (! $user) {
            return $this->error('UNAUTHENTICATED', 'Authentication required.', 401);
        }

        $meeting = ChatMeeting::where('uuid', $uuid)->orWhere('invite_code', $uuid)->first();
        if (! $meeting) {
            return $this->error('NOT_FOUND', 'Meeting not found.', 404);
        }

        if ((int) $meeting->host_id !== (int) $user->id && ! $user->hasRole('Super Administrator')) {
            return $this->error('FORBIDDEN', 'Only host can modify meeting details.', 403);
        }

        $validator = Validator::make($request->all(), [
            'title' => ['sometimes', 'required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:500'],
            'start_time' => ['nullable', 'date'],
            'duration_minutes' => ['nullable', 'integer', 'min:5', 'max:1440'],
            'passcode' => ['nullable', 'string', 'max:32'],
            'require_passcode' => ['nullable', 'boolean'],
            'waiting_room_enabled' => ['nullable', 'boolean'],
            'allow_screen_share' => ['nullable', 'boolean'],
            'mute_upon_entry' => ['nullable', 'boolean'],
        ]);

        if ($validator->fails()) {
            return $this->error('VALIDATION_ERROR', 'Validation failed.', 422, $validator->errors()->toArray());
        }

        try {
            DB::beginTransaction();

            $oldValues = $meeting->toArray();
            $updated = $this->meetingService->updateMeeting($meeting, $request->all(), $user);

            AuditLogService::log(
                event: 'chat_meeting_updated',
                description: "User #{$user->id} updated meeting '{$updated->title}' (#{$updated->uuid})",
                auditable: $updated,
                oldValues: $oldValues,
                newValues: $updated->toArray(),
                userId: $user->id
            );

            DB::commit();

            return $this->success([
                'uuid' => $updated->uuid,
                'title' => $updated->title,
                'status' => $updated->status,
                'updated_at' => $updated->updated_at->toIso8601String(),
            ]);
        } catch (Throwable $e) {
            DB::rollBack();

            return $this->error('MEETING_UPDATE_ERROR', $e->getMessage(), 500);
        }
    }

    /**
     * Cancel scheduled meeting.
     */
    public function cancelMeeting(Request $request, string $uuid): JsonResponse
    {
        $user = $this->resolveUser($request);
        if (! $user) {
            return $this->error('UNAUTHENTICATED', 'Authentication required.', 401);
        }

        $meeting = ChatMeeting::where('uuid', $uuid)->orWhere('invite_code', $uuid)->first();
        if (! $meeting) {
            return $this->error('NOT_FOUND', 'Meeting not found.', 404);
        }

        if ((int) $meeting->host_id !== (int) $user->id && ! $user->hasRole('Super Administrator')) {
            return $this->error('FORBIDDEN', 'Only host can cancel meeting.', 403);
        }

        $reason = $request->input('reason');

        try {
            DB::beginTransaction();

            $this->meetingService->cancelMeeting($meeting, $user, $reason);

            AuditLogService::log(
                event: 'chat_meeting_cancelled',
                description: "User #{$user->id} cancelled meeting '{$meeting->title}' (#{$meeting->uuid})",
                auditable: $meeting,
                userId: $user->id
            );

            DB::commit();

            return $this->success(['message' => 'Meeting successfully cancelled.']);
        } catch (Throwable $e) {
            DB::rollBack();

            return $this->error('CANCEL_ERROR', $e->getMessage(), 500);
        }
    }

    /**
     * Join meeting and obtain participant token / room status.
     */
    public function joinMeeting(Request $request, string $uuid): JsonResponse
    {
        $user = $this->resolveUser($request);
        if (! $user) {
            return $this->error('UNAUTHENTICATED', 'Authentication required.', 401);
        }

        $meeting = ChatMeeting::where('uuid', $uuid)->orWhere('invite_code', $uuid)->first();
        if (! $meeting) {
            return $this->error('NOT_FOUND', 'Meeting not found.', 404);
        }

        if ($meeting->isEnded() || $meeting->isCancelled()) {
            return $this->error('MEETING_NOT_ACTIVE', 'This meeting is no longer active.', 410);
        }

        $passcode = $request->input('passcode');

        try {
            DB::beginTransaction();

            $participant = $this->meetingService->joinMeeting($meeting, $user, $passcode);

            DB::commit();

            return $this->success([
                'participant_id' => $participant->id,
                'role' => $participant->role,
                'status' => $participant->status,
                'is_admitted' => in_array($participant->status, [ChatMeetingParticipant::STATUS_JOINED, 'admitted'], true),
                'in_waiting_room' => $participant->status === ChatMeetingParticipant::STATUS_WAITING,
                'ice_servers' => $this->meetingService->getIceServers(),
                'meeting' => [
                    'uuid' => $meeting->uuid,
                    'title' => $meeting->title,
                    'status' => $meeting->status,
                    'allow_screen_share' => (bool) $meeting->allow_screen_share,
                    'mute_upon_entry' => (bool) $meeting->mute_upon_entry,
                ],
            ]);
        } catch (ValidationException $e) {
            DB::rollBack();

            return $this->error('JOIN_DENIED', $e->validator->errors()->first(), 422);
        } catch (Throwable $e) {
            DB::rollBack();

            return $this->error('JOIN_ERROR', $e->getMessage(), 500);
        }
    }

    /**
     * Relay WebRTC meeting signaling message (offers, answers, ICE candidates, screen share).
     */
    public function signalMeeting(Request $request, string $uuid): JsonResponse
    {
        $user = $this->resolveUser($request);
        if (! $user) {
            return $this->error('UNAUTHENTICATED', 'Authentication required.', 401);
        }

        $meeting = ChatMeeting::where('uuid', $uuid)->orWhere('invite_code', $uuid)->first();
        if (! $meeting) {
            return $this->error('NOT_FOUND', 'Meeting not found.', 404);
        }

        $signalType = (string) ($request->input('signal_type') ?? $request->input('signalType') ?? 'signal');
        $payload = (array) ($request->input('payload') ?? []);
        $targetUserId = $request->input('target_user_id') ?? $request->input('targetUserId');

        try {
            $relayed = $this->meetingService->sendSignal(
                meetingUuid: $meeting->uuid,
                sender: $user,
                signalType: $signalType,
                payload: $payload,
                targetUserId: $targetUserId ? (int) $targetUserId : null
            );

            return $this->success([
                'meeting_uuid' => $meeting->uuid,
                'signal_type' => $signalType,
                'relayed' => $relayed,
            ]);
        } catch (Throwable $e) {
            return $this->error('SIGNAL_ERROR', $e->getMessage(), 500);
        }
    }

    /**
     * Sync active meeting state, participants, and speakers.
     */
    public function syncMeeting(Request $request, string $uuid): JsonResponse
    {
        $user = $this->resolveUser($request);
        if (! $user) {
            return $this->error('UNAUTHENTICATED', 'Authentication required.', 401);
        }

        $meeting = ChatMeeting::where('uuid', $uuid)->orWhere('invite_code', $uuid)->first();
        if (! $meeting) {
            return $this->error('NOT_FOUND', 'Meeting not found.', 404);
        }

        $since = (float) $request->input('since', 0.0);

        try {
            $participants = $meeting->participants()
                ->whereNull('deleted_at')
                ->with('user:id,name,avatar')
                ->get()
                ->map(fn ($p) => [
                    'id' => $p->id,
                    'user_id' => $p->user_id,
                    'name' => $p->user?->name,
                    'avatar' => $p->user?->avatar,
                    'role' => $p->role,
                    'status' => $p->status,
                    'is_muted' => (bool) $p->is_muted,
                    'video_enabled' => (bool) $p->video_enabled,
                    'hand_raised' => (bool) $p->hand_raised,
                ]);

            $reactions = $this->meetingService->getRecentReactions($meeting->uuid, $since);
            $signals = $this->meetingService->getRecentSignals($meeting->uuid, $since, $user->id);

            return $this->success([
                'meeting_status' => $meeting->status,
                'lock_meeting' => (bool) $meeting->lock_meeting,
                'allow_screen_share' => (bool) $meeting->allow_screen_share,
                'participants' => $participants,
                'reactions' => $reactions,
                'signals' => $signals,
                'sync_timestamp' => microtime(true),
            ]);
        } catch (Throwable $e) {
            return $this->error('SYNC_ERROR', $e->getMessage(), 500);
        }
    }

    /**
     * Admit participant from waiting room (Host/Co-host action).
     */
    public function admitParticipant(Request $request, string $uuid, int $participantId): JsonResponse
    {
        $user = $this->resolveUser($request);
        if (! $user) {
            return $this->error('UNAUTHENTICATED', 'Authentication required.', 401);
        }

        $meeting = ChatMeeting::where('uuid', $uuid)->orWhere('invite_code', $uuid)->first();
        if (! $meeting) {
            return $this->error('NOT_FOUND', 'Meeting not found.', 404);
        }

        try {
            $admitted = $this->meetingService->admitParticipant($meeting, $participantId, $user);

            return $this->success(['admitted' => $admitted]);
        } catch (Throwable $e) {
            return $this->error('ADMIT_ERROR', $e->getMessage(), 500);
        }
    }

    /**
     * Deny participant from waiting room (Host/Co-host action).
     */
    public function denyParticipant(Request $request, string $uuid, int $participantId): JsonResponse
    {
        $user = $this->resolveUser($request);
        if (! $user) {
            return $this->error('UNAUTHENTICATED', 'Authentication required.', 401);
        }

        $meeting = ChatMeeting::where('uuid', $uuid)->orWhere('invite_code', $uuid)->first();
        if (! $meeting) {
            return $this->error('NOT_FOUND', 'Meeting not found.', 404);
        }

        try {
            $denied = $this->meetingService->denyParticipant($meeting, $participantId, $user);

            return $this->success(['denied' => $denied]);
        } catch (Throwable $e) {
            return $this->error('DENY_ERROR', $e->getMessage(), 500);
        }
    }

    /**
     * Update participant role in meeting (co_host or attendee).
     */
    public function setMeetingParticipantRole(Request $request, string $uuid, int $participantId): JsonResponse
    {
        $user = $this->resolveUser($request);
        if (! $user) {
            return $this->error('UNAUTHENTICATED', 'Authentication required.', 401);
        }

        $meeting = ChatMeeting::where('uuid', $uuid)->orWhere('invite_code', $uuid)->first();
        if (! $meeting) {
            return $this->error('NOT_FOUND', 'Meeting not found.', 404);
        }

        $role = (string) $request->input('role', 'attendee');

        try {
            $updated = $this->meetingService->setParticipantRole($meeting, $participantId, $role, $user);

            return $this->success(['updated' => $updated, 'new_role' => $role]);
        } catch (Throwable $e) {
            return $this->error('ROLE_ERROR', $e->getMessage(), 500);
        }
    }

    /**
     * Update host meeting restrictions (lock, mute all, screen share toggle).
     */
    public function updateMeetingRestrictions(Request $request, string $uuid): JsonResponse
    {
        $user = $this->resolveUser($request);
        if (! $user) {
            return $this->error('UNAUTHENTICATED', 'Authentication required.', 401);
        }

        $meeting = ChatMeeting::where('uuid', $uuid)->orWhere('invite_code', $uuid)->first();
        if (! $meeting) {
            return $this->error('NOT_FOUND', 'Meeting not found.', 404);
        }

        try {
            $updated = $this->meetingService->updateRestrictions($meeting, $request->all(), $user);

            return $this->success(['updated' => $updated]);
        } catch (Throwable $e) {
            return $this->error('RESTRICTION_ERROR', $e->getMessage(), 500);
        }
    }

    /**
     * Post emoji reaction in active meeting.
     */
    public function recordMeetingReaction(Request $request, string $uuid): JsonResponse
    {
        $user = $this->resolveUser($request);
        if (! $user) {
            return $this->error('UNAUTHENTICATED', 'Authentication required.', 401);
        }

        $meeting = ChatMeeting::where('uuid', $uuid)->orWhere('invite_code', $uuid)->first();
        if (! $meeting) {
            return $this->error('NOT_FOUND', 'Meeting not found.', 404);
        }

        $emoji = (string) $request->input('emoji', '👍');

        try {
            $reaction = $this->meetingService->recordReaction($meeting->uuid, $user, $emoji);

            return $this->success($reaction);
        } catch (Throwable $e) {
            return $this->error('REACTION_ERROR', $e->getMessage(), 500);
        }
    }

    /**
     * Bulk dispatch email meeting invitations.
     */
    public function sendMeetingInvitations(Request $request, string $uuid): JsonResponse
    {
        $user = $this->resolveUser($request);
        if (! $user) {
            return $this->error('UNAUTHENTICATED', 'Authentication required.', 401);
        }

        $meeting = ChatMeeting::where('uuid', $uuid)->orWhere('invite_code', $uuid)->first();
        if (! $meeting) {
            return $this->error('NOT_FOUND', 'Meeting not found.', 404);
        }

        $emails = (array) $request->input('emails', []);
        if (empty($emails)) {
            return $this->error('EMPTY_EMAILS', 'At least one email recipient is required.', 422);
        }

        try {
            $sentCount = $this->meetingService->sendBulkEmailInvitations($meeting, $emails, $user);

            return $this->success(['sent_count' => $sentCount]);
        } catch (Throwable $e) {
            return $this->error('INVITATION_ERROR', $e->getMessage(), 500);
        }
    }

    /**
     * Terminate meeting for all participants.
     */
    public function endMeeting(Request $request, string $uuid): JsonResponse
    {
        $user = $this->resolveUser($request);
        if (! $user) {
            return $this->error('UNAUTHENTICATED', 'Authentication required.', 401);
        }

        $meeting = ChatMeeting::where('uuid', $uuid)->orWhere('invite_code', $uuid)->first();
        if (! $meeting) {
            return $this->error('NOT_FOUND', 'Meeting not found.', 404);
        }

        try {
            DB::beginTransaction();

            $this->meetingService->endMeeting($meeting, $user);

            AuditLogService::log(
                event: 'chat_meeting_ended',
                description: "User #{$user->id} ended meeting '{$meeting->title}' (#{$meeting->uuid})",
                auditable: $meeting,
                userId: $user->id
            );

            DB::commit();

            return $this->success(['message' => 'Meeting successfully ended.']);
        } catch (Throwable $e) {
            DB::rollBack();

            return $this->error('END_MEETING_ERROR', $e->getMessage(), 500);
        }
    }
}
