<?php

use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

Broadcast::channel('rider.pool', function ($user) {
    return $user && $user->isRider() && $user->is_active;
});

Broadcast::channel('rider.{id}', function ($user, $id) {
    return $user && $user->isRider() && (int) $user->id === (int) $id && $user->is_active;
});

Broadcast::channel('customer.{id}', function ($user, $id) {
    return $user && $user->isCustomer() && (int) $user->id === (int) $id && $user->is_active;
});
