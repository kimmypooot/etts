<?php

namespace App\Http\Controllers;

use App\Services\Legacy\LegacySync;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;
use Throwable;

/**
 * The "Sync from legacy" screen (see {@see LegacySync}). Administrators only,
 * by the route's policy. Whether sync is switched on is checked here, not in a
 * site-wide permission, so nothing else in the app depends on it: when it is
 * off the page just says so and every action refuses.
 *
 * The actions are small JSON calls the page makes one after another
 * (preview, begin, one per step, finish), so no request runs long.
 */
class LegacySyncController extends Controller
{
    public function show(LegacySync $sync): Response
    {
        return Inertia::render('LegacySync/Index', [
            'enabled' => $sync->enabled(),
            'steps' => $sync->steps(),
            ...$sync->status(),
        ]);
    }

    public function preview(LegacySync $sync): JsonResponse
    {
        return $this->respond(fn () => ['rows' => $sync->preview()]);
    }

    public function begin(LegacySync $sync): JsonResponse
    {
        return $this->respond(fn () => ['token' => $sync->begin()]);
    }

    public function step(Request $request, LegacySync $sync): JsonResponse
    {
        $data = $request->validate(['token' => ['required', 'string', 'max:100'], 'step' => ['required', 'string', 'max:60']]);

        return $this->respond(fn () => ['result' => $sync->step($data['token'], $data['step'])], $data['token'], $sync);
    }

    public function finish(Request $request, LegacySync $sync): JsonResponse
    {
        $data = $request->validate(['token' => ['required', 'string', 'max:100']]);

        return $this->respond(fn () => ['result' => $sync->finish($data['token'], $request->user()->username)], $data['token'], $sync);
    }

    public function abort(Request $request, LegacySync $sync): JsonResponse
    {
        $data = $request->validate(['token' => ['required', 'string', 'max:100']]);
        $sync->abort($data['token']);

        return response()->json(['ok' => true]);
    }

    /**
     * A refusal the person can act on is a 422 with its message; anything
     * unexpected is logged and reported generically. A failed step frees the
     * lock so the run can be started again.
     *
     * @param  callable(): array<string, mixed>  $work
     */
    private function respond(callable $work, ?string $token = null, ?LegacySync $sync = null): JsonResponse
    {
        try {
            return response()->json($work());
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (Throwable $e) {
            report($e);

            if ($token !== null && $sync !== null) {
                $sync->abort($token);
            }

            return response()->json(['message' => 'The sync stopped with an error. Nothing was removed; see the application log, fix the cause and run it again.'], 500);
        }
    }
}
