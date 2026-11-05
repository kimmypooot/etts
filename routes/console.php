<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;

// Sessions IMIS can no longer sign out (it stops tracking them after 30 days).
Schedule::call(fn () => DB::table('imis_sessions')->where('created_at', '<', now()->subDays(30))->delete())
    ->daily()
    ->name('prune-imis-sessions');
