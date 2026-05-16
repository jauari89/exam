<?php

use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

Broadcast::channel('exam-session.{sessionId}', function ($user, int $sessionId) {
    return $user->canDo('proctor_sessions') || $user->canDo('view_reports');
});
