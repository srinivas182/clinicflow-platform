<?php

use App\Domains\Identity\Actions\AddStaffMember;
use App\Domains\Identity\Enums\StaffRole;
use App\Domains\Identity\Models\Staff;
use App\Domains\Lab\Actions\LabWorkflow;
use App\Domains\Lab\Models\LabOrder;
use App\Domains\Messaging\Contracts\MessageSender;
use App\Domains\Messaging\Support\LogMessageSender;
use App\Domains\Platform\Enums\ProviderType;
use App\Domains\Platform\Enums\SubscriptionStatus;
use App\Domains\Platform\Models\Package;
use App\Domains\Platform\Models\Provider;
use App\Domains\Platform\Models\Subscription;
use App\Domains\Scribe\Actions\AiScribe;
use App\Domains\Scribe\Models\AiProvider;
use App\Domains\Visits\Actions\TransitionVisit;
use App\Domains\Visits\Enums\PayerType;
use App\Domains\Visits\Enums\VisitStage;
use App\Models\User;
use Database\Seeders\ClinicalReferenceSeeder;
use Database\Seeders\PackageSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

uses(DatabaseMigrations::class);

beforeEach(function (): void {
    if (config('database.default') !== 'mysql') {
        $this->markTestSkipped('Tenancy tests require MySQL.');
    }
    $this->artisan('migrate:fresh', ['--database' => 'hub', '--path' => 'database/migrations/hub'])->assertSuccessful();
    $this->seed(ClinicalReferenceSeeder::class);
    $this->seed(PackageSeeder::class);
    $this->app->instance(MessageSender::class, new LogMessageSender);
    $this->clinic = makeProvider('Sunrise Medical Centre', ProviderType::Clinic, 'sunrise.clinicflow.test');
    $this->sub = Subscription::create(['tenant_id' => $this->clinic->id, 'package_id' => Package::query()->where('code', 'clinic-pro')->value('id'),
        'status' => SubscriptionStatus::Active, 'current_period_ends_at' => now()->addMonth(), 'addons' => ['ai_scribe']]);
    $this->doctorUser = User::factory()->create(['name' => 'Dr Mokoena']);
    app(AddStaffMember::class)->handle($this->clinic, $this->doctorUser, StaffRole::Doctor);
    AiProvider::query()->create(['driver' => 'anthropic', 'kind' => 'notes', 'enabled' => true, 'credentials' => ['api_key' => 'sk-test']]);
    tenancy()->initialize($this->clinic);
    $this->doctor = Staff::query()->findOrFail($this->doctorUser->id);
    $this->patient = registerTestPatient('Thandi', '880412');
    tenancy()->end();
});

afterEach(function (): void {
    tenancy()->end();
    Provider::query()->get()->each->delete();
});

function verifiedOrder(object $t, array $flags = []): LabOrder
{
    return $t->clinic->run(function () use ($t, $flags): LabOrder {
        $lab = app(LabWorkflow::class);
        $visit = seenByDoctor($t, $t->patient, PayerType::Cash)->visit;
        $order = $lab->order($visit, $t->doctor, ['GLU', 'K']);
        // Close the visit so the same patient can be seen again today (as the other lab tests do).
        app(TransitionVisit::class)->handle($visit->fresh(), VisitStage::Done);

        return $lab->applyExternalResults($order, ['GLU' => '5.1', 'K' => '4.2'], $flags, 'Test lab');
    });
}

it('drafts a plain-language explanation without the patient\'s name or ID, for one AI minute', function (): void {
    $order = verifiedOrder($this);
    Http::fake(['api.anthropic.com/*' => Http::response(['content' => [['type' => 'text', 'text' => 'Your blood sugar and potassium are in the usual range. Please speak to your doctor if you have any questions.']]])]);

    $this->actingAs($this->doctorUser)->postJson("http://sunrise.clinicflow.test/lab-orders/{$order->id}/explain")->assertOk()->assertJsonPath('text', fn ($t) => str_contains($t, 'usual range'));

    Http::assertSent(fn (HttpRequest $r) => str_contains($r->body(), 'claude-haiku-4-5') && str_contains($r->body(), '30-39') && ! str_contains($r->body(), 'Thandi')
        && ! str_contains($r->body(), (string) saId('880412')));
    $this->clinic->run(fn () => expect(app(AiScribe::class)->allowance($this->clinic->id)['used'])->toBe(1)
        ->and(DB::table('lab_explanations')->count())->toBe(1));
});

it('refuses critical results and practices without AI, and charges nothing when drafting fails', function (): void {
    $critical = verifiedOrder($this, ['GLU' => 'critical']);
    Http::fake(['api.anthropic.com/*' => Http::response('overloaded', 529)]);
    $this->actingAs($this->doctorUser)->postJson("http://sunrise.clinicflow.test/lab-orders/{$critical->id}/explain")->assertStatus(422);
    Http::assertNothingSent();

    $normal = verifiedOrder($this);
    $this->actingAs($this->doctorUser)->postJson("http://sunrise.clinicflow.test/lab-orders/{$normal->id}/explain")->assertStatus(422)->assertJsonPath('errors.order.0', fn ($m) => str_contains($m, 'Nothing was charged'));
    $this->clinic->run(fn () => expect(app(AiScribe::class)->allowance($this->clinic->id)['used'])->toBe(0)->and(DB::table('lab_explanations')->count())->toBe(0));

    $this->sub->update(['addons' => []]);
    $this->actingAs($this->doctorUser)->postJson("http://sunrise.clinicflow.test/lab-orders/{$normal->id}/explain")->assertStatus(422);
});

it('marks a released AI-assisted note as reviewed by the doctor, and the patient sees that', function (): void {
    $order = verifiedOrder($this);
    $this->actingAs($this->doctorUser)->post("http://sunrise.clinicflow.test/lab-orders/{$order->id}/release", ['note' => 'Your results are in the usual range.', 'ai_assisted' => '1'])->assertSessionHasNoErrors();
    $this->clinic->run(function () use ($order): void {
        $o = LabOrder::query()->findOrFail($order->id);
        expect($o->status)->toBe('released')->and((bool) $o->getAttribute('note_ai_assisted'))->toBeTrue()->and($o->getAttribute('note_reviewed_by'))->toBe($this->doctorUser->id);
    });
    $this->withSession(['portal_cell' => '0825550147'])->get('http://sunrise.clinicflow.test/my/results')->assertOk()
        ->assertInertia(fn ($p) => $p->where('orders.0.noteAi', 'Dr Mokoena'));
});
