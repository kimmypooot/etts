<?php

namespace App\Services\Imis;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Suggests staff names (with position and office) from the IMIS people
 * directory, for the addressee and signatory fields. The list is fetched
 * server to server with a bearer key and cached, so typing never calls IMIS.
 *
 * It is only ever a convenience: when it is not configured, or IMIS is slow or
 * down, search() returns nothing and the fields stay plain free text. It never
 * throws, and a failure is remembered briefly so a down IMIS is not hit on
 * every keystroke.
 */
class PeopleDirectory
{
    private const CACHE_KEY = 'imis.people-directory';

    private const FAILED_KEY = 'imis.people-directory.failed';

    private const TTL_SECONDS = 600;

    private const FAILURE_BACKOFF_SECONDS = 60;

    public function enabled(): bool
    {
        return $this->url() !== null && (string) config('services.imis.directory_key') !== '';
    }

    /**
     * @return list<array{name: string, position: ?string, office: ?string}>
     */
    public function search(string $query, int $limit = 8): array
    {
        $query = mb_strtolower(trim($query));

        if ($query === '' || ! $this->enabled()) {
            return [];
        }

        $matches = [];

        foreach ($this->people() as $person) {
            $name = mb_strtolower($person['name']);

            // Best first: name starts with it, then a word of the name does, then anywhere
            // in the name, then the position or office.
            $rank = match (true) {
                str_starts_with($name, $query) => 0,
                str_contains($name, ' '.$query) => 1,
                str_contains($name, $query) => 2,
                str_contains(mb_strtolower((string) $person['position']), $query),
                str_contains(mb_strtolower((string) $person['office']), $query) => 3,
                default => null,
            };

            if ($rank !== null) {
                $matches[] = [$rank, $person];
            }
        }

        usort($matches, fn (array $a, array $b) => $a[0] <=> $b[0]);

        return array_map(fn (array $match) => $match[1], array_slice($matches, 0, $limit));
    }

    /**
     * @return list<array{name: string, position: ?string, office: ?string}>
     */
    private function people(): array
    {
        if (Cache::has(self::FAILED_KEY)) {
            return [];
        }

        $people = Cache::get(self::CACHE_KEY);

        if (is_array($people)) {
            return $people;
        }

        try {
            $response = Http::withToken((string) config('services.imis.directory_key'))
                ->acceptJson()->timeout(3)->connectTimeout(2)->get($this->url());

            if (! $response->successful()) {
                throw new \RuntimeException('IMIS directory answered '.$response->status());
            }

            $people = collect($response->json('data', []))
                ->filter(fn ($row) => is_array($row) && filled($row['name'] ?? null))
                ->map(fn (array $row) => [
                    'name' => (string) $row['name'],
                    'position' => filled($row['position'] ?? null) ? (string) $row['position'] : null,
                    'office' => filled($row['office_name'] ?? null) ? (string) $row['office_name'] : null,
                ])
                ->values()->all();
        } catch (Throwable $e) {
            report($e);
            Cache::put(self::FAILED_KEY, true, self::FAILURE_BACKOFF_SECONDS);

            return [];
        }

        Cache::put(self::CACHE_KEY, $people, self::TTL_SECONDS);

        return $people;
    }

    private function url(): ?string
    {
        $url = config('services.imis.directory_url');

        return is_string($url) && $url !== '' ? $url : null;
    }
}
