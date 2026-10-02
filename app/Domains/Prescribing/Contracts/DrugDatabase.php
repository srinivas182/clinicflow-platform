<?php

declare(strict_types=1);

namespace App\Domains\Prescribing\Contracts;

use App\Domains\Prescribing\Models\Medicine;

/**
 * Medicine lookups and interaction rules. The demo implementation reads the
 * reference tables; MIMS or MediKredit plug in behind the same contract.
 */
interface DrugDatabase
{
    public function find(int $medicineId): ?Medicine;

    /**
     * Interactions between two sets of active ingredients.
     *
     * @param  list<string>  $a
     * @param  list<string>  $b
     * @return list<array{severity: string, message: string, pair: string}>
     */
    public function interactions(array $a, array $b): array;
}
