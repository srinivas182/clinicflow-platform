<?php

declare(strict_types=1);

namespace App\Domains\Lab\Actions;

use App\Domains\Identity\Models\Membership;
use App\Domains\Lab\Models\LabOrder;
use App\Domains\Messaging\Actions\SendMessage;
use App\Domains\Platform\Models\Setting;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * Results waiting for the doctor:
 *  - the patient may ask for them after 24 hours (moves them to the top of the inbox);
 *  - normal results are released automatically after 48 hours with a standard note;
 *  - abnormal and unclassified results are escalated after 48 hours, never auto-released
 *    (unless the practice allows abnormal auto-release; critical never);
 *  - critical results not acknowledged within 2 hours are escalated urgently (fixed).
 */
class LabReleaseRules
{
    public const CRITICAL_ESCALATE_HOURS = 2;

    public const NORMAL_NOTE = 'Your results are within the normal range. Your doctor has not added comments yet.';

    public const ABNORMAL_NOTE = 'Some results are outside the normal range. Please book a follow-up with your doctor.';

    public function __construct(private readonly LabWorkflow $workflow) {}

    public static function hours(string $key): int
    {
        $defaults = ['request_after_hours' => 24, 'auto_release_hours' => 48, 'escalate_hours' => 48];

        return (int) (Setting::get('lab', $key, $defaults[$key]) ?? $defaults[$key]);
    }

    public function patientRequest(LabOrder $order): LabOrder
    {
        if ($order->status !== 'verified' || $order->verified_at === null || $order->verified_at->gt(now()->subHours(self::hours('request_after_hours')))) {
            throw ValidationException::withMessages(['order' => 'You can ask for these results '.self::hours('request_after_hours').' hours after they arrive.']);
        }
        if ($order->getAttribute('patient_requested_at') === null) {
            $order->forceFill(['patient_requested_at' => now()])->save();
            activity('lab')->performedOn($order)->log('Patient asked for results');
        }

        return $order;
    }

    /**
     * @return array{released: int, escalated: int}
     */
    public function run(): array
    {
        $released = 0;
        $escalated = 0;
        $allowAbnormal = (bool) Setting::get('lab', 'auto_release_abnormal', false);

        LabOrder::query()->where('status', 'verified')->whereNotNull('ordering_staff_id')->whereNull('reviewed_at')->get()
            ->each(function (LabOrder $o) use (&$released, &$escalated, $allowAbnormal): void {
                $class = (string) $o->getAttribute('classification');
                $waited = $o->verified_at;
                if ($waited === null) {
                    return;
                }

                if ($o->has_critical) {
                    if ($o->critical_acknowledged_at === null && $o->getAttribute('escalated_at') === null && $waited->lte(now()->subHours(self::CRITICAL_ESCALATE_HOURS))) {
                        $this->escalate($o, 'URGENT: critical lab result not acknowledged');
                        $escalated++;
                    }

                    return;
                }

                if ($waited->gt(now()->subHours(self::hours('auto_release_hours')))) {
                    return;
                }
                if ($class === 'normal') {
                    $this->workflow->releaseToPatient($o, 'auto', self::NORMAL_NOTE);
                    $released++;
                } elseif ($class === 'abnormal' && $allowAbnormal) {
                    $this->workflow->releaseToPatient($o, 'auto', self::ABNORMAL_NOTE);
                    $released++;
                } elseif ($o->getAttribute('escalated_at') === null && $waited->lte(now()->subHours(self::hours('escalate_hours')))) {
                    $this->escalate($o, 'Lab result waiting for review');
                    $escalated++;
                }
            });

        return ['released' => $released, 'escalated' => $escalated];
    }

    private function escalate(LabOrder $order, string $subject): void
    {
        $order->forceFill(['escalated_at' => now()])->save();
        $leaders = User::query()->whereIn('id', Membership::query()->where('tenant_id', (string) tenant()?->getTenantKey())->whereIn('role', ['owner', 'manager'])->pluck('user_id'))->get();
        foreach ($leaders as $user) {
            app(SendMessage::class)->handle('email', $user->email, "A lab result for {$order->patient->fullName()} needs a doctor's review now.", $subject, 'lab_order', $order->id);
        }
        activity('lab')->performedOn($order)->withProperties(['reason' => $subject])->log('Lab result escalated');
    }
}
