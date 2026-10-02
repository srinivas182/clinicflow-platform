<?php

declare(strict_types=1);

namespace App\Domains\Patients\Actions;

use App\Domains\Patients\Models\Patient;
use App\Domains\Patients\Support\SaIdNumber;
use Illuminate\Database\Eloquent\Collection;

/**
 * "Search first": find a patient by SA ID, cell number or name before registering.
 */
class SearchPatients
{
    /**
     * @return Collection<int, Patient>
     */
    public function handle(string $term, int $limit = 25): Collection
    {
        $term = trim($term);
        $query = Patient::query()->orderBy('surname')->orderBy('first_names')->limit($limit);

        if ($term === '') {
            return $query->latest()->get();
        }

        $digits = preg_replace('/\D/', '', $term) ?? '';

        if (strlen($digits) === 13 && ($id = SaIdNumber::tryParse($digits)) !== null) {
            return $query->where('id_number_hash', $id->lookupHash())->get();
        }

        if (strlen($digits) === 10 && $digits === $term) {
            return $query->where('cell', $digits)->get();
        }

        $words = preg_split('/\s+/', $term) ?: [];

        foreach ($words as $word) {
            $query->where(fn ($q) => $q->where('first_names', 'like', "%{$word}%")->orWhere('surname', 'like', "%{$word}%"));
        }

        return $query->get();
    }
}
