<?php

namespace App\Http\Presenters;

use App\Models\User;

class OwnerPresenter
{
    /** @return array{id:string,name:string,email:string} */
    public function present(User $owner): array
    {
        return [
            'id' => $owner->id,
            'name' => $owner->name,
            'email' => $owner->email,
        ];
    }
}
