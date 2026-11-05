<?php

namespace App\Http\Controllers;

use App\Enums\UserRole;
use App\Services\Reports\DashboardMetrics;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The staff landing page. A field office user goes to their own portal; ESD
 * administrators get the overview of the whole system.
 */
class DashboardController extends Controller
{
    public function __invoke(Request $request, DashboardMetrics $metrics): Response|RedirectResponse
    {
        $user = $request->user();

        if ($user->role === UserRole::FieldOffice) {
            return redirect()->route('fo.dashboard');
        }

        return Inertia::render('Dashboard', [
            'office' => $user->office?->only('id', 'name', 'code'),
            'alertDays' => [
                'receipt' => DashboardMetrics::RECEIPT_ALERT_DAYS,
                'draft' => DashboardMetrics::DRAFT_ALERT_DAYS,
            ],
            ...$metrics->all(),
        ]);
    }
}
