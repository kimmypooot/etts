<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

#[Signature('etts:verify-attachments {--list : Print every missing and unreferenced file name} {--quarantine : Move unreferenced files into attachments/_unreferenced (in this app only, never the legacy folder)}')]
#[Description('Check that every attachment named in the database exists on the private attachments disk, and which files nothing refers to.')]
class VerifyAttachmentsCommand extends Command
{
    private const ALLOWED = ['pdf', 'jpg', 'jpeg', 'png'];

    private const QUARANTINE = '_unreferenced';

    public function handle(): int
    {
        $disk = Storage::disk('attachments');

        $referenced = $this->referenced();
        $onDisk = collect($disk->files())
            ->map(fn (string $path) => basename($path))
            ->reject(fn (string $name) => str_starts_with($name, '.'))
            ->values();

        $missing = $referenced->diff($onDisk)->values();
        $unreferenced = $onDisk->diff($referenced)->values();
        $badType = $referenced->intersect($onDisk)
            ->reject(fn (string $name) => in_array(strtolower(pathinfo($name, PATHINFO_EXTENSION)), self::ALLOWED, true))
            ->values();
        $unsafeName = $referenced->filter(fn (string $name) => $name !== basename($name) || str_contains($name, '..'))->values();

        $this->table(['Check', 'Count'], [
            ['Files named in the database', $referenced->count()],
            ['Files on the attachments disk', $onDisk->count()],
            ['Missing on disk', $missing->count()],
            ['Not referenced by any record', $unreferenced->count()],
            ['Referenced with a type other than pdf/jpg/jpeg/png', $badType->count()],
            ['Referenced names that look like a path', $unsafeName->count()],
        ]);

        if ($this->option('list')) {
            foreach (['Missing' => $missing, 'Unreferenced' => $unreferenced, 'Bad type' => $badType, 'Unsafe name' => $unsafeName] as $label => $names) {
                foreach ($names as $name) {
                    $this->line("{$label}: {$name}");
                }
            }
        }

        if ($this->option('quarantine') && $unreferenced->isNotEmpty()) {
            foreach ($unreferenced as $name) {
                $disk->move($name, self::QUARANTINE.'/'.$name);
            }
            $this->components->info("{$unreferenced->count()} unreferenced file(s) moved to attachments/".self::QUARANTINE.'. Nothing was deleted.');
        }

        if ($missing->isNotEmpty() || $badType->isNotEmpty() || $unsafeName->isNotEmpty()) {
            $this->components->error('Some attachments are missing or unusable. Run with --list to see which.');

            return self::FAILURE;
        }

        $this->components->info('Every referenced attachment is present.');

        return self::SUCCESS;
    }

    /**
     * Every file name stored on a live or archived issuance request.
     *
     * @return \Illuminate\Support\Collection<int, string>
     */
    private function referenced(): \Illuminate\Support\Collection
    {
        return collect(['issuance_requests', 'deleted_issuance_requests'])
            ->flatMap(fn (string $table) => [
                ...DB::table($table)->whereNotNull('attachment_1')->pluck('attachment_1'),
                ...DB::table($table)->whereNotNull('attachment_2')->pluck('attachment_2'),
            ])
            ->map(fn ($name) => trim((string) $name))
            ->filter()
            ->unique()
            ->values();
    }
}
