<?php

use App\Models\ChatCall;
use App\Models\ChatConversation;
use App\Models\ChatMeeting;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function (User $user, $id) {
    if (empty($id) || (int) $id <= 0) {
        return false;
    }

    return (int) $user->id === (int) $id;
});

Broadcast::channel('user.{id}', function (User $user, $id) {
    if (empty($id) || (int) $id <= 0) {
        return false;
    }

    return (int) $user->id === (int) $id;
});

Broadcast::channel('conversation.{id}', function (User $user, $id) {
    if (empty($id) || (int) $id <= 0) {
        return false;
    }

    if ($user->hasRole('Super Administrator')) {
        return true;
    }

    return ChatConversation::where('id', (int) $id)
        ->whereHas('participants', fn ($q) => $q->where('user_id', $user->id)->whereNull('left_at'))
        ->exists();
});

Broadcast::channel('call.{uuid}', function (User $user, ?string $uuid = null) {
    if (empty($uuid)) {
        return false;
    }

    if ($user->hasRole('Super Administrator')) {
        return true;
    }

    $call = ChatCall::where('uuid', $uuid)->first();
    if (! $call) {
        return false;
    }

    if ((int) $call->caller_id === (int) $user->id || (int) $call->receiver_id === (int) $user->id) {
        return true;
    }

    if ($call->participants()->where('user_id', $user->id)->exists()) {
        return true;
    }

    if ($call->conversation_id) {
        return ChatConversation::where('id', $call->conversation_id)
            ->whereHas('participants', fn ($q) => $q->where('user_id', $user->id)->whereNull('left_at'))
            ->exists();
    }

    return false;
});

Broadcast::channel('meeting.{uuid}', function (User $user, ?string $uuid = null) {
    if (empty($uuid)) {
        return false;
    }

    if ($user->hasRole('Super Administrator')) {
        return true;
    }

    $meeting = ChatMeeting::where('uuid', $uuid)->orWhere('invite_code', $uuid)->first();
    if (! $meeting) {
        return false;
    }

    if ((int) $meeting->host_id === (int) $user->id) {
        return true;
    }

    return $meeting->participants()->where('user_id', $user->id)->whereNull('deleted_at')->exists()
        || $meeting->isOpenForEveryone();
});
