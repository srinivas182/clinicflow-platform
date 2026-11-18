<?php

use App\Domains\Billing\Models\SubscriptionInvoice;
use App\Domains\Identity\Actions\AddStaffMember;
use App\Domains\Identity\Enums\StaffRole;
use App\Domains\Platform\Actions\EnforceSubscriptionStatus;
use App\Domains\Platform\Actions\SendSubscriptionReminders;
use App\Domains\Platform\Enums\ProviderStatus;
use App\Domains\Platform\Enums\ProviderType;
use App\Domains\Platform\Enums\SubscriptionStatus;
use App\Domains\Platform\Models\Package;
use App\Domains\Platform\Models\Provider;
use App\Domains\Platform\Models\Subscription;
use App\Domains\Platform\Notifications\PracticeNotice;
use App\Domains\Platform\Support\StatusPage;
use App\Domains\Platform\SupportDesk\SupportDesk;
use App\Http\Middleware\RequireRecentConfirmation;
use App\Models\User;
use Database\Seeders\PackageSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;

/*
 * Beta testing T2: subscription journey and notifications (tracker rows 9, 10, 11, 220, 236, 286 and
 * practice tab row 4). Verification (3, 5, 13–17) is in SignupAndVerificationTest; WhatsApp consent
 * (252) in WhatsAppDeliveryComparisonTest.
 */

uses(DatabaseMigrations::class);

beforeEach(function (): void {
    if (config('database.default') !== 'mysql') {
        $this->markTestSkipped('Tenancy tests require MySQL.');
    }
    Notification::fake();
    $this->seed(PackageSeeder::class);
    $this->clinic = makeProvider('Sunrise Medical Centre', ProviderType::Clinic, 'sunrise.clinicflow.test');
    $this->owner = User::factory()->create(['email' => 'owner@sunrise.test']);
    app(AddStaffMember::class)->handle($this->clinic, $this->owner, StaffRole::Owner);
    $this->free = Package::query()->where('code', 'clinic-starter')->firstOrFail();
    $this->free->forceFill(['price_monthly_cents' => 0, 'price_annual_cents' => 0])->save();
    $this->paid = Package::query()->where('code', 'clinic-standard')->firstOrFail();
    $this->sub = Subscription::create(['tenant_id' => $this->clinic->id, 'package_id' => $this->free->id, 'status' => SubscriptionStatus::Active]);
    $this->clinic->status = ProviderStatus::Active;
    $this->clinic->save();
    $this->base = 'http://sunrise.clinicflow.test';
});

afterEach(function (): void {
    tenancy()->end();
    Provider::query()->get()->each->delete();
});

it('shows Subscription in the menu and upgrades from the free plan with an invoice to pay now (beta #9, practice row 4, #11)', function (): void {
    $this->actingAs($this->owner)->get("{$this->base}/settings/subscription")
        ->assertInertia(fn ($p) => $p->component('Settings/Subscription')->where('plan.packageId', $this->free->id)
            ->where('plans', fn ($plans) => collect($plans)->pluck('id')->contains($this->paid->id))
            ->where('auth.permissions', fn ($perms) => collect($perms)->contains('payments.configure')));

    $this->actingAs($this->owner)->post("{$this->base}/settings/subscription/plan", ['package_id' => $this->paid->id, 'billing_period' => 'monthly'])->assertSessionHasNoErrors();
    $sub = $this->sub->fresh();
    $invoice = SubscriptionInvoice::query()->sole();
    expect($sub?->package_id)->toBe($this->paid->id)->and($sub?->status)->toBe(SubscriptionStatus::Active)
        ->and($invoice->status)->toBe('open')->and($invoice->amount_cents)->toBe($this->paid->price_monthly_cents);
    Notification::assertSentTo($this->owner, PracticeNotice::class, fn (PracticeNotice $n) => $n->kind === 'invoice_issued' && str_contains((string) $n->actionUrl, $invoice->checkout_token));
    Notification::assertSentTo($this->owner, PracticeNotice::class, fn (PracticeNotice $n) => $n->kind === 'plan_changed');

    // Unpaid more than 7 days after the paid period started: read-only, with a Pay now banner.
    $sub?->forceFill(['current_period_ends_at' => now()->subDays(8)])->save();
    app(EnforceSubscriptionStatus::class)->handle();
    expect($this->clinic->fresh()?->status)->toBe(ProviderStatus::ReadOnly);
    $this->actingAs($this->owner)->get("{$this->base}/settings/subscription")
        ->assertInertia(fn ($p) => $p->where('workspaceNotice.action', 'Pay now')->where('workspaceNotice.href', fn (string $h) => str_contains($h, $invoice->checkout_token)));

    // Moving back to the free plan works while read-only: the unpaid invoice is voided, the workspace opens.
    $this->actingAs($this->owner)->post("{$this->base}/settings/subscription/plan", ['package_id' => $this->free->id, 'billing_period' => 'monthly'])->assertSessionHasNoErrors();
    expect($invoice->fresh()?->status)->toBe('void')->and($this->clinic->fresh()?->status)->toBe(ProviderStatus::Active);
});

