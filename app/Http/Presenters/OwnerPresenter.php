<?php

namespace App\Http\Presenters;

use App\Models\UpcomingReleaseNotification;
use App\Models\User;

class OwnerPresenter
{
    /** @return array{id:string,name:string,email:string,unread_notification_count:int} */
    public function present(User $owner): array
    {
        return [
            'id' => $owner->id,
            'name' => $owner->name,
            'email' => $owner->email,
            'unread_notification_count' => UpcomingReleaseNotification::query()
                ->where('user_id', $owner->id)->whereIn('status', ['active', 'withdrawn'])->whereNull('read_at')->count(),
        ];
    }
}
