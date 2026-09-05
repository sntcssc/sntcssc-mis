<?php

use App\Models\ChatCall;
use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Models\ChatMessageAttachment;
use App\Models\ChatMessageStatus;
use App\Models\ChatParticipant;
use App\Models\Setting;
use App\Models\User;
use App\Services\AuditLogService;
use App\Services\ChatService;
use App\Services\FileUploadService;
use App\Services\WebRtcCallService;
use App\Support\Toast;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithFileUploads;

new #[Layout('layouts.app')] #[Title('Live Chat')] class extends Component {
    use WithFileUploads;

    // Active Selection
    #[Url(as: 'c')]
    public ?string $activeConversationUuid = null;
    public int $activeConversationId = 0;
    public int $currentUserId = 0;

    // Filtering & Search
    public string $search = '';
    public string $filterTab = 'all'; // all, direct, groups, channels

    // Composer State
    public string $messageText = '';
    public ?int $replyingToMessageId = null;
    public ?ChatMessage $replyingToMessage = null;
    public ?int $editingMessageId = null;
    public string $editingText = '';

    // File Uploads
    public array $attachments = []; // UploadedFile[]

    // Right Info Drawer
    public bool $showInfoDrawer = false;

    // Delete Confirmation State
    public ?int $confirmDeleteMessageId = null;
    public string $confirmDeleteMode = 'for_me'; // for_me, for_everyone

    // Call Confirmation State
    public string $pendingCallType = 'audio'; // audio, video
    public bool $isPendingGroupCall = false;

    // Modals Data
    public string $newChatSearch = '';
    public string $groupTitle = '';
    public string $groupDescription = '';
    public array $groupSelectedMembers = [];
    public $groupAvatar = null;

    public string $channelTitle = '';
    public string $channelDescription = '';
    public bool $channelIsBroadcastOnly = true;
    public array $channelSelectedMembers = [];
    public $channelAvatar = null;
    public ?string $newGroupAvatarPath = null;
    public ?string $newChannelAvatarPath = null;
    public ?string $newEditAvatarPath = null;

    public array $addMemberSelectedIds = [];

    // Edit Group Profile Form
    public string $editTitle = '';
    public string $editDescription = '';
    public string $editPostingPermission = 'all'; // all, admins_only, permitted_only
    public array $editAllowedPosterIds = [];
    public $editAvatar = null;
    public bool $removeAvatar = false;
    public $directAvatarUpload = null;

    // User Profile View State
    public ?User $viewingUserProfile = null;
    public bool $soundMuted = false;

    public function mount(?string $conversation = null, ?string $c = null): void
    {
        $this->currentUserId = auth()->id();

        $chatEnabled = (bool) Setting::get('chat.enabled', true);
        if (! $chatEnabled && ! auth()->user()?->hasRole('Super Administrator')) {
            abort(403, __('Live chat is currently disabled by administrator.'));
        }

        $this->touchUserOnline();

        $targetUuid = $conversation ?? $c ?? $this->activeConversationUuid ?? request()->query('conversation') ?? request()->query('c');

        if ($targetUuid) {
            $conv = ChatConversation::where('uuid', $targetUuid)
                ->forUser(auth()->id())
                ->first();
            if ($conv) {
                $this->activeConversationId = $conv->id;
                $this->activeConversationUuid = $conv->uuid;
                $this->loadGroupEditState($conv);
                $this->markActiveAsRead();
            }
        } elseif ($defaultConv = $this->getUserConversationsQuery()->first()) {
            $this->activeConversationId = $defaultConv->id;
            $this->activeConversationUuid = $defaultConv->uuid;
            $this->loadGroupEditState($defaultConv);
            $this->markActiveAsRead();
        }
    }

    public function touchUserOnline(): void
    {
        if (auth()->check()) {
            Cache::put('user-online-' . auth()->id(), true, now()->addMinutes(5));
        }
    }

    public function selectConversation(string $uuid): void
    {
        $this->touchUserOnline();

        $conv = ChatConversation::where('uuid', $uuid)
            ->forUser(auth()->id())
            ->first();

        if ($conv) {
            $this->activeConversationUuid = $conv->uuid;
            $this->activeConversationId = $conv->id;
            $this->cancelReply();
            $this->cancelEdit();
            $this->loadGroupEditState($conv);
            $this->markActiveAsRead();
            $this->dispatch('chat-scrolled-to-bottom');
        }
    }

    protected function loadGroupEditState(ChatConversation $conv): void
    {
        $this->editTitle = $conv->title ?? '';
        $this->editDescription = $conv->description ?? '';
        $this->editPostingPermission = $conv->settings['posting_permission'] ?? ($conv->isChannel() && $conv->is_broadcast_only ? 'admins_only' : 'all');
        $this->editAllowedPosterIds = $conv->settings['allowed_poster_ids'] ?? [];
        $this->editAvatar = null;
        $this->removeAvatar = false;
    }

    public function markActiveAsRead(): void
    {
        $this->touchUserOnline();

        if (! $this->activeConversationId) {
            return;
        }

        $user = auth()->user();
        $conv = ChatConversation::find($this->activeConversationId);
        if ($conv && $user) {
            /** @var ChatService $chatService */
            $chatService = app(ChatService::class);
            $chatService->markConversationAsRead($conv, $user);
        }
    }

    public function sendMessage(): void
    {
        $this->touchUserOnline();

        if (! $this->activeConversationId) {
            return;
        }

        $conv = ChatConversation::find($this->activeConversationId);
        $user = auth()->user();

        if (! $conv || ! $user) {
            return;
        }

        if (! $conv->canPost($user)) {
            Toast::dispatch($this, 'error', __('You do not have permission to post messages in this conversation.'));
            return;
        }

        $text = trim($this->messageText);
        if (empty($text) && empty($this->attachments)) {
            return;
        }

        $this->validate([
            'messageText' => ['nullable', 'string', 'max:10000'],
            'attachments.*' => ['nullable', 'file', 'max:25600'], // 25MB max
        ]);

        /** @var ChatService $chatService */
        $chatService = app(ChatService::class);

        try {
            $chatService->sendMessage(
                conversation: $conv,
                sender: $user,
                body: $text ?: null,
                replyToId: $this->replyingToMessageId,
                attachments: $this->attachments
            );

            $this->messageText = '';
            $this->attachments = [];
            $this->cancelReply();
            $this->dispatch('chat-scrolled-to-bottom');
        } catch (\Throwable $e) {
            Toast::dispatch($this, 'error', $e->getMessage());
        }
    }

    public function setReplyTo(int $messageId): void
    {
        $msg = ChatMessage::find($messageId);
        if ($msg && (int) $msg->conversation_id === (int) $this->activeConversationId) {
            $this->replyingToMessageId = $msg->id;
            $this->replyingToMessage = $msg;
            $this->dispatch('focus-composer');
        }
    }

    public function cancelReply(): void
    {
        $this->replyingToMessageId = null;
        $this->replyingToMessage = null;
    }

    #[On('echo-private:user.{currentUserId},.ChatMessageSent')]
    #[On('echo-private:user.{currentUserId},ChatMessageSent')]
    public function onUserIncomingChatMessage(array $event = []): void
    {
        $conversationId = (int) ($event['conversation_id'] ?? 0);
        if ($conversationId > 0 && $conversationId === (int) $this->activeConversationId) {
            $this->markActiveAsRead();
            $this->dispatch('chat-scrolled-to-bottom');
        }
    }

    #[On('echo-private:conversation.{activeConversationId},.ChatMessageSent')]
    #[On('echo-private:conversation.{activeConversationId},ChatMessageSent')]
    public function onConversationChatMessage(array $event = []): void
    {
        $this->markActiveAsRead();
        $this->dispatch('chat-scrolled-to-bottom');
    }

    #[On('echo-private:conversation.{activeConversationId},.ChatMessageUpdatedEvent')]
    #[On('echo-private:conversation.{activeConversationId},ChatMessageUpdatedEvent')]
    #[On('echo-private:conversation.{activeConversationId},.ChatMessageUpdated')]
    #[On('echo-private:conversation.{activeConversationId},ChatMessageUpdated')]
    public function onConversationMessageUpdated(array $event = []): void
    {
        // Auto-refreshes message feed
    }

    #[On('echo-private:conversation.{activeConversationId},.ChatMessageReadEvent')]
    #[On('echo-private:conversation.{activeConversationId},ChatMessageReadEvent')]
    #[On('echo-private:conversation.{activeConversationId},.ChatMessageRead')]
    #[On('echo-private:conversation.{activeConversationId},ChatMessageRead')]
    public function onConversationMessageRead(array $event = []): void
    {
        // Read ticks auto-updated
    }

    #[On('echo-private:conversation.{activeConversationId},.ChatUserTypingEvent')]
    #[On('echo-private:conversation.{activeConversationId},ChatUserTypingEvent')]
    #[On('echo-private:conversation.{activeConversationId},.ChatUserTyping')]
    #[On('echo-private:conversation.{activeConversationId},ChatUserTyping')]
    public function onChatUserTyping(array $event = []): void
    {
        $this->dispatch('chat-typing-received', $event);
    }

    public function sendTypingIndicator(bool $isTyping = true): void
    {
        $user = auth()->user();
        if (! $user || ! $this->activeConversationId) {
            return;
        }

        $conv = ChatConversation::find($this->activeConversationId);
        if ($conv) {
            /** @var ChatService $chatService */
            $chatService = app(ChatService::class);
            $chatService->broadcastTypingIndicator($conv, $user, $isTyping);
        }
    }

    #[Computed]
    public function activeGroupCall(): ?ChatCall
    {
        if (! $this->activeConversationId) {
            return null;
        }

        /** @var WebRtcCallService $callService */
        $callService = app(WebRtcCallService::class);
        return $callService->getActiveGroupCall($this->activeConversationId);
    }

    public function startEdit(int $messageId): void
    {
        $msg = ChatMessage::find($messageId);
        if ($msg && (int) $msg->user_id === (int) auth()->id()) {
            $this->editingMessageId = $msg->id;
            $this->editingText = $msg->body ?? '';
        }
    }

    public function saveEdit(): void
    {
        if (! $this->editingMessageId) {
            return;
        }

        $msg = ChatMessage::find($this->editingMessageId);
        $user = auth()->user();

        if ($msg && $user) {
            /** @var ChatService $chatService */
            $chatService = app(ChatService::class);
            try {
                $chatService->editMessage($msg, $user, $this->editingText);
                $this->cancelEdit();
                Toast::dispatch($this, 'success', __('Message updated.'));
            } catch (\Throwable $e) {
                Toast::dispatch($this, 'error', $e->getMessage());
            }
        }
    }

    public function cancelEdit(): void
    {
        $this->editingMessageId = null;
        $this->editingText = '';
    }

    public function toggleMute(): void
    {
        $this->soundMuted = ! $this->soundMuted;
    }

    public function toggleReaction(int $messageId, string $emoji): void
    {
        $this->touchUserOnline();
        $msg = ChatMessage::find($messageId);
        $user = auth()->user();
        if ($msg && $user) {
            /** @var ChatService $chatService */
            $chatService = app(ChatService::class);
            $chatService->toggleReaction($msg, $user, $emoji);
        }
    }

    public function viewUserProfile(int $userId): void
    {
        $this->viewingUserProfile = User::find($userId);
        $this->js("\$store.modals.open('user-profile-modal')");
    }

    public function startDirectMessage(int $userId): void
    {
        $user = auth()->user();
        $target = User::find($userId);
        if (! $user || ! $target || (int) $user->id === (int) $target->id) {
            return;
        }

        /** @var ChatService $chatService */
        $chatService = app(ChatService::class);
        try {
            $conv = $chatService->findOrCreateDirectConversation($user, $target);
            $this->activeConversationUuid = $conv->uuid;
            $this->activeConversationId = $conv->id;
            $this->loadGroupEditState($conv);
            $this->markActiveAsRead();
            $this->js("\$store.modals.close('user-profile-modal')");
            $this->showInfoDrawer = false;
            $this->dispatch('chat-scrolled-to-bottom');
            Toast::dispatch($this, 'success', __('Direct conversation opened.'));
        } catch (\Throwable $e) {
            Toast::dispatch($this, 'error', $e->getMessage());
        }
    }

    /* ----------------------------------------------------------------- *
     *  Message Pinning Handlers
     * ----------------------------------------------------------------- */

    public function pinMessage(int $messageId): void
    {
        $msg = ChatMessage::find($messageId);
        $user = auth()->user();
        if ($msg && $user) {
            /** @var ChatService $chatService */
            $chatService = app(ChatService::class);
            try {
                $chatService->pinMessage($msg, $user);
                Toast::dispatch($this, 'success', __('Message pinned to conversation.'));
            } catch (\Throwable $e) {
                Toast::dispatch($this, 'error', $e->getMessage());
            }
        }
    }

    public function unpinMessage(int $messageId): void
    {
        $msg = ChatMessage::find($messageId);
        $user = auth()->user();
        if ($msg && $user) {
            /** @var ChatService $chatService */
            $chatService = app(ChatService::class);
            try {
                $chatService->unpinMessage($msg, $user);
                Toast::dispatch($this, 'info', __('Message unpinned.'));
            } catch (\Throwable $e) {
                Toast::dispatch($this, 'error', $e->getMessage());
            }
        }
    }

    /* ----------------------------------------------------------------- *
     *  Delete Message Confirmation Handlers
     * ----------------------------------------------------------------- */

    public function promptDeleteMessage(int $messageId, string $mode = 'for_me'): void
    {
        $this->confirmDeleteMessageId = $messageId;
        $this->confirmDeleteMode = $mode;
        $this->js("\$store.modals.open('delete-confirm-modal')");
    }

    public function executeDeleteMessage(): void
    {
        if (! $this->confirmDeleteMessageId) {
            return;
        }

        $msg = ChatMessage::find($this->confirmDeleteMessageId);
        $user = auth()->user();

        if ($msg && $user) {
            /** @var ChatService $chatService */
            $chatService = app(ChatService::class);
            try {
                if ($this->confirmDeleteMode === 'for_everyone') {
                    $chatService->deleteMessageForEveryone($msg, $user);
                    Toast::dispatch($this, 'info', __('Message deleted for everyone.'));
                } else {
                    $chatService->deleteMessageForMe($msg, $user);
                    Toast::dispatch($this, 'info', __('Message deleted for you.'));
                }
            } catch (\Throwable $e) {
                Toast::dispatch($this, 'error', $e->getMessage());
            }
        }

        $this->confirmDeleteMessageId = null;
        $this->js("\$store.modals.close('delete-confirm-modal')");
    }

    /* ----------------------------------------------------------------- *
     *  Call Confirmation & Launch Handlers
     * ----------------------------------------------------------------- */

    public function promptCall(string $callType, bool $isGroup = false): void
    {
        $callType = in_array(strtolower($callType), ['audio', 'voice', 'phone'], true) ? ChatCall::TYPE_AUDIO : ChatCall::TYPE_VIDEO;
        $this->pendingCallType = $callType;
        $this->isPendingGroupCall = $isGroup;

        $requireWebsocket = (bool) Setting::get('chat.require_websocket_for_calls', true);
        /** @var WebRtcCallService $webrtcService */
        $webrtcService = app(WebRtcCallService::class);

        if ($requireWebsocket && ! $webrtcService->isRealtimeSupported()) {
            Toast::dispatch($this, 'warning', __('Real-time voice and video calling requires an active WebSocket connection. Please configure Laravel Reverb in your system environment.'));
            return;
        }

        $conv = ChatConversation::find($this->activeConversationId);
        $user = auth()->user();
        if (! $conv || ! $user) {
            return;
        }

        $other = $conv->isDirect() ? $conv->otherParticipant($user) : null;
        $peerName = $conv->isDirect() ? ($other?->name ?? __('Participant')) : $conv->title;
        $peerAvatar = $conv->isDirect() ? $other?->avatarUrl() : null;
        $targetUserId = $other?->id;

        $this->dispatch('open-pre-call-preview', [
            'callType' => $callType,
            'isGroup' => $isGroup,
            'peerName' => $peerName,
            'peerAvatar' => $peerAvatar,
            'targetUserId' => $targetUserId,
            'conversationId' => $conv->id,
        ]);
    }

    public function launchConfirmedCall(int $receiverId, string $type = 'video', ?int $conversationId = null, bool $startMuted = false, bool $startVideoOff = false): void
    {
        $type = in_array(strtolower($type), ['audio', 'voice', 'phone'], true) ? ChatCall::TYPE_AUDIO : ChatCall::TYPE_VIDEO;
        $requireWebsocket = (bool) Setting::get('chat.require_websocket_for_calls', true);
        /** @var WebRtcCallService $webrtcService */
        $webrtcService = app(WebRtcCallService::class);

        if ($requireWebsocket && ! $webrtcService->isRealtimeSupported()) {
            Toast::dispatch($this, 'warning', __('Real-time voice and video calling requires an active WebSocket connection. Please configure Laravel Reverb in your system environment.'));
            return;
        }

        $conv = ChatConversation::find($conversationId ?: $this->activeConversationId);
        $user = auth()->user();

        if (! $conv || ! $user) {
            return;
        }

        $other = User::find($receiverId) ?: $conv->otherParticipant($user);
        if (! $other) {
            Toast::dispatch($this, 'warning', __('Cannot find recipient for this direct call.'));
            return;
        }

        $this->dispatch('start-call', receiverId: $other->id, type: $type, conversationId: $conv->id, startMuted: $startMuted, startVideoOff: $type === 'audio' ? true : $startVideoOff);
    }

    public function launchConfirmedGroupCall(int $conversationId, string $type = 'video', bool $startMuted = false, bool $startVideoOff = false): void
    {
        $type = in_array(strtolower($type), ['audio', 'voice', 'phone'], true) ? ChatCall::TYPE_AUDIO : ChatCall::TYPE_VIDEO;
        $requireWebsocket = (bool) Setting::get('chat.require_websocket_for_calls', true);
        /** @var WebRtcCallService $webrtcService */
        $webrtcService = app(WebRtcCallService::class);

        if ($requireWebsocket && ! $webrtcService->isRealtimeSupported()) {
            Toast::dispatch($this, 'warning', __('Real-time voice and video calling requires an active WebSocket connection. Please configure Laravel Reverb in your system environment.'));
            return;
        }

        $conv = ChatConversation::find($conversationId ?: $this->activeConversationId);
        $user = auth()->user();

        if (! $conv || ! $user) {
            return;
        }

        $this->dispatch('start-group-call', conversationId: $conv->id, type: $type, startMuted: $startMuted, startVideoOff: $type === 'audio' ? true : $startVideoOff);
    }

    /* ----------------------------------------------------------------- *
     *  Modal Actions (New Direct, Group, Channel, Edit, Add Member)
     * ----------------------------------------------------------------- */

    public function startDirectChat(int $userId): void
    {
        $currentUser = auth()->user();
        $targetUser = User::find($userId);

        if (! $targetUser || ! $currentUser) {
            return;
        }

        /** @var ChatService $chatService */
        $chatService = app(ChatService::class);
        $conv = $chatService->findOrCreateDirectConversation($currentUser, $targetUser);

        $this->js("\$store.modals.close('new-chat-modal')");
        $this->selectConversation($conv->uuid);
    }

    public function createGroup(): void
    {
        $this->validate([
            'groupTitle' => ['required', 'string', 'min:2', 'max:100'],
            'groupDescription' => ['nullable', 'string', 'max:500'],
            'groupSelectedMembers' => ['required', 'array', 'min:1'],
            'groupAvatar' => ['nullable', 'image', 'max:5120'],
        ]);

        $currentUser = auth()->user();
        $avatarPath = $this->newGroupAvatarPath ?: ($this->groupAvatar ? FileUploadService::upload($this->groupAvatar, 'chat/avatars') : null);

        /** @var ChatService $chatService */
        $chatService = app(ChatService::class);
        $conv = $chatService->createGroupConversation(
            creator: $currentUser,
            title: $this->groupTitle,
            participantUserIds: $this->groupSelectedMembers,
            description: $this->groupDescription ?: null,
            avatarPath: $avatarPath
        );

        $this->groupTitle = '';
        $this->groupDescription = '';
        $this->groupSelectedMembers = [];
        $this->groupAvatar = null;
        $this->newGroupAvatarPath = null;

        $this->js("\$store.modals.close('new-group-modal')");
        $this->selectConversation($conv->uuid);
        Toast::dispatch($this, 'success', __('Group created successfully!'));
    }

    public function createChannel(): void
    {
        $this->validate([
            'channelTitle' => ['required', 'string', 'min:2', 'max:100'],
            'channelDescription' => ['nullable', 'string', 'max:500'],
            'channelAvatar' => ['nullable', 'image', 'max:5120'],
        ]);

        $currentUser = auth()->user();
        $avatarPath = $this->newChannelAvatarPath ?: ($this->channelAvatar ? FileUploadService::upload($this->channelAvatar, 'chat/avatars') : null);

        /** @var ChatService $chatService */
        $chatService = app(ChatService::class);
        $conv = $chatService->createChannel(
            creator: $currentUser,
            title: $this->channelTitle,
            subscriberUserIds: $this->channelSelectedMembers,
            description: $this->channelDescription ?: null,
            avatarPath: $avatarPath,
            isBroadcastOnly: $this->channelIsBroadcastOnly
        );

        $this->channelTitle = '';
        $this->channelDescription = '';
        $this->channelSelectedMembers = [];
        $this->channelAvatar = null;
        $this->newChannelAvatarPath = null;

        $this->js("\$store.modals.close('new-channel-modal')");
        $this->selectConversation($conv->uuid);
        Toast::dispatch($this, 'success', __('Broadcast channel created successfully!'));
    }

    public function removeGroupAvatar(): void
    {
        $this->removeAvatar = true;
        $this->editAvatar = null;
        $this->newEditAvatarPath = null;
    }

    public function updatedDirectAvatarUpload(): void
    {
        $conv = ChatConversation::find($this->activeConversationId);
        $user = auth()->user();

        if (! $conv || ! $user || ! $conv->canModifyProfile($user)) {
            Toast::dispatch($this, 'error', __('Unauthorized to update conversation profile picture.'));
            $this->directAvatarUpload = null;
            return;
        }

        $this->validate([
            'directAvatarUpload' => ['required', 'image', 'max:5120'],
        ]);

        $avatarPath = FileUploadService::upload($this->directAvatarUpload, 'chat/avatars');
        $conv->update([
            'avatar' => $avatarPath,
            'updated_by' => $user->id,
        ]);

        AuditLogService::log(
            event: 'chat_group_avatar_updated',
            description: "Updated profile picture for '{$conv->title}' (#{$conv->id})",
            auditable: $conv,
            userId: $user->id
        );

        $this->directAvatarUpload = null;
        Toast::dispatch($this, 'success', __('Profile picture updated successfully!'));
    }

    public function removeActiveConversationAvatarDirect(): void
    {
        $conv = ChatConversation::find($this->activeConversationId);
        $user = auth()->user();

        if (! $conv || ! $user || ! $conv->canModifyProfile($user)) {
            Toast::dispatch($this, 'error', __('Unauthorized to update conversation profile picture.'));
            return;
        }

        $conv->update([
            'avatar' => null,
            'updated_by' => $user->id,
        ]);

        AuditLogService::log(
            event: 'chat_group_avatar_removed',
            description: "Removed profile picture for '{$conv->title}' (#{$conv->id})",
            auditable: $conv,
            userId: $user->id
        );

        Toast::dispatch($this, 'info', __('Profile picture removed.'));
    }

    public function saveGroupProfile(): void
    {
        $conv = ChatConversation::find($this->activeConversationId);
        $user = auth()->user();

        if (! $conv || ! $user || ! $conv->canModifyProfile($user)) {
            Toast::dispatch($this, 'error', __('Unauthorized to update conversation profile.'));
            return;
        }

        $this->validate([
            'editTitle' => ['required', 'string', 'min:2', 'max:100'],
            'editDescription' => ['nullable', 'string', 'max:500'],
            'editPostingPermission' => ['required', 'in:all,admins_only,permitted_only'],
            'editAvatar' => ['nullable', 'image', 'max:5120'],
        ]);

        $avatarPath = $conv->avatar;
        if ($this->removeAvatar) {
            $avatarPath = null;
        } elseif ($this->newEditAvatarPath) {
            $avatarPath = $this->newEditAvatarPath;
        } elseif ($this->editAvatar) {
            $avatarPath = FileUploadService::upload($this->editAvatar, 'chat/avatars');
        }

        $settings = $conv->settings ?? [];
        $settings['posting_permission'] = $this->editPostingPermission;
        $settings['allowed_poster_ids'] = $this->editAllowedPosterIds;

        $conv->update([
            'title' => $this->editTitle,
            'description' => $this->editDescription ?: null,
            'avatar' => $avatarPath,
            'settings' => $settings,
            'updated_by' => $user->id,
        ]);

        AuditLogService::log(
            event: 'chat_group_profile_updated',
            description: "Updated profile & permissions for conversation '{$conv->title}' (#{$conv->id})",
            auditable: $conv,
            userId: $user->id
        );

        $this->editAvatar = null;
        $this->removeAvatar = false;
        $this->newEditAvatarPath = null;
        $this->js("\$store.modals.close('edit-group-modal')");
        Toast::dispatch($this, 'success', __('Conversation profile updated!'));
    }

    public function saveBase64Avatar(string $target, string $base64Data): void
    {
        try {
            if (preg_match('/^data:image\/(\w+);base64,/', $base64Data, $type)) {
                $data = substr($base64Data, strpos($base64Data, ',') + 1);
                $type = strtolower($type[1]);
                $data = base64_decode($data);

                if ($data === false) {
                    throw new \Exception('Invalid base64 image data');
                }

                $filename = 'avatar_' . uniqid() . '.' . ($type === 'jpeg' ? 'jpg' : ($type ?: 'png'));
                $path = 'chat/avatars/' . $filename;
                \Illuminate\Support\Facades\Storage::disk('public')->put($path, $data);

                $currentUser = auth()->user();

                if ($target === 'direct' && $this->activeConversationId) {
                    $conv = ChatConversation::find($this->activeConversationId);
                    if ($conv && $conv->canModifyProfile($currentUser)) {
                        $conv->update(['avatar' => $path, 'updated_by' => $currentUser->id]);
                        Toast::dispatch($this, 'success', __('Profile picture updated successfully!'));
                    }
                } elseif ($target === 'edit') {
                    $this->newEditAvatarPath = $path;
                    $this->removeAvatar = false;
                    Toast::dispatch($this, 'success', __('Image edited and ready to save.'));
                } elseif ($target === 'group') {
                    $this->newGroupAvatarPath = $path;
                    Toast::dispatch($this, 'success', __('Image cropped and ready.'));
                } elseif ($target === 'channel') {
                    $this->newChannelAvatarPath = $path;
                    Toast::dispatch($this, 'success', __('Image cropped and ready.'));
                }
            }
        } catch (\Throwable $e) {
            Toast::dispatch($this, 'error', __('Failed to process image: :err', ['err' => $e->getMessage()]));
        }
    }

    public function saveBase64Attachment(string $base64Data, string $filename = 'image.png'): void
    {
        try {
            if (preg_match('/^data:image\/(\w+);base64,/', $base64Data, $type)) {
                $data = substr($base64Data, strpos($base64Data, ',') + 1);
                $type = strtolower($type[1]);
                $data = base64_decode($data);

                if ($data === false) {
                    return;
                }

                $ext = $type === 'jpeg' ? 'jpg' : ($type ?: 'png');
                $tempPath = tempnam(sys_get_temp_dir(), 'chat_att_') . '.' . $ext;
                file_put_contents($tempPath, $data);

                $uploadedFile = new \Illuminate\Http\UploadedFile(
                    $tempPath,
                    $filename ?: ('edited_image_' . time() . '.' . $ext),
                    'image/' . $type,
                    null,
                    true
                );

                $this->attachments[] = $uploadedFile;
                Toast::dispatch($this, 'success', __('Edited image added to attachments.'));
            }
        } catch (\Throwable $e) {
            // handle error
        }
    }

    public function removeAttachment(int $index): void
    {
        if (isset($this->attachments[$index])) {
            unset($this->attachments[$index]);
            $this->attachments = array_values($this->attachments);
        }
    }

    public function addMembers(): void
    {
        if (! $this->activeConversationId || empty($this->addMemberSelectedIds)) {
            return;
        }

        $conv = ChatConversation::find($this->activeConversationId);
        $currentUser = auth()->user();

        /** @var ChatService $chatService */
        $chatService = app(ChatService::class);

        foreach ($this->addMemberSelectedIds as $userId) {
            $target = User::find($userId);
            if ($target) {
                $chatService->addParticipant($conv, $target, $currentUser);
            }
        }

        $this->addMemberSelectedIds = [];
        $this->js("\$store.modals.close('add-member-modal')");
        Toast::dispatch($this, 'success', __('Members added successfully.'));
    }

    public function removeMember(int $userId): void
    {
        $conv = ChatConversation::find($this->activeConversationId);
        $target = User::find($userId);
        $currentUser = auth()->user();

        if ($conv && $target && $currentUser) {
            /** @var ChatService $chatService */
            $chatService = app(ChatService::class);
            try {
                $chatService->removeParticipant($conv, $target, $currentUser);
                Toast::dispatch($this, 'info', __('Member removed.'));
            } catch (\Throwable $e) {
                Toast::dispatch($this, 'error', $e->getMessage());
            }
        }
    }

    public function makeAdmin(int $userId): void
    {
        $conv = ChatConversation::find($this->activeConversationId);
        $target = User::find($userId);
        $currentUser = auth()->user();

        if ($conv && $target && $currentUser) {
            /** @var ChatService $chatService */
            $chatService = app(ChatService::class);
            $chatService->updateParticipantRole($conv, $target, ChatParticipant::ROLE_ADMIN, $currentUser);
            Toast::dispatch($this, 'success', __(':name is now an Admin.', ['name' => $target->name]));
        }
    }

    public function dismissAdmin(int $userId): void
    {
        $conv = ChatConversation::find($this->activeConversationId);
        $target = User::find($userId);
        $currentUser = auth()->user();

        if ($conv && $target && $currentUser) {
            /** @var ChatService $chatService */
            $chatService = app(ChatService::class);
            $chatService->updateParticipantRole($conv, $target, ChatParticipant::ROLE_MEMBER, $currentUser);
            Toast::dispatch($this, 'info', __('Admin privileges revoked.'));
        }
    }

    /* ----------------------------------------------------------------- *
     *  Data Queries
     * ----------------------------------------------------------------- */

    protected function getUserConversationsQuery()
    {
        $userId = auth()->id();

        $query = ChatConversation::query()
            ->forUser($userId)
            ->with(['participants.user', 'lastMessage.user', 'lastMessage.statuses'])
            ->orderByRaw('COALESCE(last_message_at, updated_at) DESC');

        if ($this->filterTab === 'direct') {
            $query->direct();
        } elseif ($this->filterTab === 'groups') {
            $query->group();
        } elseif ($this->filterTab === 'channels') {
            $query->channel();
        }

        if (! empty(trim($this->search))) {
            $search = trim($this->search);
            $query->where(function ($q) use ($search, $userId) {
                $q->where('title', 'like', "%{$search}%")
                    ->orWhereHas('participants.user', function ($uq) use ($search, $userId) {
                        $uq->where('id', '!=', $userId)->where('name', 'like', "%{$search}%");
                    });
            });
        }

        return $query;
    }

    public function with(): array
    {
        $user = auth()->user();
        $conversations = $this->getUserConversationsQuery()->get();

        $activeConversation = $this->activeConversationId
            ? ChatConversation::with(['participants.user', 'creator'])->find($this->activeConversationId)
            : null;

        $messages = collect();
        $pinnedMessages = collect();
        if ($activeConversation && $user) {
            $messages = $activeConversation->messages()
                ->visibleForUser($user->id)
                ->with(['user', 'replyTo.user', 'attachments', 'statuses'])
                ->orderBy('created_at', 'asc')
                ->get();

            $pinnedMessages = $activeConversation->messages()
                ->pinned()
                ->visibleForUser($user->id)
                ->with(['user', 'attachments'])
                ->orderBy('pinned_at', 'desc')
                ->get();
        }

        // Active direct peer user (if 1-on-1)
        $directPeerUser = ($activeConversation && $activeConversation->isDirect())
            ? $activeConversation->otherParticipant($user)
            : null;

        // User Call History
        $callLogs = ChatCall::where('caller_id', $user->id)
            ->orWhere('receiver_id', $user->id)
            ->with(['caller', 'receiver'])
            ->orderBy('created_at', 'desc')
            ->limit(20)
            ->get();

        // Search users for modals
        $newChatUsers = User::where('id', '!=', $user->id)
            ->whereNull('deleted_at')
            ->when(! empty(trim($this->newChatSearch)), fn ($q) => $q->where('name', 'like', '%' . trim($this->newChatSearch) . '%'))
            ->limit(20)
            ->get();

        $transportDriver = (string) Setting::get('chat.transport_driver', 'hybrid');
        if ($transportDriver === 'broadcasting') {
            $pollInterval = null; // No polling overhead in pure broadcasting mode
        } elseif ($transportDriver === 'hybrid') {
            $pollInterval = '20s'; // Relaxed safety heartbeat in hybrid mode
        } else {
            $pollInterval = (string) Setting::get('chat.poll_interval', '4s'); // Active polling in polling mode
        }

        return [
            'conversations' => $conversations,
            'activeConversation' => $activeConversation,
            'directPeerUser' => $directPeerUser,
            'messages' => $messages,
            'pinnedMessages' => $pinnedMessages,
            'callLogs' => $callLogs,
            'newChatUsers' => $newChatUsers,
            'pollInterval' => $pollInterval,
            'currentUser' => $user,
            'voiceCallEnabled' => (bool) Setting::get('chat.voice_call_enabled', true),
            'videoCallEnabled' => (bool) Setting::get('chat.video_call_enabled', true),
            'requireWebsocketForCalls' => (bool) Setting::get('chat.require_websocket_for_calls', true),
            'isRealtimeSupported' => app(WebRtcCallService::class)->isRealtimeSupported(),
        ];
    }
};
?>

