<?php

declare(strict_types=1);

namespace App\Domains\Pharmacy\Actions;

use App\Domains\Visits\Actions\DischargeVisit;
use App\Domains\Visits\Enums\VisitStage;
use App\Domains\Visits\Models\Visit;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * Hand-over at the collection window: the patient's 4-digit code proves the
 * hand-over; someone else collecting gives their name and ID number. The
 * discharge gate still applies.
 */
class ConfirmCollection
{
    public function __construct(private readonly DischargeVisit $discharge) {}

    public function handle(Visit $visit, string $code, ?string $collectorName = null, ?string $collectorId = null, ?User $by = null): Visit
    {
        if ($visit->stage !== VisitStage::Dispatch || $visit->collection_code === null) {
            throw ValidationException::withMessages(['code' => 'Nothing is waiting for collection on this visit.']);
        }
        if (! hash_equals($visit->collection_code, trim($code))) {
            throw ValidationException::withMessages(['code' => 'The collection code does not match.']);
        }
        if (filled($collectorName) && blank($collectorId)) {
            throw ValidationException::withMessages(['collector_id' => "Record the collector's ID number."]);
        }

        $visit->forceFill(['collected_by_name' => $collectorName, 'collected_by_id_number' => $collectorId])->save();
        $this->discharge->handle($visit, $by);
        $visit->forceFill(['collected_at' => now()])->save();

        return $visit;
    }
}
