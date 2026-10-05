<?php

declare(strict_types=1);

namespace App\Domains\Lab\Inbound;

use App\Domains\Lab\Actions\LabWorkflow;
use App\Domains\Lab\Models\LabOrder;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Receives results from a connected lab system: stores the original (encrypted),
 * ignores duplicates, matches the order by order number or sample barcode, and
 * applies final results through the normal classification and release rules.
 * Anything it cannot safely apply waits in the unmatched queue for staff —
 * a patient or order is never created automatically.
 */
class LabInbound
{
    public function __construct(private readonly LabWorkflow $workflow) {}

    /**
     * @return array{status: string, reason: ?string, id: int}
     */
    public function receive(string $format, string $raw, int $keyId): array
    {
        try {
            $parsed = $format === 'hl7' ? InboundResultParser::hl7($raw) : InboundResultParser::fhir((array) json_decode($raw, true, 64, JSON_THROW_ON_ERROR));
        } catch (\Throwable $e) {
            throw ValidationException::withMessages(['message' => 'Could not read the message: '.mb_substr($e->getMessage(), 0, 200)]);
        }
        if ($parsed['message_id'] !== null && ($dup = DB::table('lab_inbound_messages')->where('api_key_id', $keyId)->where('message_id', $parsed['message_id'])->first()) !== null) {
            return ['status' => 'duplicate', 'reason' => 'Already received', 'id' => (int) $dup->id];
        }
        $id = (int) DB::table('lab_inbound_messages')->insertGetId(['api_key_id' => $keyId, 'format' => $format, 'message_id' => $parsed['message_id'],
            'raw' => Crypt::encryptString($raw), 'parsed' => Crypt::encryptString((string) json_encode($parsed)), 'status' => 'received', 'created_at' => now(), 'updated_at' => now()]);

        $order = $this->findOrder($parsed['order_ref'], $parsed['barcode']);
        if (! $order instanceof LabOrder) {
            return $this->hold($id, 'No matching order — match it to the right patient and order.');
        }

        return $this->apply($id, $order, $parsed, (string) DB::table('api_keys')->where('id', $keyId)->value('name'));
    }

    /**
     * Staff match an unmatched message to an order and apply it.
     *
     * @return array{status: string, reason: ?string, id: int}
     */
    public function resolve(int $messageId, string $orderId, int $by): array
    {
        $msg = DB::table('lab_inbound_messages')->where('id', $messageId)->where('status', 'unmatched')->first();
        $order = LabOrder::query()->whereKey($orderId)->first();
        if ($msg === null || ! $order instanceof LabOrder) {
            throw ValidationException::withMessages(['order' => 'Choose an order for this result.']);
        }
        $result = $this->apply($messageId, $order, (array) json_decode(Crypt::decryptString((string) $msg->parsed), true), 'connected lab (matched by staff)');
        DB::table('lab_inbound_messages')->where('id', $messageId)->update(['resolved_by' => $by, 'resolved_at' => now()]);

        return $result;
    }

    public function reject(int $messageId, string $reason, int $by): void
    {
        if (trim($reason) === '') {
            throw ValidationException::withMessages(['reason' => 'Say why this result is rejected.']);
        }
        DB::table('lab_inbound_messages')->where('id', $messageId)->where('status', 'unmatched')
            ->update(['status' => 'rejected', 'reason' => mb_substr(trim($reason), 0, 255), 'resolved_by' => $by, 'resolved_at' => now(), 'updated_at' => now()]);
    }

    /**
     * @return array{name: ?string, dob: ?string, order_ref: ?string, barcode: ?string, results: list<array<string, mixed>>}
     */
    public function summary(int $messageId): array
    {
        $p = (array) json_decode(Crypt::decryptString((string) DB::table('lab_inbound_messages')->where('id', $messageId)->value('parsed')), true);

        return ['name' => data_get($p, 'patient.name'), 'dob' => data_get($p, 'patient.dob'), 'order_ref' => $p['order_ref'] ?? null, 'barcode' => $p['barcode'] ?? null,
            'results' => array_values((array) ($p['results'] ?? []))];
    }

    private function findOrder(?string $ref, ?string $barcode): ?LabOrder
    {
        if ($ref !== null && ($o = LabOrder::query()->whereKey(strtolower($ref))->first()) instanceof LabOrder) {
            return $o;
        }

        return $barcode === null ? null : LabOrder::query()->where('sample_barcode', $barcode)->first();
    }

    /**
     * @param  array<string, mixed>  $parsed
     * @return array{status: string, reason: ?string, id: int}
     */
    private function apply(int $id, LabOrder $order, array $parsed, string $labName): array
    {
        $results = (array) ($parsed['results'] ?? []);
        if (collect($results)->contains(fn ($r) => ($r['status'] ?? 'F') !== 'F')) {
            return $this->hold($id, 'Preliminary results — waiting for the final report.', $order->id);
        }
        $ordered = $order->results()->pluck('test_code')->map(fn ($c) => (string) $c)->all();
        $values = [];
        $flags = [];
        foreach ($results as $r) {
            $code = (string) ($r['code'] ?? '');
            if (! in_array($code, $ordered, true)) {
                return $this->hold($id, "Result for {$code} ({$r['name']}) was not on this order.", $order->id);
            }
            $values[$code] = (string) $r['value'];
            if (($r['flag'] ?? null) !== null) {
                $flags[$code] = (string) $r['flag'];
            }
        }
        if ($missing = array_values(array_diff($ordered, array_keys($values)))) {
            return $this->hold($id, 'Waiting for: '.implode(', ', $missing).'.', $order->id);
        }
        try {
            $this->workflow->applyExternalResults($order, $values, $flags, $labName);
        } catch (ValidationException $e) {
            return $this->hold($id, mb_substr((string) collect($e->errors())->flatten()->first(), 0, 255), $order->id);
        }
        DB::table('lab_inbound_messages')->where('id', $id)->update(['status' => 'applied', 'reason' => null, 'lab_order_id' => $order->id, 'updated_at' => now()]);

        return ['status' => 'applied', 'reason' => null, 'id' => $id];
    }

    /**
     * @return array{status: string, reason: ?string, id: int}
     */
    private function hold(int $id, string $reason, ?string $orderId = null): array
    {
        DB::table('lab_inbound_messages')->where('id', $id)->update(['status' => 'unmatched', 'reason' => $reason, 'lab_order_id' => $orderId, 'updated_at' => now()]);

        return ['status' => 'unmatched', 'reason' => $reason, 'id' => $id];
    }
}
