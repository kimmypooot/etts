<?php

namespace App\Http\Controllers;

use App\Http\Requests\ExamCenterRequest;
use App\Models\AuditLog;
use App\Models\ExamCenter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The places of examination / issuance list. A name in use by any request
 * cannot be deleted or renamed, because requests store it as text; it can be
 * switched off instead.
 */
class ExamCenterController extends Controller
{
    public function index(Request $request): Response
    {
        $search = trim((string) $request->query('search', ''));
        $direction = $request->query('dir') === 'desc' ? 'desc' : 'asc';

        $centers = ExamCenter::query()
            ->select('exam_centers.*')
            ->selectSub(
                DB::table('issuance_requests')->selectRaw('count(*)')->whereColumn('issuance_requests.place_of_exam_issuance', 'exam_centers.name'),
                'usage_count',
            )
            ->when($search !== '', fn ($query) => $query->where(fn ($inner) => $inner
                ->where('name', 'like', '%'.$search.'%')
                ->orWhere('country', 'like', '%'.$search.'%')))
            ->orderBy('name', $direction)
            ->paginate(25)
            ->withQueryString()
            ->through(fn (ExamCenter $center) => [
                'id' => $center->id,
                'name' => $center->name,
                'country' => $center->country,
                'is_active' => $center->is_active,
                'usage_count' => (int) $center->usage_count,
            ]);

        return Inertia::render('ExamCenters/Index', [
            'centers' => $centers,
            'filters' => ['search' => $search, 'dir' => $direction],
        ]);
    }

    public function store(ExamCenterRequest $request): RedirectResponse
    {
        $center = ExamCenter::create($request->validated());
        AuditLog::record('exam_center.created', $center, after: $center->fresh()->auditValues());

        return back()->with('status', "{$center->name} added.");
    }

    public function update(ExamCenterRequest $request, ExamCenter $examCenter): RedirectResponse
    {
        $data = $request->validated();

        if ($data['name'] !== $examCenter->name && ExamCenter::usageCount($examCenter->name) > 0) {
            return back()->withErrors(['name' => 'This place is used by existing requests, so it cannot be renamed. You can still change its country or switch it off.']);
        }

        $before = $examCenter->auditValues();
        $examCenter->update($data);
        AuditLog::record('exam_center.updated', $examCenter, before: $before, after: $examCenter->auditValues());

        return back()->with('status', 'Place of examination updated.');
    }

    public function destroy(ExamCenter $examCenter): RedirectResponse
    {
        $usage = ExamCenter::usageCount($examCenter->name);

        if ($usage > 0) {
            return back()->with('error', "{$examCenter->name} is used by {$usage} request(s) and cannot be deleted. Switch it off instead.");
        }

        $before = $examCenter->auditValues();
        $examCenter->delete();
        AuditLog::record('exam_center.deleted', $examCenter, before: $before);

        return back()->with('status', "{$before['name']} deleted.");
    }
}
