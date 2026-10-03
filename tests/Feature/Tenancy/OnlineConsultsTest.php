<?php

use App\Domains\Billing\Actions\RecordPayment;
use App\Domains\Billing\Enums\PaymentMethod;
use App\Domains\Billing\Models\Invoice;
use App\Domains\Billing\Models\Payment;
use App\Domains\Clinical\Actions\CompleteConsultation;
use App\Domains\Clinical\Actions\SaveConsultation;
use App\Domains\Clinical\Models\Consultation;
use App\Domains\Identity\Actions\AddStaffMember;
use App\Domains\Identity\Enums\StaffRole;
use App\Domains\Identity\Models\Staff;
use App\Domains\Messaging\Contracts\MessageSender;
use App\Domains\Messaging\Support\LogMessageSender;
use App\Domains\Patients\Models\Patient;
use App\Domains\Platform\Enums\ProviderType;
use App\Domains\Platform\Enums\SubscriptionStatus;
use App\Domains\Platform\Models\Package;
use App\Domains\Platform\Models\Provider;
use App\Domains\Platform\Models\Subscription;
use App\Domains\Scheduling\Actions\BookAppointment;
use App\Domains\Scheduling\Enums\AppointmentStatus;
use App\Domains\Scheduling\Enums\ConsultType;
use App\Domains\Scheduling\Models\Appointment;
use App\Domains\Telemedicine\Actions\OnlineBooking;
use App\Domains\Telemedicine\Actions\TrackTeleSession;
use App\Domains\Telemedicine\Models\ChatThread;
use App\Domains\Telemedicine\Models\RefundTask;
use App\Domains\Telemedicine\Models\TeleAvailability;
use App\Domains\Telemedicine\Models\TeleException;
use App\Domains\Telemedicine\Models\TelePrice;
use App\Domains\Telemedicine\Models\TeleSession;
use App\Domains\Telemedicine\Support\OnlineSlots;
use App\Domains\Visits\Enums\VisitStage;
use App\Domains\Wallet\Models\Wallet;
use App\Domains\Wallet\Models\WalletReservation;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\ClinicalReferenceSeeder;
use Database\Seeders\PackageSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

uses(DatabaseMigrations::class);

beforeEach(function (): void {
    if (config('database.default') !== 'mysql') {
        $this->markTestSkipped('Tenancy tests require MySQL.');
    }

    config(['clinicflow.payments.allow_fake' => true]);
    $this->app->instance(MessageSender::class, $this->sms = new LogMessageSender);
    $this->seed([PackageSeeder::class, ClinicalReferenceSeeder::class]);
    $this->clinic = makeProvider('Sunrise Medical Centre', ProviderType::Clinic, 'sunrise.clinicflow.test');
    $this->doctorUser = User::factory()->create(['name' => 'Dr Naidoo']);
    app(AddStaffMember::class)->handle($this->clinic, $this->doctorUser, StaffRole::Doctor);
    Subscription::create([
        'tenant_id' => $this->clinic->id, 'package_id' => Package::query()->where('code', 'clinic-starter')->value('id'),
        'status' => SubscriptionStatus::Active, 'current_period_ends_at' => now()->addMonth(), 'addons' => ['telemedicine'],
    ]);
    Wallet::for($this->clinic->id)->forceFill(['balance_cents' => 100000])->save();

    tenancy()->initialize($this->clinic);
    $this->doctor = Staff::query()->findOrFail($this->doctorUser->id);
    $this->day = CarbonImmutable::now()->addDay()->startOfDay();
    TeleAvailability::create(['staff_id' => $this->doctor->id, 'mode' => 'video', 'weekday' => $this->day->dayOfWeek, 'start_time' => '09:00', 'end_time' => '10:00']);
    TeleAvailability::create(['staff_id' => $this->doctor->id, 'mode' => 'chat', 'weekday' => $this->day->dayOfWeek, 'start_time' => '14:00', 'end_time' => '15:00']);
    foreach ([['video', 15, 35000], ['video', 30, 60000], ['chat', 15, 20000]] as [$mode, $min, $cents]) {
        TelePrice::create(['staff_id' => null, 'mode' => $mode, 'duration_minutes' => $min, 'price_cents' => $cents]);
    }
    $this->patient = registerTestPatient('Thandi', '880412', null, '0825550147');
    $this->booking = app(OnlineBooking::class);
});

afterEach(function (): void {
    tenancy()->end();
    Provider::query()->get()->each->delete();
});

