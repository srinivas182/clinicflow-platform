<?php

declare(strict_types=1);

namespace App\Domains\Prescribing\Support;

use App\Domains\Prescribing\Contracts\DrugDatabase;
use App\Domains\Prescribing\Models\Medicine;
use Illuminate\Support\Facades\DB;

/**
 * Demo rule set for development and testing. Not for clinical use.
 */
class DemoDrugDatabase implements DrugDatabase
{
    public function find(int $medicineId): ?Medicine
    {
        return Medicine::query()->find($medicineId);
    }

    public function interactions(array $a, array $b): array
    {
        $found = [];
        foreach ($a as $x) {
            foreach ($b as $y) {
                if ($x === $y) {
                    continue;
                }
                [$p, $q] = $x < $y ? [$x, $y] : [$y, $x];
                $row = DB::connection((string) config('tenancy.database.central_connection'))->table('drug_interactions')
                    ->where('ingredient_a', $p)->where('ingredient_b', $q)->first();
                if ($row !== null) {
                    $found["{$p}+{$q}"] = ['severity' => (string) $row->severity, 'message' => (string) $row->message, 'pair' => "{$p} + {$q}"];
                }
            }
        }

        return array_values($found);
    }
}
