<?php

declare(strict_types=1);

namespace App\Domains\Patients\Actions;

use App\Domains\Patients\Enums\Channel;
use App\Domains\Patients\Enums\IdType;
use App\Domains\Patients\Models\Patient;
use App\Domains\Patients\Support\SaIdNumber;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Imports a practice's existing patient list from CSV (legacy system export).
 * Rows are validated one by one; duplicates and bad rows are skipped with a
 * reason. Imported patients have no recorded consent yet, so they must give
 * POPIA and treatment consent at their next check-in.
 *
 * Columns: first_names, surname, id_number, date_of_birth, cell, email,
 * medical_aid_scheme, medical_aid_number
 */
class ImportPatients
{
    public const COLUMNS = ['first_names', 'surname', 'id_number', 'date_of_birth', 'cell', 'email', 'medical_aid_scheme', 'medical_aid_number'];

    /**
     * @return array{id: int, total: int, imported: int, skipped: int, problems: list<array{row: int, reason: string}>}
     */
    public function handle(string $path, string $fileName, ?User $by = null): array
    {
        $handle = fopen($path, 'r');
        if ($handle === false) {
            return ['id' => 0, 'total' => 0, 'imported' => 0, 'skipped' => 0, 'problems' => [['row' => 0, 'reason' => 'The file could not be read.']]];
        }

        $header = array_map(fn ($h) => strtolower(trim((string) $h)), fgetcsv($handle) ?: []);
        $header[0] = ltrim($header[0] ?? '', "\u{FEFF}");
        $missing = array_diff(['first_names', 'surname'], $header);

        $importId = (int) DB::table('patient_imports')->insertGetId(['file_name' => $fileName, 'problems' => '[]', 'imported_by' => $by?->id, 'created_at' => now(), 'updated_at' => now()]);
        $total = 0;
        $imported = 0;
        $problems = [];

        if ($missing !== []) {
            $problems[] = ['row' => 1, 'reason' => 'Missing columns: '.implode(', ', $missing).'.'];
        }

        while ($missing === [] && ($row = fgetcsv($handle)) !== false) {
            $total++;
            $line = $total + 1;
            if (count(array_filter($row, fn ($v) => trim((string) $v) !== '')) === 0) {
                $total--;

                continue;
            }
            $data = array_combine($header, array_pad(array_slice($row, 0, count($header)), count($header), ''));

            try {
                $reason = $this->importRow($data, $importId, $by);
            } catch (Throwable) {
                $reason = 'Could not be saved.';
            }

            if ($reason === null) {
                $imported++;
            } else {
                $problems[] = ['row' => $line, 'reason' => $reason];
            }
        }
        fclose($handle);

        DB::table('patient_imports')->where('id', $importId)->update([
            'rows_total' => $total, 'rows_imported' => $imported, 'rows_skipped' => $total - $imported,
            'problems' => json_encode(array_slice($problems, 0, 500)), 'updated_at' => now(),
        ]);
        activity('patients')->causedBy($by)->withProperties(['file' => $fileName, 'imported' => $imported, 'skipped' => $total - $imported])->log('Legacy patients imported');

        return ['id' => $importId, 'total' => $total, 'imported' => $imported, 'skipped' => $total - $imported, 'problems' => $problems];
    }

    /**
     * @param  array<string, string>  $d
     */
    private function importRow(array $d, int $importId, ?User $by): ?string
    {
        $first = trim($d['first_names'] ?? '');
        $surname = trim($d['surname'] ?? '');
        if ($first === '' || $surname === '') {
            return 'First names and surname are required.';
        }

        $idRaw = trim($d['id_number'] ?? '');
        $sa = $idRaw === '' ? null : SaIdNumber::tryParse($idRaw);
        if ($idRaw !== '' && strlen(preg_replace('/\D/', '', $idRaw) ?? '') === 13 && $sa === null) {
            return 'SA ID number fails the check digit.';
        }

        $dob = $sa?->dateOfBirth();
        if ($dob === null && trim($d['date_of_birth'] ?? '') !== '') {
            try {
                $dob = CarbonImmutable::parse(trim($d['date_of_birth']))->startOfDay();
            } catch (Throwable) {
                return 'Date of birth is not a valid date.';
            }
        }
        if ($dob === null || $dob->isFuture()) {
            return 'A valid SA ID or date of birth is required.';
        }

        $cell = preg_replace('/\D/', '', $d['cell'] ?? '') ?? '';
        if (str_starts_with($cell, '27') && strlen($cell) === 11) {
            $cell = '0'.substr($cell, 2);
        }
        if ($cell !== '' && ! preg_match('/^0\d{9}$/', $cell)) {
            return 'Cell number is not a 10-digit South African number.';
        }

        if ($sa !== null && Patient::query()->where('id_number_hash', $sa->lookupHash())->exists()) {
            return 'Already registered (same SA ID).';
        }
        if ($sa === null && Patient::query()->where('first_names', $first)->where('surname', $surname)->whereDate('date_of_birth', $dob)->exists()) {
            return 'Already registered (same name and date of birth).';
        }

        Patient::create([
            'first_names' => $first,
            'surname' => $surname,
            'id_type' => $sa !== null ? IdType::SaId : IdType::None,
            'id_number' => $sa?->value(),
            'id_number_hash' => $sa?->lookupHash(),
            'date_of_birth' => $dob,
            'sex' => $sa?->sex(),
            'cell' => $cell === '' ? null : $cell,
            'no_cell' => $cell === '',
            'email' => filter_var(trim($d['email'] ?? ''), FILTER_VALIDATE_EMAIL) ?: null,
            'preferred_language' => 'en',
            'preferred_channel' => Channel::Sms,
            'medical_aid_scheme' => trim($d['medical_aid_scheme'] ?? '') ?: null,
            'medical_aid_number' => trim($d['medical_aid_number'] ?? '') ?: null,
            'registered_by' => $by?->id,
            'needs_consent' => true,
            'patient_import_id' => $importId,
        ]);

        return null;
    }
}