function at(object $t, string $time): CarbonImmutable
{
    return $t->day->setTimeFromTimeString($time);
}

function payCash(Appointment $a): void
{
    app(RecordPayment::class)->handle(Invoice::query()->where('visit_id', $a->getAttribute('visit_id'))->sole(), PaymentMethod::Cash, (int) $a->getAttribute('price_cents'));
}

it('offers only times inside the doctor\'s hours for that mode, minus exceptions and bookings', function (): void {
    $video = fn (int $min = 15) => OnlineSlots::for($this->doctor->id, 'video', $min, $this->day);

    expect($video())->toBe(['09:00', '09:15', '09:30', '09:45'])
        ->and($video(30))->toBe(['09:00', '09:15', '09:30'])
        ->and(OnlineSlots::for($this->doctor->id, 'audio', 15, $this->day))->toBe([]);

    TeleException::create(['staff_id' => $this->doctor->id, 'date' => $this->day, 'type' => 'off', 'start_time' => '09:15', 'end_time' => '09:45']);
    TeleException::create(['staff_id' => $this->doctor->id, 'date' => $this->day, 'type' => 'extra', 'mode' => 'video', 'start_time' => '16:00', 'end_time' => '16:30']);
    expect($video())->toBe(['09:00', '09:45', '16:00', '16:15']);

    $this->booking->book($this->patient, $this->doctor, ConsultType::Video, 15, at($this, '16:00'));
    expect($video())->toBe(['09:00', '09:45', '16:15']);

    TeleException::create(['staff_id' => $this->doctor->id, 'date' => $this->day, 'type' => 'off']);
    expect($video())->toBe([]);
});

it('holds the slot while the patient pays, confirms on payment and releases an unpaid hold', function (): void {
    $a = $this->booking->book($this->patient, $this->doctor, ConsultType::Video, 30, at($this, '09:00'));

    expect($a->getAttribute('payment_status'))->toBe('pending')
        ->and($a->ends_at->format('H:i'))->toBe('09:30')
        ->and(Wallet::for($this->clinic->id)->reserved_cents)->toBe(30 * 250)
        ->and(Consultation::query()->where('visit_id', $a->getAttribute('visit_id'))->exists())->toBeTrue()
        ->and(Invoice::query()->where('visit_id', $a->getAttribute('visit_id'))->sole()->total_cents)->toBe(60000)
        ->and(fn () => $this->booking->book($this->patient, $this->doctor, ConsultType::Video, 15, at($this, '09:15')))->toThrow(ValidationException::class);

    $payment = $this->booking->payLink($a);
    app(RecordPayment::class)->confirm($payment);
    expect($a->fresh()?->getAttribute('payment_status'))->toBe('paid')
        ->and(DB::table('message_log')->where('related_id', $a->id)->where('recipient', '0825550147')->exists())->toBeTrue();

    $b = $this->booking->book($this->patient, $this->doctor, ConsultType::Video, 15, at($this, '09:30'));
    $this->travel(11)->minutes();
    expect($this->booking->expireHolds())->toBe(1)
        ->and($b->fresh()?->status)->toBe(AppointmentStatus::Cancelled)
        ->and(Invoice::query()->where('visit_id', $b->getAttribute('visit_id'))->sole()->status->value)->toBe('void')
        ->and(Wallet::for($this->clinic->id)->reserved_cents)->toBe(30 * 250);
});

it('applies the cancellation policy and turns non-API refunds into practice tasks', function (): void {
    $early = $this->booking->book($this->patient, $this->doctor, ConsultType::Video, 15, at($this, '09:00'));
    payCash($early);
    $this->booking->cancel($early->fresh(), 'patient', 'Feeling better');
    $task = RefundTask::query()->sole();
    expect($task->amount_cents)->toBe(35000)->and($early->fresh()?->getAttribute('payment_status'))->toBe('refund_due');

    $this->booking->completeRefundTask($task, 'EFT-REF-77');
    expect($early->fresh()?->getAttribute('payment_status'))->toBe('refunded')
        ->and(Payment::query()->find($task->payment_id)?->refunded_cents)->toBe(35000);

    $late = $this->booking->book($this->patient, $this->doctor, ConsultType::Video, 15, at($this, '09:15'));
    payCash($late);
    $this->travelTo(at($this, '08:00'));
    $this->booking->cancel($late->fresh(), 'patient', 'Running late');
    expect(RefundTask::query()->count())->toBe(1)->and($late->fresh()?->getAttribute('payment_status'))->toBe('paid');

    $practice = $this->booking->book($this->patient, $this->doctor, ConsultType::Video, 15, at($this, '09:30'));
    payCash($practice);
    $this->booking->cancel($practice->fresh(), 'practice', 'Doctor called away');
    expect(RefundTask::query()->count())->toBe(2);
});

