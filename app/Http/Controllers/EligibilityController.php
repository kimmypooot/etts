<?php

namespace App\Http\Controllers;

use App\Http\Requests\EligibilityRequest;
use App\Models\AuditLog;
use App\Models\Eligibility;
use App\Models\Transaction;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The eligibility reference list (legacy eligibilities.php). A code in use by
 * any transaction cannot be deleted or have its code changed, because
 * transactions store the code as text; the title can always be edited.
 */
class EligibilityController extends Controller
{
    public function index(Request $request): Response
    {
        $search = trim((string) $request->query('search', ''));
        $sort = $request->query('sort') === 'name' ? 'name' : 'code';
        $direction = $request->query('dir') === 'desc' ? 'desc' : 'asc';

        $eligibilities = Eligibility::query()
            ->select('eligibilities.*')
            ->selectSub(
                Transaction::query()->selectRaw('count(*)')->whereColumn('transactions.elig_code', 'eligibilities.code'),
                'usage_count',
            )
            ->when($search !== '', fn ($query) => $query->where(fn ($inner) => $inner
                ->where('code', 'like', '%'.$search.'%')
                ->orWhere('name', 'like', '%'.$search.'%')))
            ->orderBy($sort, $direction)
            ->paginate(25)
            ->withQueryString()
            ->through(fn (Eligibility $eligibility) => [
                'id' => $eligibility->id,
                'code' => $eligibility->code,
                'name' => $eligibility->name,
                'usage_count' => (int) $eligibility->usage_count,
            ]);

        return Inertia::render('Eligibilities/Index', [
            'eligibilities' => $eligibilities,
            'filters' => ['search' => $search, 'sort' => $sort, 'dir' => $direction],
        ]);
    }

    public function store(EligibilityRequest $request): RedirectResponse
    {
        $eligibility = Eligibility::create($request->validated());
        AuditLog::record('eligibility.created', $eligibility, after: $eligibility->only('code', 'name'));

        return back()->with('status', "{$eligibility->code} added.");
    }

    public function update(EligibilityRequest $request, Eligibility $eligibility): RedirectResponse
    {
        $data = $request->validated();

        if ($data['code'] !== $eligibility->code && Eligibility::usageCount($eligibility->code) > 0) {
            return back()->withErrors(['code' => 'This code is used by existing records, so it cannot be changed. You can still edit the title.']);
        }

        $before = $eligibility->only('code', 'name');
        $eligibility->update($data);
        AuditLog::record('eligibility.updated', $eligibility, before: $before, after: $eligibility->only('code', 'name'));

        return back()->with('status', 'Eligibility updated.');
    }

    public function destroy(Eligibility $eligibility): RedirectResponse
    {
        $usage = Eligibility::usageCount($eligibility->code);

        if ($usage > 0) {
            return back()->with('error', "{$eligibility->code} is used by {$usage} record(s) and cannot be deleted.");
        }

        $before = $eligibility->only('code', 'name');
        $eligibility->delete();
        AuditLog::record('eligibility.deleted', $eligibility, before: $before);

        return back()->with('status', "{$before['code']} deleted.");
    }
}
