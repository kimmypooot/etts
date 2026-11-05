<?php

namespace App\Http\Controllers;

use App\Services\Imis\PeopleDirectory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Name suggestions from the IMIS people directory for the addressee and
 * signatory fields (see {@see PeopleDirectory}). Any signed-in staff may ask:
 * it carries only name, position and office. `available` tells the page
 * whether suggestions exist at all; the fields work either way.
 */
class PeopleSearchController extends Controller
{
    public function __invoke(Request $request, PeopleDirectory $directory): JsonResponse
    {
        $query = mb_substr(trim((string) $request->query('q', '')), 0, 60);

        return response()->json(
            ['available' => $directory->enabled(), 'results' => $directory->search($query)],
            headers: ['Cache-Control' => 'no-store'],
        );
    }
}