<div
    id="chat-root-container"
    @if ($pollInterval) wire:poll.visible.{{ $pollInterval }}="markActiveAsRead" @endif
    x-data="chatAlpine({
        currentUserId: {{ (int) (auth()->id() ?? 0) }},
        currentUserName: {{ json_encode(auth()->user()?->name ?? 'User') }},
        currentUserAvatar: {{ json_encode(auth()->user()?->avatarUrl() ?? null) }},
        activeConversationId: {{ (int) ($activeConversationId ?? 0) }}
    })"
    x-init="$watch('activeConversationId', (val) => { if (val) subscribeConversationEcho(val); })"
    x-bind:class="isFullscreen ? '!fixed !inset-0 !h-screen !w-screen !z-50 !rounded-none !border-0' : ''"
    class="flex h-[calc(100vh-8.5rem)] min-h-[550px] rounded-2xl border border-border bg-card shadow-sm overflow-hidden relative"
>
    <!-- Left Sidebar: Conversations Master List -->
    <div
        class="w-full sm:w-80 md:w-96 flex flex-col border-r border-border bg-card shrink-0 transition-all duration-300"
        :class="mobileSidebarOpen ? 'flex' : 'hidden md:flex'"
    >
        <!-- Sidebar Header -->
        <div class="p-3.5 border-b border-border flex items-center justify-between gap-2">
            <div class="flex items-center gap-2.5 min-w-0">
                <x-ui.avatar :name="$currentUser->name" :initials="$currentUser->initials()" :src="$currentUser->avatarUrl()" size="size-9 text-xs" />
                <div class="min-w-0">
                    <h2 class="text-sm font-bold text-foreground truncate">{{ __('Live Messages') }}</h2>
                    <span class="text-[11px] text-emerald-500 font-medium flex items-center gap-1">
                        <span class="h-1.5 w-1.5 rounded-full bg-emerald-500 animate-pulse"></span>
                        {{ __('Online') }}
                    </span>
                </div>
            </div>

            <!-- Action Buttons -->
            <div class="flex items-center gap-1">
                <!-- Call History Button -->
                <button
                    type="button"
                    x-on:click="$store.modals.open('call-history-modal')"
                    class="p-2 rounded-lg text-muted-foreground hover:text-foreground hover:bg-secondary transition-colors cursor-pointer"
                    title="{{ __('Call Logs & History') }}"
                >
                    <x-icon name="phone" class="h-4 w-4" />
                </button>

                <!-- New Direct Chat Button -->
                <button
                    type="button"
                    x-on:click="$store.modals.open('new-chat-modal')"
                    class="p-2 rounded-lg text-muted-foreground hover:text-foreground hover:bg-secondary transition-colors cursor-pointer"
                    title="{{ __('New Direct Message') }}"
                >
                    <x-icon name="message-square-plus" class="h-4 w-4" />
                </button>

                <!-- More Options Dropdown (New Group / Channel / Broadcast / Meetings) -->
                <x-ui.dropdown width="w-52" offset="mt-1">
                    <x-slot:trigger>
                        <button type="button" class="p-2 rounded-lg text-muted-foreground hover:text-foreground hover:bg-secondary transition-colors cursor-pointer" title="{{ __('Create & Actions') }}">
                            <x-icon name="plus-circle" class="h-4 w-4" />
                        </button>
                    </x-slot:trigger>

                    <x-ui.dropdown.item icon="users" x-on:click="open = false; $store.modals.open('new-group-modal')">
                        {{ __('New Group') }}
                    </x-ui.dropdown.item>
                    <x-ui.dropdown.item icon="radio" x-on:click="open = false; $store.modals.open('new-channel-modal')">
                        {{ __('New Channel') }}
                    </x-ui.dropdown.item>
                    <x-ui.dropdown.separator />
                    <x-ui.dropdown.item icon="video" href="{{ route('meetings.index') }}" wire:navigate>
                        {{ __('Online Meetings Hub') }}
                    </x-ui.dropdown.item>
                    <x-ui.dropdown.item icon="send" href="{{ route('admin.chat.broadcast') }}" wire:navigate>
                        {{ __('Broadcast Hub') }}
                    </x-ui.dropdown.item>
                </x-ui.dropdown>
            </div>
        </div>

        <!-- Search Bar -->
        <div class="p-2.5 border-b border-border/60">
            <x-ui.input
                wire:model.live.debounce.250ms="search"
                placeholder="{{ __('Search chats…') }}"
                icon="search"
                class="text-xs"
            />
        </div>

        <!-- Filter Tabs -->
        <div class="flex items-center gap-1 px-2.5 py-1.5 border-b border-border text-xs overflow-x-auto scrollbar-none">
            @foreach (['all' => __('All'), 'direct' => __('Direct'), 'groups' => __('Groups'), 'channels' => __('Channels')] as $tabKey => $tabLabel)
                <button
                    type="button"
                    wire:click="$set('filterTab', '{{ $tabKey }}')"
                    class="px-2.5 py-1 rounded-full text-xs font-medium whitespace-nowrap transition-colors cursor-pointer {{ $filterTab === $tabKey ? 'bg-primary text-primary-foreground font-semibold shadow-xs' : 'text-muted-foreground hover:text-foreground hover:bg-secondary' }}"
                >
                    {{ $tabLabel }}
                </button>
            @endforeach
        </div>

        <!-- Conversations List -->
        <div class="flex-1 overflow-y-auto divide-y divide-border/40 scrollbar-thin scrollbar-thumb-border">
            @forelse ($conversations as $conv)
                @php
                    $isActive = (int) $conv->id === (int) $activeConversationId;
                    $unreadCount = $conv->unreadCountFor($currentUser->id);
                    $displayName = $conv->displayNameFor($currentUser);
                    $avatarUrl = $conv->displayAvatarFor($currentUser);
                    $initials = $conv->displayInitialsFor($currentUser);
                    $lastMsg = $conv->lastMessage;
                    $otherUser = $conv->isDirect() ? $conv->otherParticipant($currentUser) : null;
                    $isPeerOnline = $otherUser ? $otherUser->isOnline() : false;
                @endphp
                <div
                    wire:key="conv-{{ $conv->id }}"
                    wire:click="selectConversation('{{ $conv->uuid }}')"
                    x-on:click="mobileSidebarOpen = false"
                    class="flex items-center gap-3 p-3 transition-colors cursor-pointer select-none {{ $isActive ? 'bg-primary/10 border-l-4 border-l-primary font-medium' : 'hover:bg-secondary/40' }}"
                >
                    <!-- Avatar with Online / Channel Dot -->
                    <div class="relative shrink-0">
                        <x-ui.avatar :name="$displayName" :initials="$initials" :src="$avatarUrl" size="size-11 text-sm" />
                        @if ($conv->isDirect())
                            <span class="absolute bottom-0 right-0 h-3 w-3 rounded-full {{ $isPeerOnline ? 'bg-emerald-500' : 'bg-zinc-400 dark:bg-zinc-600' }} ring-2 ring-card" title="{{ $otherUser?->lastSeenText() }}"></span>
                        @elseif ($conv->isChannel())
                            <span class="absolute bottom-0 right-0 h-4 w-4 rounded-full bg-sky-500 text-white flex items-center justify-center ring-1 ring-card">
                                <x-icon name="radio" class="h-2.5 w-2.5" />
                            </span>
                        @elseif ($conv->isGroup())
                            <span class="absolute bottom-0 right-0 h-4 w-4 rounded-full bg-blue-500 text-white flex items-center justify-center ring-1 ring-card">
                                <x-icon name="users" class="h-2.5 w-2.5" />
                            </span>
                        @endif
                    </div>

                    <!-- Details -->
                    <div class="min-w-0 flex-1">
                        <div class="flex items-center justify-between gap-1 mb-0.5">
                            <span class="text-xs font-semibold text-foreground truncate">{{ $displayName }}</span>
                            @if ($conv->last_message_at)
                                <span class="text-[10px] text-muted-foreground whitespace-nowrap">
                                    {{ $conv->last_message_at->shortRelativeDiffForHumans() }}
                                </span>
                            @endif
                        </div>

                        <div class="flex items-center justify-between gap-1">
                            <p class="text-[11px] text-muted-foreground truncate flex items-center gap-1">
                                @if ($lastMsg)
                                    @if ((int) $lastMsg->user_id === (int) $currentUser->id)
                                        <!-- Ticks for Outgoing Message -->
                                        @if ($lastMsg->tickStatus() === 'blue_double_tick')
                                            <span class="text-sky-500" title="{{ __('Read') }}">
                                                <x-icon name="check-check" class="h-3.5 w-3.5 inline" />
                                            </span>
                                        @elseif ($lastMsg->tickStatus() === 'double_tick')
                                            <span class="text-muted-foreground" title="{{ __('Delivered') }}">
                                                <x-icon name="check-check" class="h-3.5 w-3.5 inline" />
                                            </span>
                                        @else
                                            <span class="text-muted-foreground" title="{{ __('Sent') }}">
                                                <x-icon name="check" class="h-3.5 w-3.5 inline" />
                                            </span>
                                        @endif
                                    @endif
                                    <span class="truncate">{{ $lastMsg->body ?: __('Attachment') }}</span>
                                @else
                                    <span class="italic text-[10px]">{{ __('No messages yet') }}</span>
                                @endif
                            </p>

                            @if ($unreadCount > 0)
                                <span class="h-5 min-w-[20px] px-1.5 rounded-full bg-primary text-primary-foreground text-[10px] font-bold flex items-center justify-center shrink-0">
                                    {{ $unreadCount }}
                                </span>
                            @endif
                        </div>
                    </div>
                </div>
            @empty
                <div class="py-12 text-center text-muted-foreground text-xs p-4">
                    <x-icon name="message-square-dashed" class="h-8 w-8 mx-auto mb-2 opacity-40" />
                    <p class="font-medium">{{ __('No conversations found') }}</p>
                    <p class="text-[11px] mt-1">{{ __('Start a new direct chat, group, or broadcast channel.') }}</p>
                </div>
            @endforelse
        </div>
    </div>

    <!-- Right Pane: Active Chat Thread Window -->
    <div
        class="flex-1 flex flex-col bg-background/50 min-w-0 transition-all duration-300"
        :class="mobileSidebarOpen ? 'hidden md:flex' : 'flex'"
    >
        @if ($activeConversation)
            <!-- Active Conversation Topbar -->
            <div class="h-16 px-4 border-b border-border bg-card flex items-center justify-between gap-3 shrink-0">
                <div class="flex items-center gap-3 min-w-0">
                    <!-- Mobile Back Button -->
                    <button
                        type="button"
                        x-on:click="mobileSidebarOpen = true"
                        class="md:hidden p-1.5 rounded-lg text-muted-foreground hover:text-foreground hover:bg-secondary cursor-pointer"
                    >
                        <x-icon name="arrow-left" class="h-5 w-5" />
                    </button>

                    <!-- Avatar -->
                    <div class="relative shrink-0">
                        <x-ui.avatar
                            :name="$activeConversation->displayNameFor($currentUser)"
                            :initials="$activeConversation->displayInitialsFor($currentUser)"
                            :src="$activeConversation->displayAvatarFor($currentUser)"
                            size="size-10 text-sm"
                        />
                    </div>

                    <div class="min-w-0">
                        <h3 class="text-sm font-bold text-foreground truncate">
                            {{ $activeConversation->displayNameFor($currentUser) }}
                        </h3>
                        <!-- Real-time Typing Indicator in Header -->
                        <div x-show="typingCount > 0" class="text-[11px] text-primary font-medium flex items-center gap-1.5 animate-pulse" x-cloak>
                            <span class="flex gap-0.5 items-center">
                                <span class="h-1.5 w-1.5 rounded-full bg-primary animate-bounce"></span>
                                <span class="h-1.5 w-1.5 rounded-full bg-primary animate-bounce [animation-delay:0.15s]"></span>
                                <span class="h-1.5 w-1.5 rounded-full bg-primary animate-bounce [animation-delay:0.3s]"></span>
                            </span>
                            <span x-text="typingText"></span>
                        </div>

                        <!-- Regular Status when nobody is typing -->
                        <p x-show="typingCount === 0" class="text-[11px] text-muted-foreground truncate">
                            @if ($activeConversation->isDirect() && $directPeerUser)
                                <span class="{{ $directPeerUser->isOnline() ? 'text-emerald-500 font-semibold' : 'text-muted-foreground' }}">
                                    {{ $directPeerUser->lastSeenText() }}
                                </span>
                            @elseif ($activeConversation->isGroup())
                                <span>{{ __(':count members', ['count' => $activeConversation->participants->count()]) }}</span>
                            @elseif ($activeConversation->isChannel())
                                <span>{{ __('Broadcast Channel • :count subscribers', ['count' => $activeConversation->participants->count()]) }}</span>
                            @endif
                        </p>
                    </div>
                </div>

                <!-- Call & Options Actions -->
                <div class="flex items-center gap-1 sm:gap-1.5">
                    <!-- Live WebSocket Status Indicator -->
                    <div
                        class="hidden sm:flex items-center gap-1.5 px-2.5 py-1 rounded-full text-[11px] font-medium border transition-all"
                        :class="$store.websocket && $store.websocket.connected ? 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 border-emerald-500/20' : 'bg-amber-500/10 text-amber-600 dark:text-amber-400 border-amber-500/20'"
                        :title="$store.websocket && $store.websocket.connected ? '{{ __('WebSocket Connected (Laravel Reverb)') }}' : '{{ __('WebSocket Disconnected') }}'"
                    >
                        <span class="relative flex h-2 w-2">
                            <span x-show="$store.websocket && $store.websocket.connected" class="animate-ping absolute inline-flex h-full w-full rounded-full bg-emerald-400 opacity-75"></span>
                            <span class="relative inline-flex rounded-full h-2 w-2" :class="$store.websocket && $store.websocket.connected ? 'bg-emerald-500' : 'bg-amber-500'"></span>
                        </span>
                        <span x-text="$store.websocket && $store.websocket.connected ? '{{ __('Live') }}' : '{{ __('Offline') }}'"></span>
                    </div>

                    <!-- Sound Notification Toggle -->
                    <button
                        type="button"
                        wire:click="toggleMute"
                        class="p-2 rounded-lg text-muted-foreground hover:text-foreground hover:bg-secondary transition-colors cursor-pointer"
                        title="{{ $soundMuted ? __('Unmute notification sound') : __('Mute notification sound') }}"
                    >
                        <x-icon :name="$soundMuted ? 'volume-x' : 'volume-2'" class="h-4 w-4 {{ $soundMuted ? 'text-rose-400' : 'text-muted-foreground' }}" />
                    </button>

                    <!-- Voice Call Button (Active only when WebSocket connected) -->
                    @if ($voiceCallEnabled)
                        <button
                            type="button"
                            @click="if ($store.websocket && !$store.websocket.connected) {
                                $store.toasts?.add('warning', '{{ __('Real-time voice calling requires an active WebSocket connection. Reconnecting...') }}');
                                $store.websocket?.reconnect();
                                return;
                            } $dispatch('open-pre-call-preview', {
                                callType: 'audio',
                                isGroup: {{ $activeConversation->isDirect() ? 'false' : 'true' }},
                                peerName: @js($activeConversation->isDirect() ? ($activeConversation->otherParticipant(auth()->user())?->name ?? __('Participant')) : $activeConversation->title),
                                peerAvatar: @js($activeConversation->isDirect() ? $activeConversation->otherParticipant(auth()->user())?->avatarUrl() : null),
                                targetUserId: {{ (int) ($activeConversation->isDirect() ? ($activeConversation->otherParticipant(auth()->user())?->id ?? 0) : 0) }},
                                conversationId: {{ (int) $activeConversation->id }}
                            })"
                            class="p-2 rounded-lg transition-colors cursor-pointer"
                            :class="$store.websocket && $store.websocket.connected ? 'text-muted-foreground hover:text-foreground hover:bg-secondary' : 'opacity-40 text-muted-foreground/60 hover:bg-transparent'"
                            :title="$store.websocket && $store.websocket.connected ? '{{ $activeConversation->isDirect() ? __('Voice Call') : __('Group Voice Call') }}' : '{{ __('Voice calling requires an active WebSocket connection') }}'"
                        >
                            <x-icon name="phone" class="h-4 w-4" />
                        </button>
                    @endif

                    <!-- Video Call Button (Active only when WebSocket connected) -->
                    @if ($videoCallEnabled)
                        <button
                            type="button"
                            @click="if ($store.websocket && !$store.websocket.connected) {
                                $store.toasts?.add('warning', '{{ __('Real-time video calling requires an active WebSocket connection. Reconnecting...') }}');
                                $store.websocket?.reconnect();
                                return;
                            } $dispatch('open-pre-call-preview', {
                                callType: 'video',
                                isGroup: {{ $activeConversation->isDirect() ? 'false' : 'true' }},
                                peerName: @js($activeConversation->isDirect() ? ($activeConversation->otherParticipant(auth()->user())?->name ?? __('Participant')) : $activeConversation->title),
                                peerAvatar: @js($activeConversation->isDirect() ? $activeConversation->otherParticipant(auth()->user())?->avatarUrl() : null),
                                targetUserId: {{ (int) ($activeConversation->isDirect() ? ($activeConversation->otherParticipant(auth()->user())?->id ?? 0) : 0) }},
                                conversationId: {{ (int) $activeConversation->id }}
                            })"
                            class="p-2 rounded-lg transition-colors cursor-pointer"
                            :class="$store.websocket && $store.websocket.connected ? 'text-muted-foreground hover:text-foreground hover:bg-secondary' : 'opacity-40 text-muted-foreground/60 hover:bg-transparent'"
                            :title="$store.websocket && $store.websocket.connected ? '{{ $activeConversation->isDirect() ? __('Video Call') : __('Group Video Call') }}' : '{{ __('Video calling requires an active WebSocket connection') }}'"
                        >
                            <x-icon name="video" class="h-4 w-4" />
                        </button>
                    @endif

                    <!-- Open In New Tab -->
                    @if ($activeConversation)
                        <a
                            href="{{ route('admin.chat.index', ['conversation' => $activeConversation->uuid]) }}"
                            target="_blank"
                            rel="noopener noreferrer"
                            tabindex="-1"
                            class="p-2 rounded-lg text-muted-foreground hover:text-foreground hover:bg-secondary transition-colors cursor-pointer"
                            title="{{ __('Open chat in new tab') }}"
                        >
                            <x-icon name="external-link" class="h-4 w-4" />
                        </a>
                    @endif

                    <!-- Fullscreen View Toggle -->
                    <button
                        type="button"
                        x-on:click="toggleFullscreen()"
                        class="p-2 rounded-lg text-muted-foreground hover:text-foreground hover:bg-secondary transition-colors cursor-pointer"
                        title="{{ __('Toggle Fullscreen') }}"
                    >
                        <x-icon name="maximize-2" class="h-4 w-4" x-show="!isFullscreen" />
                        <x-icon name="minimize-2" class="h-4 w-4" x-show="isFullscreen" x-cloak />
                    </button>

                    <!-- Invite / QR Code Modal Button for Groups & Channels -->
                    @if (! $activeConversation->isDirect())
                        <button
                            type="button"
                            x-on:click="$store.modals.open('invite-modal')"
                            class="p-2 rounded-lg text-muted-foreground hover:text-foreground hover:bg-secondary transition-colors cursor-pointer"
                            title="{{ __('Invite Link & QR Code') }}"
                        >
                            <x-icon name="share-2" class="h-4 w-4" />
                        </button>
                    @endif

                    <!-- Details / Info Drawer Toggle -->
                    <button
                        type="button"
                        wire:click="$toggle('showInfoDrawer')"
                        class="p-2 rounded-lg text-muted-foreground hover:text-foreground hover:bg-secondary transition-colors cursor-pointer"
                        title="{{ __('Conversation Info & Details') }}"
                    >
                        <x-icon name="info" class="h-4 w-4" />
                    </button>
                </div>
            </div>

            <!-- WebSocket Disconnected Warning Banner -->
            <div
                x-show="$store.websocket && !$store.websocket.connected"
                x-cloak
                x-transition
                class="px-4 py-2 bg-amber-500/10 dark:bg-amber-950/30 border-b border-amber-500/20 text-xs text-amber-700 dark:text-amber-300 flex items-center justify-between gap-2 shrink-0 z-10"
            >
                <div class="flex items-center gap-2 min-w-0">
                    <span class="relative flex h-2 w-2 shrink-0">
                        <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-amber-400 opacity-75"></span>
                        <span class="relative inline-flex rounded-full h-2 w-2 bg-amber-500"></span>
                    </span>
                    <span class="truncate">{{ __('Real-time WebSocket disconnected. Audio/video calling is paused until connection is restored.') }}</span>
                </div>
                <button
                    type="button"
                    @click="$store.websocket?.reconnect()"
                    class="px-2.5 py-1 rounded bg-amber-500/20 hover:bg-amber-500/30 text-amber-900 dark:text-amber-100 font-semibold shrink-0 transition-colors cursor-pointer"
                >
                    {{ __('Reconnect') }}
                </button>
            </div>

            <!-- Ongoing Active Group Call Banner -->
            @if ($this->activeGroupCall && $activeConversation && ! $activeConversation->isDirect())
                <div class="px-4 py-2.5 bg-emerald-500/15 border-b border-emerald-500/30 flex items-center justify-between gap-3 text-xs shrink-0 z-10 animate-pulse">
                    <div class="flex items-center gap-2.5 min-w-0">
                        <div class="p-1.5 rounded-full bg-emerald-500 text-white shrink-0">
                            <x-icon name="phone-call" class="h-4 w-4 animate-bounce" />
                        </div>
                        <div class="min-w-0">
                            <p class="font-bold text-emerald-800 dark:text-emerald-300 truncate">
                                {{ __('Ongoing Group Call in Progress') }}
                            </p>
                            <p class="text-[11px] text-emerald-700/80 dark:text-emerald-400/80">
                                {{ __(':count participants active', ['count' => $this->activeGroupCall->activeParticipants()->count()]) }}
                            </p>
                        </div>
                    </div>
                    <button
                        type="button"
                        @click="$dispatch('join-group-call', { callUuid: '{{ $this->activeGroupCall->uuid }}', type: '{{ $this->activeGroupCall->type }}' })"
                        class="px-3.5 py-1.5 rounded-xl bg-emerald-600 hover:bg-emerald-700 text-white font-semibold flex items-center gap-1.5 shadow-sm transition-all cursor-pointer"
                    >
                        <x-icon name="phone" class="h-3.5 w-3.5" />
                        {{ __('Join Call') }}
                    </button>
                </div>
            @endif

            <!-- Pinned Messages Banner -->
            @if ($pinnedMessages->isNotEmpty())
                <div class="px-4 py-2 bg-amber-500/10 border-b border-amber-500/20 flex items-center justify-between gap-3 text-xs shrink-0 z-10">
                    <div class="flex items-center gap-2 min-w-0">
                        <div class="p-1 rounded bg-amber-500/20 text-amber-600 dark:text-amber-400 shrink-0">
                            <x-icon name="pin" class="h-3.5 w-3.5" />
                        </div>
                        <div class="min-w-0">
                            <p class="font-bold text-[11px] text-foreground truncate">
                                {{ __('Pinned Message') }} • <span class="text-muted-foreground font-normal">{{ $pinnedMessages->first()->user?->name }}</span>
                            </p>
                            <p class="text-[11px] text-muted-foreground truncate">
                                {{ $pinnedMessages->first()->body ?: __('Attachment: :name', ['name' => $pinnedMessages->first()->attachments->first()?->file_name ?? __('File')]) }}
                            </p>
                        </div>
                    </div>
                    <div class="flex items-center gap-1.5 shrink-0">
                        <button
                            type="button"
                            x-on:click="scrollToMessage({{ $pinnedMessages->first()->id }})"
                            class="px-2.5 py-1 rounded bg-secondary hover:bg-secondary/80 text-[10px] font-semibold text-foreground cursor-pointer"
                        >
                            {{ __('Jump') }}
                        </button>
                        @if (! $activeConversation->isGroupOrChannel() || $activeConversation->isUserAdmin($currentUser->id) || $isSuperAdmin)
                            <button
                                type="button"
                                wire:click="unpinMessage({{ $pinnedMessages->first()->id }})"
                                class="p-1 rounded hover:bg-secondary text-muted-foreground hover:text-foreground cursor-pointer"
                                title="{{ __('Unpin') }}"
                            >
                                <x-icon name="pin-off" class="h-3.5 w-3.5" />
                            </button>
                        @endif
                    </div>
                </div>
            @endif

            <!-- Messages Thread Viewport -->
            <div
                x-ref="messagesContainer"
                class="flex-1 overflow-y-auto p-4 space-y-4 scrollbar-thin scrollbar-thumb-border bg-slate-50/50 dark:bg-zinc-950/40"
            >
                @php
                    $lastDate = null;
                    $isSuperAdmin = $currentUser->hasRole('Super Administrator');
                @endphp

                @forelse ($messages as $msg)
                    @php
                        $msgDate = $msg->created_at?->format('Y-m-d');
                        $isOutgoing = (int) $msg->user_id === (int) $currentUser->id;
                        $isSystem = $msg->type === ChatMessage::TYPE_SYSTEM;
                    @endphp

                    <!-- Date Separator -->
                    @if ($msgDate !== $lastDate)
                        @php $lastDate = $msgDate; @endphp
                        <div class="flex items-center justify-center my-3">
                            <span class="px-3 py-1 rounded-full text-[10px] font-semibold uppercase tracking-wider bg-secondary/80 text-muted-foreground shadow-xs border border-border/50">
                                {{ $msg->created_at?->isToday() ? __('Today') : ($msg->created_at?->isYesterday() ? __('Yesterday') : $msg->created_at?->format('d M Y')) }}
                            </span>
                        </div>
                    @endif

                    @if ($isSystem)
                        <!-- System Notification Message -->
                        <div class="flex justify-center my-2">
                            <span class="px-3 py-1 rounded-lg text-xs text-muted-foreground bg-secondary/40 border border-border/40 text-center max-w-md">
                                {{ $msg->body }}
                            </span>
                        </div>
                    @else
                        <!-- Chat Message Bubble -->
                        <div
                            id="msg-{{ $msg->id }}"
                            wire:key="msg-{{ $msg->id }}"
                            class="flex flex-col group {{ $isOutgoing ? 'items-end' : 'items-start' }}"
                            :class="highlightedMessageId === {{ $msg->id }} ? 'ring-2 ring-amber-400 bg-amber-400/10 rounded-xl transition-all duration-500 p-1' : ''"
                        >
                            <div class="flex items-end gap-1.5 max-w-[85%] sm:max-w-[75%]">
                                @if (! $isOutgoing && ! $activeConversation->isDirect())
                                    <div wire:click="viewUserProfile({{ $msg->user_id }})" class="cursor-pointer hover:opacity-85 transition-opacity" title="{{ __('View profile') }}">
                                        <x-ui.avatar :name="$msg->user?->name" :initials="$msg->user?->initials()" :src="$msg->user?->avatarUrl()" size="size-7 text-[10px] shrink-0 mb-1" />
                                    </div>
                                @endif

                                <div class="space-y-1">
                                    <!-- Sender Name for Groups (Clickable to View Profile) -->
                                    @if (! $isOutgoing && ! $activeConversation->isDirect())
                                        <button
                                            type="button"
                                            wire:click="viewUserProfile({{ $msg->user_id }})"
                                            class="text-[11px] font-semibold text-primary px-1 hover:underline cursor-pointer text-left"
                                            title="{{ __('View :name profile & direct message', ['name' => $msg->user?->name ?? '']) }}"
                                        >
                                            {{ $msg->user?->name }}
                                        </button>
                                    @endif

                                    <div
                                        class="relative rounded-2xl px-3.5 py-2 text-xs shadow-xs {{ $isOutgoing ? 'bg-primary text-primary-foreground rounded-br-xs' : 'bg-card text-card-foreground border border-border rounded-bl-xs' }}"
                                    >
                                        <!-- Pinned indicator -->
                                        @if ($msg->is_pinned)
                                            <div class="flex items-center gap-1 text-[10px] font-bold mb-1 {{ $isOutgoing ? 'text-amber-200' : 'text-amber-600 dark:text-amber-400' }}">
                                                <x-icon name="pin" class="h-3 w-3" />
                                                <span>{{ __('Pinned') }}</span>
                                            </div>
                                        @endif

                                        <!-- Quoted Reply Snippet (WhatsApp style) -->
                                        @if ($msg->replyTo)
                                            <div
                                                x-on:click="scrollToMessage({{ $msg->replyTo->id }})"
                                                class="mb-2 p-2 rounded-lg border-l-4 text-[11px] cursor-pointer transition-opacity hover:opacity-85 select-none {{ $isOutgoing ? 'bg-black/15 border-primary-foreground/60 text-primary-foreground' : 'bg-secondary/70 border-primary text-foreground' }}"
                                            >
                                                <p class="font-semibold text-[10px] opacity-90">{{ $msg->replyTo->user?->name ?? __('User') }}</p>
                                                <p class="truncate opacity-80">{{ $msg->replyTo->body ?: __('Attachment') }}</p>
                                            </div>
                                        @endif

                                        <!-- Attachments Preview & Downloads -->
                                        @if ($msg->attachments->isNotEmpty() && ! $msg->is_deleted_for_everyone)
                                            <div class="space-y-2 mb-2">
                                                @foreach ($msg->attachments as $att)
                                                    @php
                                                        $isPdf = str_ends_with(strtolower($att->file_name), '.pdf') || str_contains($att->file_type ?? '', 'pdf');
                                                        $isImg = $att->isImage();
                                                        $isMedia = $att->isAudio() || $att->isVideo();
                                                    @endphp
                                                    @if ($isImg)
                                                        <div class="relative group/att rounded-xl overflow-hidden max-w-sm border border-black/10 shadow-xs bg-black/5">
                                                            <img
                                                                src="{{ $att->url() }}"
                                                                alt="{{ $att->file_name }}"
                                                                x-on:click="openFilePreview('{{ $att->url() }}', '{{ addslashes($att->file_name) }}', 'image', '{{ $att->formattedSize() }}', false)"
                                                                class="max-h-60 w-auto rounded-xl object-cover cursor-pointer hover:scale-[1.02] transition-transform"
                                                            />
                                                            <div class="absolute top-2 right-2 flex items-center gap-1 opacity-0 group-hover/att:opacity-100 transition-opacity">
                                                                <button
                                                                    type="button"
                                                                    x-on:click="openFilePreview('{{ $att->url() }}', '{{ addslashes($att->file_name) }}', 'image', '{{ $att->formattedSize() }}', false)"
                                                                    class="p-1.5 rounded-lg bg-black/60 text-white hover:bg-black/80 transition-colors shadow-xs cursor-pointer"
                                                                    title="{{ __('Preview Image') }}"
                                                                >
                                                                    <x-icon name="maximize-2" class="h-3.5 w-3.5" />
                                                                </button>
                                                                <a
                                                                    href="{{ $att->url() }}"
                                                                    download="{{ $att->file_name }}"
                                                                    class="p-1.5 rounded-lg bg-black/60 text-white hover:bg-black/80 transition-colors shadow-xs"
                                                                    title="{{ __('Download Image') }}"
                                                                >
                                                                    <x-icon name="download" class="h-3.5 w-3.5" />
                                                                </a>
                                                            </div>
                                                        </div>
                                                    @elseif ($isPdf)
                                                        <div class="flex items-center justify-between gap-2.5 p-2.5 rounded-xl border {{ $isOutgoing ? 'bg-black/15 border-white/20 text-primary-foreground' : 'bg-secondary/70 border-border text-foreground' }}">
                                                            <div
                                                                x-on:click="openFilePreview('{{ $att->url() }}', '{{ addslashes($att->file_name) }}', 'pdf', '{{ $att->formattedSize() }}', true)"
                                                                class="flex items-center gap-2.5 min-w-0 flex-1 cursor-pointer hover:opacity-85"
                                                            >
                                                                <div class="p-2 rounded-lg bg-rose-500/15 text-rose-500 shrink-0">
                                                                    <x-icon name="file-text" class="h-5 w-5" />
                                                                </div>
                                                                <div class="min-w-0 flex-1">
                                                                    <p class="text-xs font-semibold truncate">{{ $att->file_name }}</p>
                                                                    <p class="text-[10px] opacity-75">{{ $att->formattedSize() }} • {{ __('Click to Preview PDF') }}</p>
                                                                </div>
                                                            </div>
                                                            <div class="flex items-center gap-1 shrink-0">
                                                                <button
                                                                    type="button"
                                                                    x-on:click="openFilePreview('{{ $att->url() }}', '{{ addslashes($att->file_name) }}', 'pdf', '{{ $att->formattedSize() }}', true)"
                                                                    class="p-1.5 rounded-lg hover:bg-black/10 transition-colors cursor-pointer"
                                                                    title="{{ __('Preview PDF') }}"
                                                                >
                                                                    <x-icon name="eye" class="h-4 w-4" />
                                                                </button>
                                                                <a
                                                                    href="{{ $att->url() }}"
                                                                    download="{{ $att->file_name }}"
                                                                    class="p-1.5 rounded-lg hover:bg-black/10 transition-colors"
                                                                    title="{{ __('Download PDF') }}"
                                                                >
                                                                    <x-icon name="download" class="h-4 w-4" />
                                                                </a>
                                                            </div>
                                                        </div>
                                                    @else
                                                        <div class="flex items-center justify-between gap-2.5 p-2.5 rounded-xl border {{ $isOutgoing ? 'bg-black/15 border-white/20 text-primary-foreground' : 'bg-secondary/70 border-border text-foreground' }}">
                                                            <div
                                                                @if ($isMedia)
                                                                    x-on:click="openFilePreview('{{ $att->url() }}', '{{ addslashes($att->file_name) }}', '{{ $att->isAudio() ? 'audio' : 'video' }}', '{{ $att->formattedSize() }}', false)"
                                                                    class="flex items-center gap-2.5 min-w-0 flex-1 cursor-pointer hover:opacity-85"
                                                                @else
                                                                    class="flex items-center gap-2.5 min-w-0 flex-1"
                                                                @endif
                                                            >
                                                                <div class="p-2 rounded-lg bg-primary/15 text-primary shrink-0">
                                                                    <x-icon :name="$att->isAudio() ? 'music' : ($att->isVideo() ? 'video' : 'file-text')" class="h-5 w-5" />
                                                                </div>
                                                                <div class="min-w-0 flex-1">
                                                                    <p class="text-xs font-semibold truncate">{{ $att->file_name }}</p>
                                                                    <p class="text-[10px] opacity-75">{{ $att->formattedSize() }}</p>
                                                                </div>
                                                            </div>
                                                            <div class="flex items-center gap-1 shrink-0">
                                                                @if ($isMedia)
                                                                    <button
                                                                        type="button"
                                                                        x-on:click="openFilePreview('{{ $att->url() }}', '{{ addslashes($att->file_name) }}', '{{ $att->isAudio() ? 'audio' : 'video' }}', '{{ $att->formattedSize() }}', false)"
                                                                        class="p-1.5 rounded-lg hover:bg-black/10 transition-colors cursor-pointer"
                                                                        title="{{ __('Play / Preview') }}"
                                                                    >
                                                                        <x-icon name="play" class="h-4 w-4" />
                                                                    </button>
                                                                @endif
                                                                <a
                                                                    href="{{ $att->url() }}"
                                                                    download="{{ $att->file_name }}"
                                                                    class="p-1.5 rounded-lg hover:bg-black/10 transition-colors"
                                                                    title="{{ __('Download File') }}"
                                                                >
                                                                    <x-icon name="download" class="h-4 w-4" />
                                                                </a>
                                                            </div>
                                                        </div>
                                                    @endif
                                                @endforeach
                                            </div>
                                        @endif

                                        <!-- Text Body & Super Admin Audit View for Deleted Messages -->
                                        @if ($msg->is_deleted_for_everyone)
                                            @if ($isSuperAdmin)
                                                <div class="rounded-lg bg-rose-500/10 border border-rose-500/30 p-2 text-rose-700 dark:text-rose-300 text-xs">
                                                    <div class="flex items-center gap-1.5 font-bold mb-1">
                                                        <x-icon name="shield-alert" class="h-3.5 w-3.5 text-rose-500" />
                                                        <span>{{ __('Deleted Message (Super Admin View)') }}</span>
                                                    </div>
                                                    <p class="line-through opacity-85 break-words">
                                                        {{ $msg->metadata['original_body'] ?? $msg->body }}
                                                    </p>
                                                </div>
                                            @else
                                                <p class="italic text-muted-foreground flex items-center gap-1 opacity-75">
                                                    <x-icon name="slash" class="h-3 w-3" />
                                                    {{ __('This message was deleted') }}
                                                </p>
                                            @endif
                                        @else
                                            <p class="whitespace-pre-wrap leading-relaxed break-words">{{ $msg->body }}</p>
                                        @endif

                                        <!-- Footer: Time, Edited Badge, Ticks -->
                                        <div class="flex items-center justify-end gap-1 text-[10px] opacity-75 mt-1">
                                            @if ($msg->is_edited && ! $msg->is_deleted_for_everyone)
                                                <span class="italic">{{ __('(edited)') }}</span>
                                            @endif
                                            <span>{{ $msg->formattedTime() }}</span>

                                            @if ($isOutgoing && ! $msg->is_deleted_for_everyone)
                                                @if ($msg->tickStatus() === 'blue_double_tick')
                                                    <span class="text-sky-400 font-bold" title="{{ __('Read') }}">
                                                        <x-icon name="check-check" class="h-3.5 w-3.5 inline" />
                                                    </span>
                                                @elseif ($msg->tickStatus() === 'double_tick')
                                                    <span class="opacity-90" title="{{ __('Delivered') }}">
                                                        <x-icon name="check-check" class="h-3.5 w-3.5 inline" />
                                                    </span>
                                                @else
                                                    <span class="opacity-90" title="{{ __('Sent') }}">
                                                        <x-icon name="check" class="h-3.5 w-3.5 inline" />
                                                    </span>
                                                @endif
                                            @endif
                                        </div>
                                    </div>

                                    <!-- Emoji Reaction Badges -->
                                    @php
                                        $groupedReactions = $msg->groupedReactions($currentUser->id);
                                    @endphp
                                    @if (! empty($groupedReactions))
                                        <div class="flex flex-wrap items-center gap-1 pt-0.5 {{ $isOutgoing ? 'justify-end' : 'justify-start' }}">
                                            @foreach ($groupedReactions as $rxEmoji => $rxData)
                                                <button
                                                    type="button"
                                                    wire:click="toggleReaction({{ $msg->id }}, '{{ $rxEmoji }}')"
                                                    class="inline-flex items-center gap-1 px-1.5 py-0.5 rounded-full text-xs border transition-all cursor-pointer shadow-2xs {{ $rxData['has_reacted'] ? 'bg-primary/15 border-primary text-primary font-bold' : 'bg-card border-border text-foreground hover:bg-secondary' }}"
                                                    title="{{ implode(', ', $rxData['users']) }}"
                                                >
                                                    <span>{{ $rxEmoji }}</span>
                                                    <span class="text-[10px] font-mono">{{ $rxData['count'] }}</span>
                                                </button>
                                            @endforeach
                                        </div>
                                    @endif
                                </div>

                                <!-- Hover Action Buttons (Emoji React Bar, Reply, Pin, Edit, Delete) -->
                                @if (! $msg->is_deleted_for_everyone)
                                    <div class="opacity-0 group-hover:opacity-100 transition-opacity flex items-center gap-0.5 shrink-0">
                                        <!-- Quick Emoji Reaction Bar (Popover) -->
                                        <div x-data="{ openReact: false }" class="relative">
                                            <button
                                                type="button"
                                                @click="openReact = !openReact"
                                                class="p-1 rounded hover:bg-secondary text-muted-foreground hover:text-foreground cursor-pointer"
                                                title="{{ __('React with emoji') }}"
                                            >
                                                <x-icon name="smile" class="h-3.5 w-3.5" />
                                            </button>

                                            <div
                                                x-show="openReact"
                                                @click.outside="openReact = false"
                                                x-transition
                                                x-cloak
                                                class="absolute bottom-full mb-1 z-30 flex items-center gap-1 p-1 rounded-full border border-border bg-popover text-popover-foreground shadow-lg {{ $isOutgoing ? 'right-0' : 'left-0' }}"
                                            >
                                                @foreach (['👍', '❤️', '😂', '😮', '😢', '👏', '🔥', '🎉'] as $em)
                                                    <button
                                                        type="button"
                                                        wire:click="toggleReaction({{ $msg->id }}, '{{ $em }}')"
                                                        @click="openReact = false"
                                                        class="p-1 text-base hover:scale-125 transition-transform cursor-pointer"
                                                    >
                                                        {{ $em }}
                                                    </button>
                                                @endforeach
                                            </div>
                                        </div>

                                        <button
                                            type="button"
                                            wire:click="setReplyTo({{ $msg->id }})"
                                            class="p-1 rounded hover:bg-secondary text-muted-foreground hover:text-foreground cursor-pointer"
                                            title="{{ __('Reply') }}"
                                        >
                                            <x-icon name="reply" class="h-3.5 w-3.5" />
                                        </button>

                                        <x-ui.dropdown width="w-44" offset="mt-1">
                                            <x-slot:trigger>
                                                <button type="button" class="p-1 rounded hover:bg-secondary text-muted-foreground hover:text-foreground cursor-pointer">
                                                    <x-icon name="more-horizontal" class="h-3.5 w-3.5" />
                                                </button>
                                            </x-slot:trigger>

                                            <x-ui.dropdown.item icon="reply" wire:click="setReplyTo({{ $msg->id }})">
                                                {{ __('Reply') }}
                                            </x-ui.dropdown.item>

                                            <!-- Pin / Unpin message -->
                                            @if ($msg->is_pinned)
                                                @if (! $activeConversation->isGroupOrChannel() || $activeConversation->isUserAdmin($currentUser->id) || $isSuperAdmin)
                                                    <x-ui.dropdown.item icon="pin-off" wire:click="unpinMessage({{ $msg->id }})">
                                                        {{ __('Unpin message') }}
                                                    </x-ui.dropdown.item>
                                                @endif
                                            @else
                                                @if (! $activeConversation->isGroupOrChannel() || $activeConversation->isUserAdmin($currentUser->id) || $isSuperAdmin)
                                                    <x-ui.dropdown.item icon="pin" wire:click="pinMessage({{ $msg->id }})">
                                                        {{ __('Pin message') }}
                                                    </x-ui.dropdown.item>
                                                @endif
                                            @endif

                                            @if ($isOutgoing)
                                                <x-ui.dropdown.item icon="edit-3" wire:click="startEdit({{ $msg->id }})">
                                                    {{ __('Edit message') }}
                                                </x-ui.dropdown.item>
                                            @endif

                                            <x-ui.dropdown.item icon="trash" wire:click="promptDeleteMessage({{ $msg->id }}, 'for_me')">
                                                {{ __('Delete for me') }}
                                            </x-ui.dropdown.item>

                                            @if ($isOutgoing || $activeConversation->isUserAdmin($currentUser->id) || $isSuperAdmin)
                                                <x-ui.dropdown.item icon="trash-2" danger wire:click="promptDeleteMessage({{ $msg->id }}, 'for_everyone')">
                                                    {{ __('Delete for everyone') }}
                                                </x-ui.dropdown.item>
                                            @endif
                                        </x-ui.dropdown>
                                    </div>
                                @endif
                            </div>
                        </div>
                    @endif
                @empty
                    <div class="h-full flex flex-col items-center justify-center text-center text-muted-foreground py-16">
                        <div class="h-16 w-16 rounded-full bg-primary/10 text-primary flex items-center justify-center mb-3">
                            <x-icon name="message-circle" class="h-8 w-8" />
                        </div>
                        <h4 class="font-bold text-foreground text-sm">{{ __('Start the conversation!') }}</h4>
                        <p class="text-xs text-muted-foreground mt-1 max-w-sm">
                            {{ __('Send your first message or media attachment below. Messages are synchronized in real time.') }}
                        </p>
                    </div>
                @endforelse
            </div>

            <!-- Edit Message Banner -->
            @if ($editingMessageId)
                <div class="px-4 py-2 bg-amber-500/10 border-t border-amber-500/20 flex items-center justify-between text-xs text-amber-700 dark:text-amber-300">
                    <div class="flex items-center gap-2">
                        <x-icon name="edit-2" class="h-4 w-4" />
                        <span>{{ __('Editing Message') }}</span>
                    </div>
                    <button type="button" wire:click="cancelEdit" class="hover:underline font-semibold cursor-pointer">
                        {{ __('Cancel') }}
                    </button>
                </div>
            @endif

            <!-- Quote Reply Preview Banner -->
            @if ($replyingToMessage)
                <div class="px-4 py-2 bg-primary/5 border-t border-primary/20 flex items-center justify-between text-xs">
                    <div class="flex items-center gap-2.5 min-w-0">
                        <x-icon name="reply" class="h-4 w-4 text-primary shrink-0" />
                        <div class="min-w-0">
                            <p class="font-semibold text-primary text-[11px] truncate">
                                {{ __('Replying to :name', ['name' => $replyingToMessage->user?->name]) }}
                            </p>
                            <p class="text-muted-foreground text-[11px] truncate">
                                {{ $replyingToMessage->body ?: __('Attachment') }}
                            </p>
                        </div>
                    </div>
                    <button type="button" wire:click="cancelReply" class="p-1 text-muted-foreground hover:text-foreground cursor-pointer">
                        <x-icon name="x" class="h-4 w-4" />
                    </button>
                </div>
            @endif

            <!-- Attachment Staging Preview -->
            @if (! empty($attachments))
                <div class="px-4 py-2 bg-secondary/40 border-t border-border flex items-center gap-2 overflow-x-auto">
                    @foreach ($attachments as $index => $att)
                        <div class="relative rounded-lg border border-border bg-card p-1.5 flex items-center gap-2 text-xs shrink-0 shadow-xs">
                            <x-icon name="file" class="h-4 w-4 text-primary shrink-0" />
                            <span class="truncate max-w-[130px] font-medium">{{ $att->getClientOriginalName() }}</span>
                            <button
                                type="button"
                                wire:click="removeAttachment({{ $index }})"
                                class="p-1 rounded-md text-muted-foreground hover:text-rose-500 hover:bg-rose-500/10 cursor-pointer"
                                title="{{ __('Remove attachment') }}"
                            >
                                <x-icon name="x" class="h-3.5 w-3.5" />
                            </button>
                        </div>
                    @endforeach
                </div>
            @endif

            <!-- Floating Typing Indicator Bubble -->
            <div
                x-show="typingCount > 0"
                x-transition:enter="transition ease-out duration-200"
                x-transition:enter-start="opacity-0 translate-y-2"
                x-transition:enter-end="opacity-100 translate-y-0"
                x-transition:leave="transition ease-in duration-150"
                x-transition:leave-start="opacity-100 translate-y-0"
                x-transition:leave-end="opacity-0 translate-y-2"
                x-cloak
                class="px-4 py-1.5 bg-card/95 backdrop-blur-md border-t border-border flex items-center gap-2 text-xs text-muted-foreground shrink-0"
            >
                <div class="flex items-center gap-1 text-primary">
                    <span class="h-1.5 w-1.5 rounded-full bg-primary animate-bounce"></span>
                    <span class="h-1.5 w-1.5 rounded-full bg-primary animate-bounce [animation-delay:0.15s]"></span>
                    <span class="h-1.5 w-1.5 rounded-full bg-primary animate-bounce [animation-delay:0.3s]"></span>
                </div>
                <span class="font-medium text-foreground truncate" x-text="typingText"></span>
            </div>

            <!-- Composer Input Bar -->
            @if ($activeConversation->canPost($currentUser))
                <div class="p-3 bg-card border-t border-border flex items-end gap-2 relative">
                    <!-- Emoji Picker Button & Popover -->
                    <div class="relative">
                        <button
                            type="button"
                            x-on:click="showEmojiPicker = !showEmojiPicker"
                            class="p-2 rounded-lg text-muted-foreground hover:text-foreground hover:bg-secondary cursor-pointer"
                            title="{{ __('Emoji Picker') }}"
                        >
                            <x-icon name="smile" class="h-5 w-5" />
                        </button>

                        <!-- Emoji Popover -->
                        <div
                            x-show="showEmojiPicker"
                            x-on:click.outside="showEmojiPicker = false"
                            x-cloak
                            class="absolute bottom-12 left-0 z-40 w-72 rounded-xl border border-border bg-card p-3 shadow-2xl space-y-2"
                        >
                            <div class="flex items-center justify-between text-xs font-semibold border-b border-border pb-1.5">
                                <span>{{ __('Quick Emojis') }}</span>
                                <button type="button" x-on:click="showEmojiPicker = false" class="text-muted-foreground hover:text-foreground">
                                    <x-icon name="x" class="h-3.5 w-3.5" />
                                </button>
                            </div>
                            <div class="grid grid-cols-8 gap-1 text-lg max-h-48 overflow-y-auto">
                                @foreach (['😀','😃','😄','😁','😆','😅','😂','🤣','😊','😇','🙂','😉','😍','🥰','😘','😋','😎','🥳','🤩','🤔','😐','🤐','😴','😷','👍','👎','👏','🙌','🙏','💪','🔥','🎉','❤️','💖','✨','⭐','📚','🎓','💼','🚀'] as $emoji)
                                    <button
                                        type="button"
                                        x-on:click="insertEmoji('{{ $emoji }}')"
                                        class="h-8 w-8 rounded hover:bg-secondary flex items-center justify-center cursor-pointer transition-transform hover:scale-125"
                                    >
                                        {{ $emoji }}
                                    </button>
                                @endforeach
                            </div>
                        </div>
                    </div>

                    <!-- File Attachment Upload -->
                    <label class="p-2 rounded-lg text-muted-foreground hover:text-foreground hover:bg-secondary cursor-pointer" title="{{ __('Attach File / Image') }}">
                        <x-icon name="paperclip" class="h-5 w-5" />
                        <input type="file" wire:model="attachments" multiple class="hidden" />
                    </label>

                    <!-- Edit Image & Send Button -->
                    <label class="p-2 rounded-lg text-muted-foreground hover:text-foreground hover:bg-secondary cursor-pointer" title="{{ __('Edit & Crop Image before sending') }}">
                        <x-icon name="crop" class="h-5 w-5" />
                        <input
                            type="file"
                            accept="image/*"
                            class="hidden"
                            @change="
                                const file = $event.target.files[0];
                                if (file) {
                                    const reader = new FileReader();
                                    reader.onload = (e) => {
                                        window.dispatchEvent(new CustomEvent('open-image-editor', {
                                            detail: { src: e.target.result, target: 'attachment', aspectRatio: 'free' }
                                        }));
                                    };
                                    reader.readAsDataURL(file);
                                    $event.target.value = '';
                                }
                            "
                        />
                    </label>

                    <!-- Text Composer -->
                    <div class="flex-1">
                        @if ($editingMessageId)
                            <textarea
                                wire:model="editingText"
                                rows="1"
                                wire:keydown.enter.prevent="saveEdit"
                                placeholder="{{ __('Edit your message… (Press Enter to save)') }}"
                                class="w-full rounded-xl border border-border bg-background px-3 py-2 text-sm focus:border-primary focus:ring-1 focus:ring-primary resize-none max-h-32"
                            ></textarea>
                        @else
                            <textarea
                                x-ref="composerInput"
                                wire:model="messageText"
                                @input="onComposerInput()"
                                rows="1"
                                @keydown.enter.exact.prevent="
                                    clearOwnTyping();
                                    if ($el.value.trim() || ($wire.attachments && $wire.attachments.length > 0)) {
                                        playMessageSentChime();
                                        $wire.sendMessage();
                                    }
                                "
                                placeholder="{{ __('Type a message… (Press Enter to send, Shift+Enter for newline)') }}"
                                class="w-full rounded-xl border border-border bg-background px-3 py-2 text-sm focus:border-primary focus:ring-1 focus:ring-primary resize-none max-h-32 leading-relaxed"
                            ></textarea>
                        @endif
                    </div>

                    <!-- Send Button -->
                    @if ($editingMessageId)
                        <x-ui.button wire:click="saveEdit" variant="default" size="sm" icon="check" class="mb-0.5">
                            {{ __('Save') }}
                        </x-ui.button>
                    @else
                        <button
                            type="button"
                            @click="
                                clearOwnTyping();
                                if (($refs.composerInput && $refs.composerInput.value.trim()) || ($wire.attachments && $wire.attachments.length > 0)) {
                                    playMessageSentChime();
                                    $wire.sendMessage();
                                }
                            "
                            class="h-9 w-9 rounded-xl bg-primary text-primary-foreground flex items-center justify-center hover:opacity-90 transition-transform active:scale-95 cursor-pointer shrink-0 shadow-xs mb-0.5"
                            title="{{ __('Send Message') }}"
                        >
                            <x-icon name="send" class="h-4 w-4" />
                        </button>
                    @endif
                </div>
            @else
                <div class="p-3 bg-secondary/30 border-t border-border text-center text-xs text-muted-foreground italic">
                    <x-icon name="lock" class="h-4 w-4 inline mr-1" />
                    {{ __('Only authorized members can post messages in this conversation.') }}
                </div>
            @endif
        @else
            <!-- Empty State when no conversation selected -->
            <div class="flex-1 flex flex-col items-center justify-center text-center p-8 text-muted-foreground">
                <div class="h-20 w-20 rounded-full bg-primary/10 text-primary flex items-center justify-center mb-4">
                    <x-icon name="message-square" class="h-10 w-10" />
                </div>
                <h3 class="text-base font-bold text-foreground">{{ __('Select a chat to begin') }}</h3>
                <p class="text-xs text-muted-foreground mt-1 max-w-sm">
                    {{ __('Choose an existing chat from the left sidebar, or create a new direct message, group, or broadcast channel.') }}
                </p>
            </div>
        @endif
    </div>

    <!-- Right Drawer: Conversation Information & Participants -->
    @if ($showInfoDrawer && $activeConversation)
        <div class="w-72 md:w-80 border-l border-border bg-card flex flex-col shrink-0 animate-in slide-in-from-right duration-200">
            <div class="p-4 border-b border-border flex items-center justify-between">
                <h3 class="font-bold text-sm text-foreground">{{ __('Conversation Details') }}</h3>
                <button type="button" wire:click="$set('showInfoDrawer', false)" class="text-muted-foreground hover:text-foreground cursor-pointer">
                    <x-icon name="x" class="h-4 w-4" />
                </button>
            </div>

            <div class="flex-1 overflow-y-auto p-4 space-y-5 scrollbar-thin scrollbar-thumb-border text-xs">
                <!-- Group/Channel/Direct Profile Card -->
                <div class="text-center space-y-3">
                    <div class="relative inline-block mx-auto group">
                        <x-ui.avatar
                            :name="$activeConversation->displayNameFor($currentUser)"
                            :src="$activeConversation->displayAvatarFor($currentUser)"
                            size="size-20 text-xl mx-auto shadow-md"
                        />

                        @if ($activeConversation->canModifyProfile($currentUser) && ! $activeConversation->isDirect())
                            <!-- Quick Change Overlay Button -->
                            <label
                                for="drawer-group-avatar-upload"
                                class="absolute inset-0 rounded-full bg-black/50 text-white opacity-0 group-hover:opacity-100 flex flex-col items-center justify-center cursor-pointer transition-opacity text-[10px] font-medium"
                                title="{{ __('Change / Crop display picture') }}"
                            >
                                <x-icon name="camera" class="h-5 w-5 mb-0.5" />
                                <span>{{ __('Change') }}</span>
                            </label>
                            <input
                                type="file"
                                id="drawer-group-avatar-upload"
                                accept="image/*"
                                class="hidden"
                                @change="
                                    const file = $event.target.files[0];
                                    if (file) {
                                        const reader = new FileReader();
                                        reader.onload = (e) => {
                                            window.dispatchEvent(new CustomEvent('open-image-editor', {
                                                detail: { src: e.target.result, target: 'direct', aspectRatio: 'circle' }
                                            }));
                                        };
                                        reader.readAsDataURL(file);
                                        $event.target.value = '';
                                    }
                                "
                            />
                        @endif
                    </div>

                    <div wire:loading wire:target="directAvatarUpload" class="text-[11px] text-primary font-semibold flex items-center justify-center gap-1.5 animate-pulse">
                        <x-icon name="loader" class="h-3.5 w-3.5 animate-spin" />
                        <span>{{ __('Uploading new picture…') }}</span>
                    </div>
                    @error('directAvatarUpload')
                        <p class="text-[11px] text-rose-500 font-medium">{{ $message }}</p>
                    @enderror

                    <div>
                        <h4 class="font-bold text-base text-foreground">{{ $activeConversation->displayNameFor($currentUser) }}</h4>
                        <span class="text-[10px] px-2 py-0.5 rounded-full bg-secondary text-muted-foreground font-semibold uppercase tracking-wider">
                            {{ $activeConversation->isChannel() ? __('Channel') : ($activeConversation->isGroup() ? __('Group') : __('Direct')) }}
                        </span>
                    </div>
                    
                    @if ($activeConversation->isDirect() && $directPeerUser)
                        <div class="p-3 rounded-xl border border-border bg-secondary/30 text-left space-y-1.5 mt-3">
                            <p><strong class="text-muted-foreground">{{ __('Email:') }}</strong> {{ $directPeerUser->email }}</p>
                            @if ($directPeerUser->phone)
                                <p><strong class="text-muted-foreground">{{ __('Phone:') }}</strong> {{ $directPeerUser->phone }}</p>
                            @endif
                            <p><strong class="text-muted-foreground">{{ __('Status:') }}</strong> <span class="{{ $directPeerUser->isOnline() ? 'text-emerald-500 font-semibold' : 'text-muted-foreground' }}">{{ $directPeerUser->lastSeenText() }}</span></p>
                        </div>
                    @else
                        @if ($activeConversation->description)
                            <p class="text-xs text-muted-foreground">{{ $activeConversation->description }}</p>
                        @endif

                        @if ($activeConversation->canModifyProfile($currentUser))
                            <div class="pt-1 flex items-center justify-center gap-1.5 flex-wrap">
                                <label
                                    for="drawer-group-avatar-upload"
                                    class="inline-flex items-center gap-1 px-2 py-1 rounded-lg border border-border bg-card hover:bg-secondary text-foreground text-xs font-semibold cursor-pointer shadow-2xs transition-colors"
                                >
                                    <x-icon name="camera" class="h-3.5 w-3.5 text-primary" />
                                    <span>{{ $activeConversation->avatar ? __('Change Picture') : __('Upload Picture') }}</span>
                                </label>

                                @if ($activeConversation->avatar)
                                    <button
                                        type="button"
                                        wire:click="removeActiveConversationAvatarDirect"
                                        class="inline-flex items-center gap-1 px-2 py-1 rounded-lg border border-rose-500/30 bg-rose-500/10 hover:bg-rose-500/20 text-rose-600 dark:text-rose-400 text-xs font-semibold cursor-pointer shadow-2xs transition-colors"
                                    >
                                        <x-icon name="trash" class="h-3.5 w-3.5" />
                                        <span>{{ __('Remove') }}</span>
                                    </button>
                                @endif

                                <x-ui.button
                                    variant="outline"
                                    size="xs"
                                    icon="edit"
                                    x-on:click="$store.modals.open('edit-group-modal')"
                                >
                                    {{ __('Edit Rules') }}
                                </x-ui.button>
                            </div>
                        @endif
                    @endif
                </div>

                <!-- Members Section (Groups & Channels) -->
                @if (! $activeConversation->isDirect())
                    <div class="space-y-3 pt-3 border-t border-border">
                        <div class="flex items-center justify-between">
                            <span class="text-xs font-semibold text-foreground uppercase tracking-wider">
                                {{ __('Members (:count)', ['count' => $activeConversation->participants->count()]) }}
                            </span>
                            @if ($activeConversation->isUserAdmin($currentUser->id))
                                <button
                                    type="button"
                                    x-on:click="$store.modals.open('add-member-modal')"
                                    class="text-xs font-semibold text-primary hover:underline cursor-pointer flex items-center gap-1"
                                >
                                    <x-icon name="user-plus" class="h-3 w-3" />
                                    {{ __('Add') }}
                                </button>
                            @endif
                        </div>

                        <div class="space-y-2">
                            @foreach ($activeConversation->participants as $part)
                                <div class="flex items-center justify-between gap-2 p-1.5 rounded-lg hover:bg-secondary/40 text-xs">
                                    <div class="flex items-center gap-2 min-w-0 cursor-pointer hover:opacity-85" wire:click="viewUserProfile({{ $part->user_id }})" title="{{ __('Click to view details & direct message') }}">
                                        <x-ui.avatar :name="$part->user?->name" :initials="$part->user?->initials()" :src="$part->user?->avatarUrl()" size="size-7 text-[10px]" />
                                        <div class="min-w-0">
                                            <p class="font-semibold text-foreground truncate hover:text-primary hover:underline">{{ $part->user?->name }}</p>
                                            <span class="text-[10px] text-muted-foreground">{{ ucfirst($part->role) }}</span>
                                        </div>
                                    </div>

                                    @if ($activeConversation->isUserAdmin($currentUser->id) && (int) $part->user_id !== (int) $currentUser->id)
                                        <x-ui.dropdown width="w-40" offset="mt-1">
                                            <x-slot:trigger>
                                                <button type="button" class="p-1 text-muted-foreground hover:text-foreground cursor-pointer">
                                                    <x-icon name="more-vertical" class="h-3.5 w-3.5" />
                                                </button>
                                            </x-slot:trigger>

                                            @if ($part->isMember())
                                                <x-ui.dropdown.item icon="shield" wire:click="makeAdmin({{ $part->user_id }})">
                                                    {{ __('Make Admin') }}
                                                </x-ui.dropdown.item>
                                            @else
                                                <x-ui.dropdown.item icon="shield-off" wire:click="dismissAdmin({{ $part->user_id }})">
                                                    {{ __('Dismiss Admin') }}
                                                </x-ui.dropdown.item>
                                            @endif

                                            <x-ui.dropdown.item icon="user-minus" danger wire:click="removeMember({{ $part->user_id }})">
                                                {{ __('Remove') }}
                                            </x-ui.dropdown.item>
                                        </x-ui.dropdown>
                                    @endif
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endif
            </div>
        </div>
    @endif

    <!-- 1. Modal: Delete Message Confirmation -->
    <x-ui.modal name="delete-confirm-modal" max-width="max-w-md" title="{{ __('Confirm Delete Message') }}">
        <div class="space-y-4 text-center">
            <div class="h-12 w-12 rounded-full bg-rose-500/10 text-rose-500 flex items-center justify-center mx-auto">
                <x-icon name="alert-triangle" class="h-6 w-6" />
            </div>

            <div class="space-y-1">
                <h4 class="font-bold text-sm text-foreground">{{ __('Are you sure you want to delete this message?') }}</h4>
                <p class="text-xs text-muted-foreground">
                    @if ($confirmDeleteMode === 'for_everyone')
                        {{ __('This message will be deleted for all participants in the conversation. This action cannot be undone.') }}
                    @else
                        {{ __('This message will be removed from your chat history only.') }}
                    @endif
                </p>
            </div>

            <div class="flex justify-end gap-2 pt-2">
                <x-ui.button x-on:click="$store.modals.close('delete-confirm-modal')" variant="secondary">
                    {{ __('Cancel') }}
                </x-ui.button>
                <x-ui.button wire:click="executeDeleteMessage" variant="destructive" icon="trash-2">
                    {{ __('Delete Message') }}
                </x-ui.button>
            </div>
        </div>
    </x-ui.modal>

    <!-- 2. Modal: Pre-Call Device & Permissions Preview (Camera, Mic & Speaker) -->
    <div
        x-data="chatPreCallPreviewAlpine()"
        x-show="isOpen"
        x-cloak
        x-transition:enter="transition ease-out duration-300"
        x-transition:enter-start="opacity-0"
        x-transition:enter-end="opacity-100"
        x-transition:leave="transition ease-in duration-200"
        x-transition:leave-start="opacity-100"
        x-transition:leave-end="opacity-0"
        class="fixed inset-0 z-50 flex items-center justify-center p-3 sm:p-4 bg-background/80 backdrop-blur-md overflow-y-auto"
        role="dialog"
        aria-modal="true"
    >
        <div
            x-show="isOpen"
            x-transition:enter="transition ease-out duration-300"
            x-transition:enter-start="opacity-0 scale-95 -translate-y-2"
            x-transition:enter-end="opacity-100 scale-100 translate-y-0"
            x-transition:leave="transition ease-in duration-200"
            x-transition:leave-start="opacity-100 scale-100 translate-y-0"
            x-transition:leave-end="opacity-0 scale-95 -translate-y-2"
            @click.outside="cancelPreview()"
            class="w-full max-w-lg rounded-3xl border border-border bg-card shadow-2xl overflow-hidden flex flex-col my-auto"
        >
            <!-- Modal Header -->
            <div class="px-5 py-4 border-b border-border flex items-center justify-between gap-3 bg-secondary/30">
                <div class="flex items-center gap-3 min-w-0">
                    <div class="p-2.5 rounded-2xl" :class="callType === 'video' ? 'bg-primary/10 text-primary' : 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400'">
                        <x-icon name="video" class="h-5 w-5" x-show="callType === 'video'" />
                        <x-icon name="phone-call" class="h-5 w-5" x-show="callType === 'audio'" />
                    </div>
                    <div class="min-w-0">
                        <h3 class="text-sm font-bold text-foreground truncate" x-text="callType === 'video' ? '{{ __('Ready for Video Call?') }}' : '{{ __('Ready for Voice Call?') }}'"></h3>
                        <p class="text-xs text-muted-foreground truncate" x-text="'{{ __('Calling:') }} ' + peerName"></p>
                    </div>
                </div>

                <button
                    type="button"
                    @click="cancelPreview()"
                    class="h-8 w-8 rounded-lg flex items-center justify-center text-muted-foreground hover:text-foreground hover:bg-secondary transition-colors cursor-pointer"
                    aria-label="{{ __('Close') }}"
                >
                    <x-icon name="x" class="h-4 w-4" />
                </button>
            </div>

            <!-- Modal Body / Device Preview -->
            <div class="p-5 space-y-4 max-h-[75vh] overflow-y-auto">
                <!-- A. Video Call Stage (16:9 Camera Feed & Camera Toggles) -->
                <div x-show="callType === 'video'" class="space-y-3">
                    <div class="relative w-full aspect-video rounded-2xl bg-zinc-950 border border-zinc-800 overflow-hidden flex items-center justify-center shadow-inner">
                        <!-- Loading skeleton -->
                        <div x-show="isLoadingMedia" class="absolute inset-0 flex flex-col items-center justify-center space-y-2 bg-zinc-950/80 z-20">
                            <x-icon name="refresh-cw" class="h-6 w-6 animate-spin text-primary" />
                            <span class="text-xs text-zinc-400 font-medium">{{ __('Accessing camera & microphone…') }}</span>
                        </div>

                        <!-- Video stream preview -->
                        <video
                            x-ref="preCallVideo"
                            autoplay
                            playsinline
                            muted
                            class="w-full h-full object-cover transition-transform"
                            :class="[(!videoOff && !isLoadingMedia) ? 'block' : 'hidden', isMirrored ? '-scale-x-100' : '']"
                        ></video>

                        <!-- Camera Off placeholder -->
                        <div
                            x-show="videoOff && !isLoadingMedia"
                            class="flex flex-col items-center justify-center space-y-3 p-4 text-center z-10"
                        >
                            <div class="relative">
                                <div class="absolute -inset-3 rounded-full bg-primary/20 animate-pulse"></div>
                                <x-ui.avatar :name="$currentUser->name" :initials="$currentUser->initials()" :src="$currentUser->avatarUrl()" size="size-20 text-2xl border-2 border-border shadow-xl" />
                            </div>
                            <div>
                                <p class="text-xs font-semibold text-zinc-200">{{ __('Camera is turned off') }}</p>
                                <p class="text-[11px] text-zinc-400 mt-0.5" x-text="micMuted ? '{{ __('Microphone is muted') }}' : '{{ __('Microphone active') }}'"></p>
                            </div>
                        </div>

                        <!-- Floating Device Controls Toolbar -->
                        <div class="absolute bottom-3 inset-x-0 flex items-center justify-center gap-3 z-30">
                            <!-- Mic Button -->
                            <button
                                type="button"
                                @click="toggleMic()"
                                :class="micMuted ? 'bg-rose-600 text-white hover:bg-rose-700' : (audioLevel > 5 ? 'bg-emerald-600 text-white ring-2 ring-emerald-400' : 'bg-zinc-800/90 text-zinc-100 hover:bg-zinc-700/90 border border-zinc-700')"
                                class="h-11 w-11 rounded-full flex items-center justify-center transition-all shadow-lg hover:scale-105 cursor-pointer backdrop-blur-md"
                                :title="micMuted ? '{{ __('Unmute Microphone') }}' : '{{ __('Mute Microphone') }}'"
                            >
                                <x-icon name="mic" class="h-5 w-5" x-show="!micMuted" />
                                <x-icon name="mic-off" class="h-5 w-5" x-show="micMuted" />
                            </button>

                            <!-- Camera Button -->
                            <button
                                type="button"
                                @click="toggleVideo()"
                                :class="videoOff ? 'bg-rose-600 text-white hover:bg-rose-700' : 'bg-zinc-800/90 text-zinc-100 hover:bg-zinc-700/90 border border-zinc-700'"
                                class="h-11 w-11 rounded-full flex items-center justify-center transition-all shadow-lg hover:scale-105 cursor-pointer backdrop-blur-md"
                                :title="videoOff ? '{{ __('Turn Camera On') }}' : '{{ __('Turn Camera Off') }}'"
                            >
                                <x-icon name="video" class="h-5 w-5" x-show="!videoOff" />
                                <x-icon name="video-off" class="h-5 w-5" x-show="videoOff" />
                            </button>

                            <!-- Mirror Toggle -->
                            <button
                                type="button"
                                @click="toggleMirror()"
                                class="h-11 w-11 rounded-full flex items-center justify-center transition-all shadow-lg hover:scale-105 cursor-pointer backdrop-blur-md bg-zinc-800/90 text-zinc-100 hover:bg-zinc-700/90 border border-zinc-700"
                                :title="isMirrored ? '{{ __('Disable Mirror Mode') }}' : '{{ __('Enable Mirror Mode') }}'"
                            >
                                <x-icon name="flip-horizontal" class="h-5 w-5" />
                            </button>
                        </div>

                        <!-- Live Mic Volume Activity Indicator Badge on Top Left -->
                        <div
                            class="absolute top-3 left-3 flex items-center gap-2 px-2.5 py-1 rounded-full bg-zinc-900/80 border border-zinc-700/60 backdrop-blur-md text-[11px] text-zinc-200 z-30"
                        >
                            <span class="h-2 w-2 rounded-full" :class="micMuted ? 'bg-rose-500' : (audioLevel > 5 ? 'bg-emerald-500 animate-ping' : 'bg-emerald-500')"></span>
                            <span x-text="micMuted ? '{{ __('Muted') }}' : '{{ __('Mic Active') }}'"></span>
                            <div x-show="!micMuted" class="flex items-center gap-0.5 h-3 ml-1">
                                <span class="w-0.5 rounded-full bg-emerald-400 transition-all duration-75" :style="`height: ${Math.max(3, audioLevel * 0.15)}px`"></span>
                                <span class="w-0.5 rounded-full bg-emerald-400 transition-all duration-75" :style="`height: ${Math.max(3, audioLevel * 0.3)}px`"></span>
                                <span class="w-0.5 rounded-full bg-emerald-400 transition-all duration-75" :style="`height: ${Math.max(3, audioLevel * 0.18)}px`"></span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- B. Voice Call Stage (Pure Audio & Mic Preview, No Camera) -->
                <div x-show="callType === 'audio'" class="space-y-4">
                    <div class="relative w-full py-8 px-4 rounded-2xl bg-gradient-to-b from-emerald-950/20 via-card to-card border border-border flex flex-col items-center justify-center text-center space-y-5 shadow-xs">
                        <div x-show="isLoadingMedia" class="flex flex-col items-center justify-center space-y-2 py-4">
                            <x-icon name="refresh-cw" class="h-6 w-6 animate-spin text-emerald-500" />
                            <span class="text-xs text-muted-foreground font-medium">{{ __('Accessing microphone…') }}</span>
                        </div>

                        <div x-show="!isLoadingMedia" class="space-y-4 flex flex-col items-center">
                            <!-- Pulsing Voice Avatar -->
                            <div class="relative">
                                <div
                                    class="absolute -inset-4 rounded-full bg-emerald-500/20 transition-transform duration-100"
                                    :class="(!micMuted && audioLevel > 5) ? 'scale-125 animate-pulse' : 'scale-100'"
                                ></div>
                                <div class="relative">
                                    <x-ui.avatar :name="$currentUser->name" :initials="$currentUser->initials()" :src="$currentUser->avatarUrl()" size="size-24 text-2xl border-4 border-emerald-500/50 shadow-xl" />
                                </div>
                            </div>

                            <div>
                                <h4 class="text-sm font-bold text-foreground">{{ __('Voice Call Mode') }}</h4>
                                <p class="text-xs text-muted-foreground mt-0.5">{{ __('Only microphone access will be used for this call.') }}</p>
                            </div>

                            <!-- Live Microphone Waveform / Volume Level Meter -->
                            <div class="flex items-center gap-3 px-4 py-2.5 rounded-2xl bg-secondary/50 border border-border w-full max-w-xs justify-between">
                                <div class="flex items-center gap-2 text-xs font-semibold" :class="micMuted ? 'text-rose-500' : 'text-emerald-600 dark:text-emerald-400'">
                                    <x-icon name="mic" class="h-4 w-4 shrink-0" x-show="!micMuted" />
                                    <x-icon name="mic-off" class="h-4 w-4 shrink-0" x-show="micMuted" />
                                    <span x-text="micMuted ? '{{ __('Microphone is muted') }}' : '{{ __('Microphone connected') }}'"></span>
                                </div>

                                <div x-show="!micMuted" class="flex items-center gap-1 h-5">
                                    <span class="w-1 rounded-full bg-emerald-500 transition-all duration-75" :style="`height: ${Math.max(4, audioLevel * 0.2)}px`"></span>
                                    <span class="w-1 rounded-full bg-emerald-500 transition-all duration-75" :style="`height: ${Math.max(4, audioLevel * 0.35)}px`"></span>
                                    <span class="w-1 rounded-full bg-emerald-500 transition-all duration-75" :style="`height: ${Math.max(4, audioLevel * 0.5)}px`"></span>
                                    <span class="w-1 rounded-full bg-emerald-500 transition-all duration-75" :style="`height: ${Math.max(4, audioLevel * 0.3)}px`"></span>
                                    <span class="w-1 rounded-full bg-emerald-500 transition-all duration-75" :style="`height: ${Math.max(4, audioLevel * 0.15)}px`"></span>
                                </div>
                            </div>

                            <!-- Mic Mute / Unmute Toggle Button -->
                            <div>
                                <button
                                    type="button"
                                    @click="toggleMic()"
                                    :class="micMuted ? 'bg-rose-600 text-white hover:bg-rose-700' : 'bg-secondary text-foreground hover:bg-secondary/80 border border-border'"
                                    class="inline-flex items-center gap-2 px-4 py-2 rounded-full text-xs font-semibold transition-all shadow-xs cursor-pointer"
                                >
                                    <x-icon name="mic" class="h-4 w-4" x-show="!micMuted" />
                                    <x-icon name="mic-off" class="h-4 w-4" x-show="micMuted" />
                                    <span x-text="micMuted ? '{{ __('Unmute Microphone') }}' : '{{ __('Mute Microphone') }}'"></span>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- C. Device Selectors Section (Microphone, Camera, Speaker) -->
                <div class="space-y-3 pt-1 border-t border-border/50 text-xs">
                    <!-- Microphone Selector -->
                    <div class="space-y-1.5" x-show="audioInputs.length > 0">
                        <label class="font-semibold text-muted-foreground flex items-center gap-1.5">
                            <x-icon name="mic" class="h-3.5 w-3.5" />
                            <span>{{ __('Microphone') }}</span>
                        </label>
                        <select
                            x-model="selectedAudioInput"
                            @change="onAudioInputChange()"
                            class="w-full px-3 py-2 rounded-xl bg-secondary/40 border border-input text-xs focus:ring-2 focus:ring-primary focus:outline-none"
                        >
                            <template x-for="mic in audioInputs" :key="mic.deviceId">
                                <option :value="mic.deviceId" x-text="mic.label"></option>
                            </template>
                        </select>
                    </div>

                    <!-- Camera Selector (Video calls) -->
                    <div class="space-y-1.5" x-show="callType === 'video' && videoInputs.length > 0">
                        <label class="font-semibold text-muted-foreground flex items-center gap-1.5">
                            <x-icon name="video" class="h-3.5 w-3.5" />
                            <span>{{ __('Camera') }}</span>
                        </label>
                        <select
                            x-model="selectedVideoInput"
                            @change="onVideoInputChange()"
                            class="w-full px-3 py-2 rounded-xl bg-secondary/40 border border-input text-xs focus:ring-2 focus:ring-primary focus:outline-none"
                        >
                            <template x-for="cam in videoInputs" :key="cam.deviceId">
                                <option :value="cam.deviceId" x-text="cam.label"></option>
                            </template>
                        </select>
                    </div>

                    <!-- Speaker Selector & Sound Test -->
                    <div class="space-y-1.5" x-show="audioOutputs.length > 0">
                        <div class="flex items-center justify-between">
                            <label class="font-semibold text-muted-foreground flex items-center gap-1.5">
                                <x-icon name="volume-2" class="h-3.5 w-3.5" />
                                <span>{{ __('Speaker / Output') }}</span>
                            </label>
                            <button
                                type="button"
                                @click="testSpeakerSound()"
                                class="text-primary hover:underline flex items-center gap-1 font-medium text-[11px] cursor-pointer"
                            >
                                <x-icon name="play" class="h-3 w-3" x-show="!isTestingSpeaker" />
                                <x-icon name="loader-2" class="h-3 w-3 animate-spin" x-show="isTestingSpeaker" />
                                <span>{{ __('Test Audio Chime') }}</span>
                            </button>
                        </div>
                        <select
                            x-model="selectedAudioOutput"
                            @change="onAudioOutputChange()"
                            class="w-full px-3 py-2 rounded-xl bg-secondary/40 border border-input text-xs focus:ring-2 focus:ring-primary focus:outline-none"
                        >
                            <template x-for="spk in audioOutputs" :key="spk.deviceId">
                                <option :value="spk.deviceId" x-text="spk.label"></option>
                            </template>
                        </select>
                    </div>
                </div>

                <!-- Hardware Access Error / Warning Box -->
                <div
                    x-show="permissionError"
                    x-transition
                    class="p-3.5 rounded-2xl border border-amber-500/30 bg-amber-500/10 dark:bg-amber-950/30 text-amber-800 dark:text-amber-300 text-xs space-y-2"
                >
                    <div class="flex items-start gap-2.5">
                        <x-icon name="alert-triangle" class="h-4 w-4 shrink-0 text-amber-600 dark:text-amber-400 mt-0.5" />
                        <div class="flex-1">
                            <p class="font-semibold text-xs">{{ __('Device Access Notice') }}</p>
                            <p class="text-[11px] opacity-90 mt-0.5" x-text="permissionError"></p>
                        </div>
                    </div>
                    <div class="flex justify-end">
                        <button
                            type="button"
                            @click="retryPermissions()"
                            class="inline-flex items-center gap-1 px-2.5 py-1 rounded-md bg-amber-500 hover:bg-amber-600 text-white font-medium text-[11px] transition-colors cursor-pointer shadow-xs"
                        >
                            <x-icon name="refresh-cw" class="h-3 w-3" />
                            {{ __('Retry Permissions') }}
                        </button>
                    </div>
                </div>
            </div>

            <!-- Modal Footer -->
            <div class="px-5 py-3.5 border-t border-border flex items-center justify-between gap-3 bg-secondary/20">
                <x-ui.button type="button" @click="cancelPreview()" variant="secondary">
                    {{ __('Cancel') }}
                </x-ui.button>

                <div class="flex items-center gap-2">
                    <button
                        type="button"
                        @click="startCallNow()"
                        class="inline-flex items-center gap-2 rounded-xl bg-primary text-primary-foreground hover:opacity-90 px-4 py-2 text-xs font-bold transition-all shadow-md cursor-pointer active:scale-95"
                    >
                        <x-icon name="video" class="h-4 w-4" x-show="callType === 'video'" />
                        <x-icon name="phone" class="h-4 w-4" x-show="callType === 'audio'" />
                        <span x-text="callType === 'video' ? '{{ __('Start Video Call') }}' : '{{ __('Start Voice Call') }}'"></span>
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- 3. Modal: User Call History / Logs -->
    <x-ui.modal name="call-history-modal" max-width="max-w-md" title="{{ __('Call History & Logs') }}">
        <div class="space-y-3">
            <div class="max-h-72 overflow-y-auto divide-y divide-border space-y-1 scrollbar-thin">
                @forelse ($callLogs as $cLog)
                    @php
                        $isOutgoing = (int) $cLog->caller_id === (int) $currentUser->id;
                        $otherUser = $isOutgoing ? $cLog->receiver : $cLog->caller;
                        $isMissed = in_array($cLog->status, [ChatCall::STATUS_MISSED, ChatCall::STATUS_REJECTED]);
                    @endphp
                    <div class="flex items-center justify-between gap-3 p-2.5 rounded-lg hover:bg-secondary/40 text-xs">
                        <div class="flex items-center gap-2.5 min-w-0">
                            <div class="p-2 rounded-lg {{ $isMissed ? 'bg-rose-500/10 text-rose-500' : ($cLog->isVideo() ? 'bg-blue-500/10 text-blue-500' : 'bg-emerald-500/10 text-emerald-500') }} shrink-0">
                                <x-icon :name="$isOutgoing ? 'phone-outgoing' : ($isMissed ? 'phone-missed' : 'phone-incoming')" class="h-4 w-4" />
                            </div>
                            <div class="min-w-0">
                                <p class="font-bold text-foreground truncate">{{ $otherUser?->name ?? __('Unknown') }}</p>
                                <p class="text-[10px] text-muted-foreground flex items-center gap-1">
                                    <span>{{ $cLog->created_at?->shortRelativeDiffForHumans() }}</span>
                                    <span>•</span>
                                    <span class="{{ $isMissed ? 'text-rose-500 font-semibold' : '' }}">
                                        {{ $isMissed ? __('Missed') : $cLog->formattedDuration() }}
                                    </span>
                                </p>
                            </div>
                        </div>

                        @if ($otherUser)
                            <button
                                type="button"
                                wire:click="startDirectChat({{ $otherUser->id }})"
                                class="p-2 rounded-lg bg-primary/10 text-primary hover:bg-primary/20 transition-colors cursor-pointer"
                                title="{{ __('Open Chat / Call Back') }}"
                            >
                                <x-icon name="phone" class="h-3.5 w-3.5" />
                            </button>
                        @endif
                    </div>
                @empty
                    <p class="text-center py-8 text-xs text-muted-foreground">{{ __('No recent calls found.') }}</p>
                @endforelse
            </div>
        </div>
    </x-ui.modal>

    <!-- 4. Modal: New Direct Chat -->
    <x-ui.modal name="new-chat-modal" max-width="max-w-md" title="{{ __('Start New Direct Chat') }}">
        <div class="space-y-4">
            <x-ui.input
                wire:model.live.debounce.250ms="newChatSearch"
                placeholder="{{ __('Search user by name or email…') }}"
                icon="search"
            />

            <div class="max-h-60 overflow-y-auto divide-y divide-border space-y-1 scrollbar-thin">
                @forelse ($newChatUsers as $u)
                    <div
                        wire:click="startDirectChat({{ $u->id }})"
                        class="flex items-center gap-3 p-2.5 rounded-lg hover:bg-secondary cursor-pointer transition-colors"
                    >
                        <x-ui.avatar :name="$u->name" :initials="$u->initials()" :src="$u->avatarUrl()" size="size-8 text-xs" />
                        <div class="min-w-0 flex-1">
                            <p class="text-xs font-semibold text-foreground truncate">{{ $u->name }}</p>
                            <p class="text-[11px] text-muted-foreground truncate">{{ $u->email }}</p>
                        </div>
                    </div>
                @empty
                    <p class="text-center py-6 text-xs text-muted-foreground">{{ __('No users found.') }}</p>
                @endforelse
            </div>
        </div>
    </x-ui.modal>

    <!-- 5. Modal: New Group -->
    <x-ui.modal name="new-group-modal" max-width="max-w-md" title="{{ __('Create New Group') }}">
        <div class="space-y-4">
            <!-- Group Avatar Upload -->
            <div>
                <label class="block text-xs font-semibold text-foreground mb-1">{{ __('Group Display Picture (Optional)') }}</label>
                <div class="flex items-center gap-3">
                    <div class="h-12 w-12 rounded-full bg-secondary border border-border flex items-center justify-center overflow-hidden shrink-0">
                        @if ($newGroupAvatarPath)
                            <img src="{{ asset('storage/' . $newGroupAvatarPath) }}" class="h-full w-full object-cover" alt="Avatar" />
                        @elseif ($groupAvatar)
                            <img src="{{ $groupAvatar->temporaryUrl() }}" class="h-full w-full object-cover" alt="Avatar" />
                        @else
                            <x-icon name="users" class="h-6 w-6 text-muted-foreground" />
                        @endif
                    </div>
                    <div class="flex-1 min-w-0">
                        <label
                            class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-primary/10 text-primary hover:bg-primary/20 text-xs font-semibold cursor-pointer transition-colors"
                        >
                            <x-icon name="upload" class="h-3.5 w-3.5" />
                            <span>{{ __('Upload & Crop Image') }}</span>
                            <input
                                type="file"
                                accept="image/*"
                                class="hidden"
                                @change="
                                    const file = $event.target.files[0];
                                    if (file) {
                                        const reader = new FileReader();
                                        reader.onload = (e) => {
                                            window.dispatchEvent(new CustomEvent('open-image-editor', {
                                                detail: { src: e.target.result, target: 'group', aspectRatio: 'circle' }
                                            }));
                                        };
                                        reader.readAsDataURL(file);
                                        $event.target.value = '';
                                    }
                                "
                            />
                        </label>
                    </div>
                </div>
                @error('groupAvatar') <span class="text-xs text-rose-500">{{ $message }}</span> @enderror
            </div>

            <div>
                <label class="block text-xs font-semibold text-foreground mb-1">{{ __('Group Title') }}</label>
                <x-ui.input wire:model="groupTitle" placeholder="{{ __('e.g. UPSC Batch 2026') }}" />
                @error('groupTitle') <span class="text-xs text-rose-500">{{ $message }}</span> @enderror
            </div>

            <div>
                <label class="block text-xs font-semibold text-foreground mb-1">{{ __('Description (Optional)') }}</label>
                <x-ui.textarea wire:model="groupDescription" rows="2" placeholder="{{ __('Purpose of this group…') }}" />
            </div>

            <div>
                <label class="block text-xs font-semibold text-foreground mb-1">{{ __('Select Members') }}</label>
                <div class="max-h-40 overflow-y-auto space-y-1 rounded-lg border border-border p-2">
                    @foreach ($newChatUsers as $u)
                        <label class="flex items-center gap-2 text-xs p-1 rounded hover:bg-secondary cursor-pointer select-none">
                            <input type="checkbox" wire:model="groupSelectedMembers" value="{{ $u->id }}" class="rounded border-border text-primary" />
                            <span>{{ $u->name }} ({{ $u->email }})</span>
                        </label>
                    @endforeach
                </div>
                @error('groupSelectedMembers') <span class="text-xs text-rose-500">{{ $message }}</span> @enderror
            </div>

            <div class="flex justify-end gap-2 pt-2">
                <x-ui.button x-on:click="$store.modals.close('new-group-modal')" variant="secondary">
                    {{ __('Cancel') }}
                </x-ui.button>
                <x-ui.button wire:click="createGroup" variant="default" icon="check">
                    {{ __('Create Group') }}
                </x-ui.button>
            </div>
        </div>
    </x-ui.modal>

    <!-- 6. Modal: New Broadcast Channel -->
    <x-ui.modal name="new-channel-modal" max-width="max-w-md" title="{{ __('Create Broadcast Channel') }}">
        <div class="space-y-4">
            <!-- Channel Avatar Upload -->
            <div>
                <label class="block text-xs font-semibold text-foreground mb-1">{{ __('Channel Display Picture (Optional)') }}</label>
                <div class="flex items-center gap-3">
                    <div class="h-12 w-12 rounded-full bg-secondary border border-border flex items-center justify-center overflow-hidden shrink-0">
                        @if ($newChannelAvatarPath)
                            <img src="{{ asset('storage/' . $newChannelAvatarPath) }}" class="h-full w-full object-cover" alt="Avatar" />
                        @elseif ($channelAvatar)
                            <img src="{{ $channelAvatar->temporaryUrl() }}" class="h-full w-full object-cover" alt="Avatar" />
                        @else
                            <x-icon name="radio" class="h-6 w-6 text-muted-foreground" />
                        @endif
                    </div>
                    <div class="flex-1 min-w-0">
                        <label
                            class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-primary/10 text-primary hover:bg-primary/20 text-xs font-semibold cursor-pointer transition-colors"
                        >
                            <x-icon name="upload" class="h-3.5 w-3.5" />
                            <span>{{ __('Upload & Crop Image') }}</span>
                            <input
                                type="file"
                                accept="image/*"
                                class="hidden"
                                @change="
                                    const file = $event.target.files[0];
                                    if (file) {
                                        const reader = new FileReader();
                                        reader.onload = (e) => {
                                            window.dispatchEvent(new CustomEvent('open-image-editor', {
                                                detail: { src: e.target.result, target: 'channel', aspectRatio: 'circle' }
                                            }));
                                        };
                                        reader.readAsDataURL(file);
                                        $event.target.value = '';
                                    }
                                "
                            />
                        </label>
                    </div>
                </div>
                @error('channelAvatar') <span class="text-xs text-rose-500">{{ $message }}</span> @enderror
            </div>

            <div>
                <label class="block text-xs font-semibold text-foreground mb-1">{{ __('Channel Name') }}</label>
                <x-ui.input wire:model="channelTitle" placeholder="{{ __('e.g. Official Announcements') }}" />
                @error('channelTitle') <span class="text-xs text-rose-500">{{ $message }}</span> @enderror
            </div>

            <div>
                <label class="block text-xs font-semibold text-foreground mb-1">{{ __('Channel Description') }}</label>
                <x-ui.textarea wire:model="channelDescription" rows="2" placeholder="{{ __('Official news, circulars, and updates.') }}" />
            </div>

            <div class="flex items-center justify-between p-3 rounded-lg border border-border bg-secondary/30">
                <div class="space-y-0.5">
                    <span class="text-xs font-semibold text-foreground">{{ __('Broadcast Only Mode') }}</span>
                    <p class="text-[11px] text-muted-foreground">{{ __('Only admins can send messages; subscribers can read.') }}</p>
                </div>
                <x-ui.switch wire:model="channelIsBroadcastOnly" />
            </div>

            <div class="flex justify-end gap-2 pt-2">
                <x-ui.button x-on:click="$store.modals.close('new-channel-modal')" variant="secondary">
                    {{ __('Cancel') }}
                </x-ui.button>
                <x-ui.button wire:click="createChannel" variant="default" icon="check">
                    {{ __('Create Channel') }}
                </x-ui.button>
            </div>
        </div>
    </x-ui.modal>

    <!-- 7. Modal: Add Members -->
    <x-ui.modal name="add-member-modal" max-width="max-w-md" title="{{ __('Add Members to Conversation') }}">
        <div class="space-y-4">
            <div class="max-h-60 overflow-y-auto space-y-1 rounded-lg border border-border p-2">
                @foreach ($newChatUsers as $u)
                    <label class="flex items-center gap-2 text-xs p-1.5 rounded hover:bg-secondary cursor-pointer select-none">
                        <input type="checkbox" wire:model="addMemberSelectedIds" value="{{ $u->id }}" class="rounded border-border text-primary" />
                        <span>{{ $u->name }} ({{ $u->email }})</span>
                    </label>
                @endforeach
            </div>

            <div class="flex justify-end gap-2 pt-2">
                <x-ui.button x-on:click="$store.modals.close('add-member-modal')" variant="secondary">
                    {{ __('Cancel') }}
                </x-ui.button>
                <x-ui.button wire:click="addMembers" variant="default" icon="check">
                    {{ __('Add Selected') }}
                </x-ui.button>
            </div>
        </div>
    </x-ui.modal>

    <!-- 8. Modal: Edit Group Profile & Posting Permissions -->
    @if ($activeConversation && ! $activeConversation->isDirect())
        <x-ui.modal name="edit-group-modal" max-width="max-w-md" title="{{ __('Edit Conversation Profile & Permissions') }}">
            <div class="space-y-4">
                <!-- Display Picture Upload & Preview -->
                <div class="p-3.5 rounded-xl border border-border bg-secondary/30 space-y-3">
                    <label class="block text-xs font-semibold text-foreground">{{ __('Group / Channel Display Picture') }}</label>
                    <div class="flex items-center gap-3">
                        <div class="relative shrink-0">
                            @if (! $removeAvatar && $newEditAvatarPath)
                                <img src="{{ asset('storage/' . $newEditAvatarPath) }}" class="h-16 w-16 rounded-full object-cover border-2 border-primary shadow-xs" alt="Preview" />
                            @elseif (! $removeAvatar && $editAvatar)
                                <img src="{{ $editAvatar->temporaryUrl() }}" class="h-16 w-16 rounded-full object-cover border-2 border-primary shadow-xs" alt="Preview" />
                            @elseif (! $removeAvatar && $activeConversation->avatar)
                                <img src="{{ $activeConversation->displayAvatarFor($currentUser) }}" class="h-16 w-16 rounded-full object-cover border-2 border-border shadow-xs" alt="Current Avatar" />
                            @else
                                <div class="h-16 w-16 rounded-full bg-secondary border-2 border-dashed border-border flex items-center justify-center text-muted-foreground text-xs font-bold">
                                    {{ $activeConversation->displayInitialsFor($currentUser) }}
                                </div>
                            @endif
                        </div>

                        <div class="flex-1 space-y-1.5 min-w-0">
                            <div class="flex items-center gap-2 flex-wrap">
                                <label
                                    class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-primary text-primary-foreground text-xs font-semibold hover:opacity-90 cursor-pointer shadow-xs transition-opacity"
                                >
                                    <x-icon name="crop" class="h-3.5 w-3.5" />
                                    <span>{{ __('Upload & Edit Picture') }}</span>
                                    <input
                                        type="file"
                                        accept="image/*"
                                        class="hidden"
                                        @change="
                                            const file = $event.target.files[0];
                                            if (file) {
                                                const reader = new FileReader();
                                                reader.onload = (e) => {
                                                    window.dispatchEvent(new CustomEvent('open-image-editor', {
                                                        detail: { src: e.target.result, target: 'edit', aspectRatio: 'circle' }
                                                    }));
                                                };
                                                reader.readAsDataURL(file);
                                                $event.target.value = '';
                                            }
                                        "
                                    />
                                </label>

                                @if (($activeConversation->avatar && ! $removeAvatar) || $editAvatar || $newEditAvatarPath)
                                    <button
                                        type="button"
                                        wire:click="removeGroupAvatar"
                                        class="inline-flex items-center gap-1 px-2.5 py-1.5 rounded-lg border border-rose-500/30 bg-rose-500/10 text-rose-600 dark:text-rose-400 text-xs font-semibold hover:bg-rose-500/20 cursor-pointer transition-colors"
                                    >
                                        <x-icon name="trash-2" class="h-3.5 w-3.5" />
                                        <span>{{ __('Remove') }}</span>
                                    </button>
                                @endif
                            </div>
                            <p class="text-[10px] text-muted-foreground">{{ __('JPG, PNG, GIF, WebP up to 5MB. Opens image editor.') }}</p>
                        </div>
                    </div>

                    @error('editAvatar')
                        <p class="text-xs text-rose-500">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label class="block text-xs font-semibold text-foreground mb-1">{{ __('Conversation Title') }}</label>
                    <x-ui.input wire:model="editTitle" placeholder="{{ __('Title') }}" />
                    @error('editTitle') <span class="text-xs text-rose-500">{{ $message }}</span> @enderror
                </div>

                <div>
                    <label class="block text-xs font-semibold text-foreground mb-1">{{ __('Description') }}</label>
                    <x-ui.textarea wire:model="editDescription" rows="2" placeholder="{{ __('Description') }}" />
                </div>

                <div>
                    <label class="block text-xs font-semibold text-foreground mb-1">{{ __('Posting Permissions') }}</label>
                    <select wire:model="editPostingPermission" class="w-full rounded-lg border border-border bg-card px-3 py-2 text-xs text-foreground focus:ring-1 focus:ring-primary outline-none">
                        <option value="all">{{ __('Everyone in conversation can post') }}</option>
                        <option value="admins_only">{{ __('Only Admins & Owners can post') }}</option>
                        <option value="permitted_only">{{ __('Only specific permitted members can post') }}</option>
                    </select>
                </div>

                @if ($editPostingPermission === 'permitted_only')
                    <div>
                        <label class="block text-xs font-semibold text-foreground mb-1">{{ __('Permitted Posters') }}</label>
                        <div class="max-h-36 overflow-y-auto space-y-1 rounded-lg border border-border p-2">
                            @foreach ($activeConversation->participants as $p)
                                <label class="flex items-center gap-2 text-xs p-1 rounded hover:bg-secondary cursor-pointer select-none">
                                    <input type="checkbox" wire:model="editAllowedPosterIds" value="{{ $p->user_id }}" class="rounded border-border text-primary" />
                                    <span>{{ $p->user?->name }}</span>
                                </label>
                            @endforeach
                        </div>
                    </div>
                @endif

                <div class="flex justify-end gap-2 pt-2">
                    <x-ui.button x-on:click="$store.modals.close('edit-group-modal')" variant="secondary">
                        {{ __('Cancel') }}
                    </x-ui.button>
                    <x-ui.button wire:click="saveGroupProfile" variant="default" icon="check">
                        {{ __('Save Changes') }}
                    </x-ui.button>
                </div>
            </div>
        </x-ui.modal>
    @endif

    <!-- 9. Modal: Share & Join via Invite Link / QR Code -->
    @if ($activeConversation && ! $activeConversation->isDirect())
        @php
            $inviteUrl = $activeConversation->invite_url;
        @endphp
        <x-ui.modal name="invite-modal" max-width="max-w-md" title="{{ __('Share & Invite to :title', ['title' => $activeConversation->title]) }}">
            <div class="space-y-5 text-center">
                <!-- QR Code Box -->
                <div class="p-4 bg-white rounded-2xl border border-border shadow-xs inline-block mx-auto">
                    <div class="h-44 w-44 flex items-center justify-center mx-auto bg-slate-50 border border-slate-200 rounded-xl overflow-hidden p-2">
                        <img
                            src="https://api.qrserver.com/v1/create-qr-code/?size=180x180&data={{ urlencode($inviteUrl) }}"
                            alt="QR Code"
                            class="h-full w-full object-contain"
                        />
                    </div>
                    <p class="text-[11px] text-zinc-600 font-medium mt-2">{{ __('Scan with smartphone camera to join directly') }}</p>
                </div>

                <!-- Invite Link Bar -->
                <div class="space-y-1.5 text-left">
                    <label class="block text-xs font-semibold text-foreground">{{ __('Sharable Invite Link') }}</label>
                    <div class="flex items-center gap-2">
                        <input
                            type="text"
                            readonly
                            value="{{ $inviteUrl }}"
                            class="flex-1 rounded-lg border border-border bg-secondary/50 px-3 py-2 text-xs font-mono select-all outline-none"
                        />
                        <x-ui.button
                            x-on:click="navigator.clipboard.writeText('{{ $inviteUrl }}'); copiedInvite = true; setTimeout(() => copiedInvite = false, 2000)"
                            variant="default"
                            size="sm"
                            icon="copy"
                        >
                            <span x-text="copiedInvite ? '{{ __('Copied!') }}' : '{{ __('Copy') }}'"></span>
                        </x-ui.button>
                    </div>
                </div>

                <!-- Quick Share External Channels -->
                <div class="pt-2 border-t border-border">
                    <p class="text-xs text-muted-foreground mb-3">{{ __('Or share directly to apps:') }}</p>
                    <div class="flex items-center justify-center gap-3">
                        <a
                            href="https://wa.me/?text={{ urlencode(__('Join our :title conversation on Live Chat: :url', ['title' => $activeConversation->title, 'url' => $inviteUrl])) }}"
                            target="_blank"
                            class="p-2.5 rounded-xl bg-emerald-500/10 text-emerald-600 hover:bg-emerald-500/20 transition-colors flex items-center gap-1.5 text-xs font-semibold"
                        >
                            <x-icon name="smartphone" class="h-4 w-4" />
                            <span>{{ __('WhatsApp') }}</span>
                        </a>

                        <a
                            href="https://t.me/share/url?url={{ urlencode($inviteUrl) }}&text={{ urlencode(__('Join :title', ['title' => $activeConversation->title])) }}"
                            target="_blank"
                            class="p-2.5 rounded-xl bg-sky-500/10 text-sky-600 hover:bg-sky-500/20 transition-colors flex items-center gap-1.5 text-xs font-semibold"
                        >
                            <x-icon name="send" class="h-4 w-4" />
                            <span>{{ __('Telegram') }}</span>
                        </a>

                        <a
                            href="mailto:?subject={{ urlencode(__('Invitation to join :title', ['title' => $activeConversation->title])) }}&body={{ urlencode(__('You have been invited to join :title. Click here to join: :url', ['title' => $activeConversation->title, 'url' => $inviteUrl])) }}"
                            class="p-2.5 rounded-xl bg-blue-500/10 text-blue-600 hover:bg-blue-500/20 transition-colors flex items-center gap-1.5 text-xs font-semibold"
                        >
                            <x-icon name="mail" class="h-4 w-4" />
                            <span>{{ __('Email') }}</span>
                        </a>
                    </div>
                </div>
            </div>
        </x-ui.modal>
    @endif

    <!-- 7. Modal: File, Image & PDF Interactive Previewer with Download -->
    <div
        x-show="previewModal.open"
        x-on:keydown.escape.window="closeFilePreview()"
        x-cloak
        class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/80 backdrop-blur-md animate-in fade-in duration-150"
    >
        <div
            x-on:click.outside="closeFilePreview()"
            class="relative w-full max-w-4xl max-h-[90vh] bg-card rounded-2xl border border-border shadow-2xl flex flex-col overflow-hidden animate-in zoom-in-95 duration-150"
        >
            <!-- Topbar -->
            <div class="px-4 py-3 border-b border-border flex items-center justify-between gap-3 bg-secondary/30 shrink-0">
                <div class="min-w-0 flex items-center gap-2">
                    <x-icon name="file-text" class="h-4 w-4 text-primary shrink-0" />
                    <span class="text-xs font-bold text-foreground truncate" x-text="previewModal.name"></span>
                    <span class="text-[10px] text-muted-foreground" x-text="'(' + previewModal.size + ')'"></span>
                </div>

                <div class="flex items-center gap-2 shrink-0">
                    <a
                        :href="previewModal.url"
                        :download="previewModal.name"
                        class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-primary text-primary-foreground text-xs font-semibold hover:opacity-90 transition-opacity shadow-xs"
                    >
                        <x-icon name="download" class="h-3.5 w-3.5" />
                        <span>{{ __('Download') }}</span>
                    </a>
                    <a
                        :href="previewModal.url"
                        target="_blank"
                        class="p-1.5 rounded-lg text-muted-foreground hover:text-foreground hover:bg-secondary transition-colors"
                        title="{{ __('Open original in new tab') }}"
                    >
                        <x-icon name="external-link" class="h-4 w-4" />
                    </a>
                    <button
                        type="button"
                        x-on:click="closeFilePreview()"
                        class="p-1.5 rounded-lg text-muted-foreground hover:text-foreground hover:bg-secondary transition-colors cursor-pointer"
                        title="{{ __('Close') }}"
                    >
                        <x-icon name="x" class="h-4 w-4" />
                    </button>
                </div>
            </div>

            <!-- Viewer Body -->
            <div class="flex-1 overflow-auto p-4 flex items-center justify-center bg-zinc-950/40 min-h-[300px]">
                <template x-if="previewModal.type === 'image'">
                    <img :src="previewModal.url" :alt="previewModal.name" class="max-h-[70vh] max-w-full rounded-xl object-contain shadow-md mx-auto" />
                </template>

                <template x-if="previewModal.isPdf || previewModal.type === 'pdf'">
                    <iframe :src="previewModal.url" class="w-full h-[70vh] rounded-xl border border-border bg-white shadow-md"></iframe>
                </template>

                <template x-if="previewModal.type === 'video'">
                    <video :src="previewModal.url" controls autoplay class="max-h-[70vh] w-full rounded-xl shadow-md bg-black"></video>
                </template>

                <template x-if="previewModal.type === 'audio'">
                    <div class="p-8 text-center space-y-4 w-full max-w-md">
                        <div class="h-16 w-16 rounded-full bg-primary/10 text-primary flex items-center justify-center mx-auto">
                            <x-icon name="music" class="h-8 w-8" />
                        </div>
                        <audio :src="previewModal.url" controls autoplay class="w-full"></audio>
                    </div>
                </template>
            </div>
        </div>
    </div>

    <!-- 6. User Profile & Direct Message Modal -->
    <x-ui.modal name="user-profile-modal" max-width="max-w-md" title="{{ __('User Profile') }}">
        @if ($this->viewingUserProfile)
            <div class="p-6 space-y-5 text-center">
                <div class="relative inline-block mx-auto">
                    <x-ui.avatar
                        :name="$this->viewingUserProfile->name"
                        :initials="$this->viewingUserProfile->initials()"
                        :src="$this->viewingUserProfile->avatarUrl()"
                        size="size-20 text-xl mx-auto shadow-md"
                    />
                    @if ($this->viewingUserProfile->isOnline())
                        <span class="absolute bottom-1 right-1 h-4 w-4 rounded-full bg-emerald-500 ring-2 ring-background"></span>
                    @endif
                </div>

                <div>
                    <h3 class="text-lg font-bold text-foreground">{{ $this->viewingUserProfile->name }}</h3>
                    <p class="text-xs text-muted-foreground mt-0.5">
                        {{ $this->viewingUserProfile->designation ?? ($this->viewingUserProfile->roles->first()?->name ?? __('Member')) }}
                    </p>
                </div>

                <div class="p-3.5 rounded-xl border border-border bg-secondary/30 text-left space-y-2 text-xs">
                    <div class="flex items-center justify-between">
                        <span class="text-muted-foreground font-medium">{{ __('Email:') }}</span>
                        <span class="text-foreground font-semibold truncate ml-2">{{ $this->viewingUserProfile->email }}</span>
                    </div>
                    @if ($this->viewingUserProfile->phone)
                        <div class="flex items-center justify-between">
                            <span class="text-muted-foreground font-medium">{{ __('Phone:') }}</span>
                            <span class="text-foreground font-semibold ml-2">{{ $this->viewingUserProfile->phone }}</span>
                        </div>
                    @endif
                    @if ($this->viewingUserProfile->whatsapp_no)
                        <div class="flex items-center justify-between">
                            <span class="text-muted-foreground font-medium">{{ __('WhatsApp:') }}</span>
                            <span class="text-foreground font-semibold ml-2">{{ $this->viewingUserProfile->whatsapp_no }}</span>
                        </div>
                    @endif
                    <div class="flex items-center justify-between">
                        <span class="text-muted-foreground font-medium">{{ __('Status:') }}</span>
                        <span class="font-medium {{ $this->viewingUserProfile->isOnline() ? 'text-emerald-500 font-semibold' : 'text-muted-foreground' }}">
                            {{ $this->viewingUserProfile->lastSeenText() }}
                        </span>
                    </div>
                </div>

                <div class="pt-2 flex items-center justify-center gap-2">
                    <x-ui.button type="button" x-on:click="$store.modals.close('user-profile-modal')" variant="outline" size="sm">
                        {{ __('Close') }}
                    </x-ui.button>

                    @if (auth()->id() !== $this->viewingUserProfile->id)
                        <x-ui.button
                            type="button"
                            wire:click="startDirectMessage({{ $this->viewingUserProfile->id }})"
                            variant="default"
                            size="sm"
                            icon="message-square"
                        >
                            {{ __('Direct Message') }}
                        </x-ui.button>
                    @endif
                </div>
            </div>
        @endif
    </x-ui.modal>

    <!-- Reusable Image Editor Component -->
    <x-ui.image-editor />
</div>
