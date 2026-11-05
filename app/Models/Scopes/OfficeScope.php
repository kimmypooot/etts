<?php

namespace App\Models\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Support\Facades\Auth;

/**
 * Field office staff see only the records filed at their own office; ESD
 * administrators see every office. Applied in SQL, so a missing check in a
 * controller can never expose another office. Does nothing when no one is
 * signed in (console commands, the import) or for administrators.
 *
 * A signed-in non-administrator with no office sees nothing.
 */
class OfficeScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $user = Auth::user();

        if ($user === null || $user->isAdmin()) {
            return;
        }

        $builder->where($model->qualifyColumn('office_id'), $user->office_id ?? 0);
    }
}
