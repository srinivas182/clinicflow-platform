<?php

declare(strict_types=1);

namespace App\Domains\Lab\Inbound;

/**
 * Turns HL7 v2 ORU^R01 or a FHIR DiagnosticReport (Bundle) into one shape:
 * {message_id, order_ref, barcode, patient: {name, dob}, results: [{code, name, value, unit, flag, status}]}.
 */
final class InboundResultParser
{
    /**
     * @return array{message_id: ?string, order_ref: ?string, barcode: ?string, patient: array{name: ?string, dob: ?string}, results: list<array{code: string, name: string, value: string, unit: ?string, flag: ?string, status: string}>}
     */
    public static function hl7(string $raw): array
    {
        $segments = preg_split('/\r\n|\r|\n/', trim($raw)) ?: [];
        $out = ['message_id' => null, 'order_ref' => null, 'barcode' => null, 'patient' => ['name' => null, 'dob' => null], 'results' => []];
        if (! str_starts_with($segments[0] ?? '', 'MSH')) {
            throw new \InvalidArgumentException('Not an HL7 v2 message (no MSH segment).');
        }
        $fieldSep = substr($segments[0], 3, 1) ?: '|';
        $compSep = substr($segments[0], 4, 1) ?: '^';
        foreach ($segments as $segment) {
            $f = explode($fieldSep, $segment);
            $c = fn (int $i, int $k = 0): string => (string) (explode($compSep, $f[$i] ?? '')[$k] ?? '');
            switch ($f[0]) {
                case 'MSH':
                    // MSH-1 is the separator itself, so MSH-n is at index n-1.
                    $type = ($f[8] ?? '');
                    if (! str_starts_with($type, 'ORU')) {
                        throw new \InvalidArgumentException('Only result messages (ORU^R01) are accepted.');
                    }
                    $out['message_id'] = ($f[9] ?? '') !== '' ? $f[9] : null;
                    break;
                case 'PID':
                    $out['patient'] = ['name' => trim($c(5, 1).' '.$c(5, 0)) ?: null, 'dob' => self::date($c(7))];
                    break;
                case 'OBR':
                    $out['order_ref'] ??= $c(2) !== '' ? $c(2) : null;
                    $out['barcode'] ??= $c(3) !== '' ? $c(3) : null;
                    break;
                case 'OBX':
                    $out['results'][] = ['code' => $c(3), 'name' => $c(3, 1) ?: $c(3), 'value' => (string) ($f[5] ?? ''), 'unit' => $c(6) ?: null,
                        'flag' => self::flag((string) ($f[8] ?? '')), 'status' => strtoupper((string) ($f[11] ?? 'F')) ?: 'F'];
                    break;
            }
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $json
     * @return array{message_id: ?string, order_ref: ?string, barcode: ?string, patient: array{name: ?string, dob: ?string}, results: list<array{code: string, name: string, value: string, unit: ?string, flag: ?string, status: string}>}
     */
    public static function fhir(array $json): array
    {
        $resources = ($json['resourceType'] ?? null) === 'Bundle'
            ? array_values(array_filter(array_map(fn ($e) => $e['resource'] ?? null, (array) ($json['entry'] ?? []))))
            : [$json];
        $report = collect($resources)->firstWhere('resourceType', 'DiagnosticReport');
        if (! is_array($report)) {
            throw new \InvalidArgumentException('Send a DiagnosticReport (on its own or in a Bundle).');
        }
        $observations = collect($resources)->where('resourceType', 'Observation')->keyBy(fn ($o) => 'Observation/'.($o['id'] ?? ''));
        $contained = collect((array) ($report['contained'] ?? []))->where('resourceType', 'Observation')->keyBy(fn ($o) => '#'.($o['id'] ?? ''));
        $patient = collect($resources)->firstWhere('resourceType', 'Patient');
        $ref = (string) preg_replace('#^ServiceRequest/#', '', (string) data_get($report, 'basedOn.0.reference', ''));
        $results = [];
        foreach ((array) ($report['result'] ?? []) as $r) {
            $o = $observations->get((string) ($r['reference'] ?? '')) ?? $contained->get((string) ($r['reference'] ?? ''));
            if (! is_array($o)) {
                continue;
            }
            $results[] = ['code' => (string) data_get($o, 'code.coding.0.code', ''), 'name' => (string) (data_get($o, 'code.text') ?? data_get($o, 'code.coding.0.display', '')),
                'value' => (string) (data_get($o, 'valueQuantity.value') ?? data_get($o, 'valueString') ?? data_get($o, 'valueCodeableConcept.text', '')),
                'unit' => data_get($o, 'valueQuantity.unit'), 'flag' => self::flag((string) data_get($o, 'interpretation.0.coding.0.code', '')),
                'status' => ($o['status'] ?? 'final') === 'final' ? 'F' : 'P'];
        }

        return ['message_id' => isset($json['id']) ? (string) $json['id'] : (isset($report['id']) ? (string) $report['id'] : null),
            'order_ref' => $ref !== '' ? $ref : (data_get($report, 'identifier.0.value') !== null ? (string) data_get($report, 'identifier.0.value') : null),
            'barcode' => data_get($report, 'specimen.0.identifier.value') !== null ? (string) data_get($report, 'specimen.0.identifier.value') : null,
            'patient' => ['name' => is_array($patient) ? trim(implode(' ', (array) data_get($patient, 'name.0.given', [])).' '.data_get($patient, 'name.0.family', '')) ?: null : null,
                'dob' => is_array($patient) ? data_get($patient, 'birthDate') : null],
            'results' => $results];
    }

    /** Lab abnormality flag → our lab flag (the system can only be raised by it, never lowered). */
    private static function flag(string $code): ?string
    {
        return match (strtoupper(trim($code))) {
            'HH', 'LL', 'AA', 'PANIC', 'C' => 'critical',
            'H', 'L', 'A', 'HU', 'LU' => 'abnormal',
            'N', '' => null,
            default => 'abnormal',
        };
    }

    private static function date(string $v): ?string
    {
        return preg_match('/^(\d{4})(\d{2})(\d{2})/', $v, $m) === 1 ? "{$m[1]}-{$m[2]}-{$m[3]}" : null;
    }
}
