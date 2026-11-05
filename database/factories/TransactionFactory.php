<?php

namespace Database\Factories;

use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use App\Models\Office;
use App\Models\Transaction;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Ids are the legacy ids and are not auto-incremented, so the factory hands out
 * its own.
 *
 * @extends Factory<Transaction>
 */
class TransactionFactory extends Factory
{
    private static int $nextId = 100000;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => self::$nextId++,
            'office_id' => fn () => Office::query()->value('id') ?? Office::create(['id' => 1, 'name' => 'Examination Services Division', 'code' => 'ESD'])->id,
            'fname' => fake()->firstName(),
            'lname' => fake()->lastName(),
            'mname' => fake()->lastName(),
            'transaction_type' => TransactionType::Spels,
            'transaction_date' => now(),
            'transaction_status' => TransactionStatus::Submitted,
        ];
    }

    public function approved(?string $dateCompleted = null): static
    {
        return $this->state(fn () => [
            'transaction_status' => TransactionStatus::Approved,
            'date_completed' => $dateCompleted ?? now()->toDateString(),
        ]);
    }

    public function forApplicant(string $lname, string $fname, ?string $mname, ?string $eligCode): static
    {
        return $this->state(fn () => ['lname' => $lname, 'fname' => $fname, 'mname' => $mname, 'elig_code' => $eligCode]);
    }
}