it('refunds the patient in full when the doctor has not joined after 10 minutes', function (): void {
    $a = $this->booking->book($this->patient, $this->doctor, ConsultType::Video, 15, at($this, '09:00'));
    payCash($a);

    $this->travelTo(at($this, '09:09'));
    expect($this->booking->refundDoctorNoShows())->toBe(0);
    $this->travelTo(at($this, '09:11'));
    expect($this->booking->refundDoctorNoShows())->toBe(1)
        ->and($a->fresh()?->status)->toBe(AppointmentStatus::Cancelled)
        ->and(RefundTask::query()->sole()->reason)->toContain('practice');
});

it('extends a running consult only after the patient pays for the extra time', function (): void {
    $a = $this->booking->book($this->patient, $this->doctor, ConsultType::Video, 15, at($this, '09:00'));
    payCash($a);
    TeleSession::query()->sole()->forceFill(['doctor_joined_at' => at($this, '09:00'), 'patient_joined_at' => at($this, '09:00'), 'connected_at' => at($this, '09:00'), 'status' => 'live'])->save();

    $payment = $this->booking->extend($a->fresh());
    expect($payment->amount_cents)->toBe(60000 - 35000)
        ->and($a->fresh()?->ends_at->format('H:i'))->toBe('09:15')
        ->and(fn () => $this->booking->extend($a->fresh()))->toThrow(ValidationException::class);

    app(RecordPayment::class)->confirm($payment);
    expect($a->fresh()?->ends_at->format('H:i'))->toBe('09:30')
        ->and($a->fresh()?->getAttribute('duration_minutes'))->toBe(30)
        ->and(WalletReservation::query()->where('reference', 'like', '%-ext%')->count())->toBe(1);

    $this->travelTo(at($this, '09:34'));
    $this->booking->closeFinished(app(TrackTeleSession::class));
    expect(TeleSession::query()->sole()->charged_minutes)->toBe(34)
        ->and(WalletReservation::query()->where('status', 'held')->count())->toBe(0)
        ->and(ChatThread::query()->where('kind', 'followup')->sole()->closes_at->toDateString())->toBe(at($this, '09:34')->addDays(3)->toDateString());
});

it('runs a chat consult inside its window and charges one session only if both took part', function (): void {
    $a = $this->booking->book($this->patient, $this->doctor, ConsultType::Chat, 15, at($this, '14:00'));
    payCash($a);
    $thread = ChatThread::query()->where('kind', 'consult')->sole();

    $this->travelTo(at($this, '13:55'));
    expect($thread->isOpen())->toBeFalse();

    $this->travelTo(at($this, '14:02'));
    $thread->messages()->create(['sender' => 'patient', 'body' => 'Sore throat since Monday', 'created_at' => now()]);
    $thread->messages()->create(['sender' => 'doctor', 'body' => 'Any fever?', 'created_at' => now()]);

    $this->travelTo(at($this, '14:19'));
    $this->booking->closeFinished(app(TrackTeleSession::class));

    expect($a->fresh()?->status)->toBe(AppointmentStatus::Completed)
        ->and(Wallet::for($this->clinic->id)->balance_cents)->toBe(100000 - 1200)
        ->and(ChatThread::query()->where('kind', 'followup')->count())->toBe(1);
});

it('gives each online consult a virtual visit so notes and prescribing work without queue stages', function (): void {
    $a = $this->booking->book($this->patient, $this->doctor, ConsultType::Video, 15, at($this, '09:00'));
    payCash($a);
    $consult = Consultation::query()->where('visit_id', $a->getAttribute('visit_id'))->sole();

    app(SaveConsultation::class)->handle($consult, ['assessment' => 'Viral URTI'], [['code' => 'J06.9', 'primary' => true]], 1);
    app(CompleteConsultation::class)->handle($consult->fresh());

    expect($consult->visit->fresh()?->stage)->toBe(VisitStage::Done)
        ->and($consult->visit->check_in_channel)->toBe('online')
        ->and(fn () => app(BookAppointment::class)->handle(Patient::query()->firstOrFail(), $this->doctor, at($this, '09:30'), ConsultType::Video))->toThrow(ValidationException::class);
});
