<?php

use App\Models\ChatCall;
use App\Models\ChatConversation;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function (User $user, $id) {
    return (int) $user->id === (int) $id;
});

Broadcast::channel('user.{id}', function (User $user, $id) {
    return (int) $user->id === (int) $id;
});

Broadcast::channel('conversation.{id}', function (User $user, $id) {
    if ($user->hasRole('Super Administrator')) {
        return true;
    }

    return ChatConversation::where('id', $id)
        ->whereHas('participants', fn ($q) => $q->where('user_id', $user->id)->whereNull('left_at'))
        ->exists();
});

Broadcast::channel('call.{uuid}', function (User $user, string $uuid) {
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

    return $call->participants()->where('user_id', $user->id)->exists();
});