it('warns about a trial ending in the workspace and by email at 7, 3 and 1 days, and reminds of invoices due (beta #10, #11)', function (): void {
    $this->sub->forceFill(['package_id' => $this->paid->id, 'status' => SubscriptionStatus::Trialing, 'trial_ends_at' => now()->addDays(6)])->save();
    $this->clinic->status = ProviderStatus::Trial;
    $this->clinic->save();
    $reminders = app(SendSubscriptionReminders::class);

    expect($reminders->handle())->toBe(1)->and($reminders->handle())->toBe(0);
    $this->sub->forceFill(['trial_ends_at' => now()->addDays(3)->addHour()])->save();
    expect($reminders->handle())->toBe(1);
    $this->sub->forceFill(['trial_ends_at' => now()->addHours(20)])->save();
    expect($reminders->handle())->toBe(1);
    Notification::assertSentToTimes($this->owner, PracticeNotice::class, 3);

    $this->actingAs($this->owner)->get("{$this->base}/workspace")->assertInertia(fn ($p) => $p->where('workspaceNotice.text', 'Your free trial ends tomorrow.'));

    $invoice = SubscriptionInvoice::create(['number' => 'CF-2026-000201', 'tenant_id' => $this->clinic->id, 'subscription_id' => $this->sub->id, 'period_start' => now()->addDays(3), 'period_end' => now()->addMonth(),
        'amount_cents' => 149000, 'messaging_units' => 0, 'messaging_overage_cents' => 0, 'vat_cents' => 22350, 'total_cents' => 171350, 'status' => 'open', 'checkout_token' => 'tok-201', 'due_at' => now()->addDays(3)]);
    $invoice->forceFill(['created_at' => now()->subDays(2)])->save();
    expect($reminders->handle())->toBe(1)->and($reminders->handle())->toBe(0);
    Notification::assertSentTo($this->owner, PracticeNotice::class, fn (PracticeNotice $n) => $n->kind === 'invoice_due');
});

it('emails the practice when Support replies and the support team when a ticket is opened (beta #220)', function (): void {
    $admin = User::factory()->create();
    $admin->forceFill(['is_platform_admin' => true])->save();
    $desk = app(SupportDesk::class);
    $ticket = $desk->open($this->clinic, $this->owner, 'Cannot print invoices', 'The PDF is blank.');
    Notification::assertSentTo($admin, PracticeNotice::class, fn (PracticeNotice $n) => $n->kind === 'support_opened');
    $desk->reply($ticket, $admin, 'support', 'Looking now.');
    Notification::assertSentTo($this->owner, PracticeNotice::class, fn (PracticeNotice $n) => $n->kind === 'support_reply' && in_array('Looking now.', $n->lines, true));
});

it('emails practice owners when a major incident starts and ends, with a working opt-out (beta #236)', function (): void {
    $status = app(StatusPage::class);
    $status->report('Minor glitch', 'incident', 'minor', [], 'Checking.');
    Notification::assertNothingSent();

    $id = $status->report('Card payments failing', 'incident', 'major', ['payments'], 'Investigating.');
    $status->update($id, 'identified', 'Payment provider outage.');
    $status->update($id, 'resolved', 'Fixed.');
    Notification::assertSentToTimes($this->owner, PracticeNotice::class, 2);

    $this->get(URL::signedRoute('status-emails.off', ['user' => $this->owner->id]))->assertOk()->assertSee('no longer receive');
    $this->get(route('status-emails.off', ['user' => $this->owner->id]))->assertForbidden();
    $status->report('Video calls down', 'incident', 'critical', [], 'Investigating.');
    Notification::assertSentToTimes($this->owner, PracticeNotice::class, 2);
});

it('tells practices on a package when its messaging allowance or message types change (beta #286)', function (): void {
    $admin = User::factory()->create();
    $admin->forceFill(['is_platform_admin' => true])->save();
    $limits = $this->free->limits;
    $this->actingAs($admin)->withSession([RequireRecentConfirmation::SESSION_KEY => now()->getTimestamp()])->put("http://localhost/admin/messaging/packages/{$this->free->id}", [
        'sms' => ($limits['sms'] ?? 0) + 100, 'email' => $limits['email'] ?? 0, 'sms_overage' => ($limits['sms_overage_cents'] ?? 0) / 100, 'email_overage' => ($limits['email_overage_cents'] ?? 0) / 100,
        'message_types' => ['msg_booking'],
    ])->assertSessionHasNoErrors();
    Notification::assertSentTo($this->owner, PracticeNotice::class, fn (PracticeNotice $n) => $n->kind === 'package_changed'
        && collect($n->lines)->contains(fn (string $l) => str_starts_with($l, 'Included SMS per month:')));
});
