<?php

namespace App\Http\Middleware;

use App\Models\AuditLog;
use App\Models\DeletedTransaction;
use App\Models\KioskTerminal;
use App\Models\Eligibility;
use App\Models\Transaction;
use App\Models\Transmittal;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that is loaded on the first page visit.
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $user = $request->user();

        return [
            ...parent::share($request),
            'auth' => [
                'user' => $user ? [
                    ...$user->only('id', 'name', 'username', 'email', 'photo_url'),
                    'role' => $user->role->value,
                    'role_label' => $user->role->label(),
                    'office' => $user->office?->only('id', 'name', 'code'),
                ] : null,
                // For hiding controls only (useAuthorization); every route
                // checks its policy regardless.
                'permissions' => $user ? array_keys(array_filter([
                    'activity.view' => $user->can('viewAny', AuditLog::class),
                    'transactions.view' => $user->can('viewAny', Transaction::class),
                    'transactions.process' => $user->can('process', Transaction::class),
                    'transactions.manage' => $user->can('manage', Transaction::class),
                    'transactions.export' => $user->can('export', Transaction::class),
                    'transactions.delete' => $user->can('delete', Transaction::class),
                    'transmittals.view' => $user->can('viewAny', Transmittal::class),
                    'transmittals.manage' => $user->can('manage', Transmittal::class),
                    'fo.portal' => $user->can('office', Transaction::class),
                    'fo.create' => $user->can('createAsOffice', Transaction::class),
                    'kiosks.manage' => $user->can('viewAny', KioskTerminal::class),
                    'archive.view' => $user->can('viewAny', DeletedTransaction::class),
                    'eligibilities.manage' => $user->can('viewAny', Eligibility::class),
                ])) : [],
            ],
            'imisUrl' => config('services.imis.portal_url'),
            // The header's app switcher: ESD only, as in the legacy header.
            'relatedSystems' => $user?->isAdmin() ? array_map(
                fn (array $item) => ['label' => $item['label'], 'icon' => $item['icon'], 'url' => config('etts.related_systems.base_url').$item['path']],
                config('etts.related_systems.items'),
            ) : [],
            'flash' => [
                'error' => fn () => $request->session()->get('error'),
                'status' => fn () => $request->session()->get('status'),
            ],
        ];
    }
}
