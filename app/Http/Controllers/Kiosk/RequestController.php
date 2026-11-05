<?php

namespace App\Http\Controllers\Kiosk;

use App\Enums\TransactionType;
use App\Http\Controllers\Controller;
use App\Http\Middleware\KioskPaths;
use App\Http\Requests\KioskRequestRequest;
use App\Models\AuditLog;
use App\Models\Eligibility;
use App\Services\Transactions\TransactionRecorder;
use App\Services\Transactions\WorkflowException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The kiosk's menu, the request form and the confirmation.
 *
 * A request becomes an ordinary transaction through {@see TransactionRecorder}
 * (so the duplicate-approval gate, the status each type starts in and the
 * attachment checks are the staff ones), filed under the office the terminal is
 * paired to. The visitor never chooses an office or a status.
 *
 * The confirmation is shown once and carries only the tracking number, the
 * request type and the visitor's first name: refreshing it returns to the menu,
 * so nothing personal stays on a shared screen.
 */
class RequestController extends Controller
{
    /** URL slug => type. */
    private const SLUGS = [
        'certification' => TransactionType::Certification,
        'authentication' => TransactionType::Authentication,
        'certification-and-authentication' => TransactionType::CertificationAndAuthentication,
        'special-eligibility' => TransactionType::Spels,
    ];

    public function __construct(private TransactionRecorder $recorder) {}

    public function menu(): Response
    {
        // Back to the start: nothing the last visitor saw may be reachable with the Back button.
        Inertia::clearHistory();

        return Inertia::render('Kiosk/Menu', [
            'types' => collect(self::SLUGS)->map(fn (TransactionType $type, string $slug) => [
                'slug' => $slug,
                'type' => $type->value,
            ])->values()->all(),
        ]);
    }

    public function show(string $slug): Response
    {
        $type = self::SLUGS[$slug] ?? abort(404);

        return Inertia::render('Kiosk/Request', [
            'slug' => $slug,
            'type' => $type->value,
            'spels' => $type->isSpels(),
            'eligibilities' => $type->isSpels()
                ? Eligibility::orderBy('name')->get(['code', 'name'])->map(fn (Eligibility $e) => ['code' => $e->code, 'name' => $e->name, 'featured' => in_array($e->code, ['HGE', 'BOE', 'MC 11'], true)])->all()
                : [],
            'purposes' => config('etts.purposes'),
            'maxMb' => (int) round(config('etts.attachments.max_kb') / 1024),
        ]);
    }

    public function store(KioskRequestRequest $request): RedirectResponse
    {
        $terminal = $request->attributes->get('kiosk_terminal');

        $data = [
            ...$request->safe()->except(['attachment_1', 'attachment_2', 'privacy_acknowledged', 'website']),
            'office_id' => $terminal->filingOfficeId(),
            // The moment the request was made, not only the day.
            'transaction_date' => now()->format('Y-m-d H:i:s'),
        ];

        try {
            $transaction = $this->recorder->create($data, null, $request->file('attachment_1'), $request->file('attachment_2'));
        } catch (WorkflowException $e) {
            return back()->withErrors(['form' => $e->getMessage(), 'reason' => $e->reason ?? 'refused']);
        } catch (\Throwable $e) {
            report($e);

            return back()->withErrors(['form' => 'Submission failed. Please see the Action Officer for assistance.']);
        }

        $terminal->increment('requests_submitted');
        AuditLog::record('kiosk.request_submitted', $transaction, after: [
            'summary' => "Submitted at the kiosk {$terminal->name}.",
            'terminal' => $terminal->id,
        ]);

        $request->session()->flash('kiosk_receipt', [
            'id' => $transaction->id,
            'type' => $transaction->transaction_type->value,
            'first_name' => $transaction->fname,
            'office' => $terminal->office?->name,
            'spels' => $transaction->transaction_type->isSpels(),
        ]);

        return redirect(KioskPaths::base().'/done');
    }

    public function done(Request $request): Response|RedirectResponse
    {
        $receipt = $request->session()->pull('kiosk_receipt');

        if ($receipt === null) {
            return redirect(KioskPaths::base().'/');
        }

        Inertia::clearHistory();

        return Inertia::render('Kiosk/Done', ['receipt' => $receipt]);
    }
}
