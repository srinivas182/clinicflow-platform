<?php

use App\Domains\Api\Actions\ApiKeys;
use App\Domains\Identity\Actions\AddStaffMember;
use App\Domains\Identity\Enums\StaffRole;
use App\Domains\Identity\Models\Staff;
use App\Domains\Lab\Actions\LabWorkflow;
use App\Domains\Lab\Inbound\LabConnections;
use App\Domains\Lab\Models\LabOrder;
use App\Domains\Messaging\Contracts\MessageSender;
use App\Domains\Messaging\Support\LogMessageSender;
use App\Domains\Platform\Enums\ProviderType;
use App\Domains\Platform\Enums\SubscriptionStatus;
use App\Domains\Platform\Models\Package;
use App\Domains\Platform\Models\Provider;
use App\Domains\Platform\Models\Subscription;
use App\Domains\Visits\Enums\PayerType;
use App\Models\User;
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
    $this->artisan('migrate:fresh', ['--database' => 'hub', '--path' => 'database/migrations/hub'])->assertSuccessful();
    $this->seed(ClinicalReferenceSeeder::class);
    $this->seed(PackageSeeder::class);
    $this->app->instance(MessageSender::class, new LogMessageSender);
    $this->clinic = makeProvider('Sunrise Medical Centre', ProviderType::Clinic, 'sunrise.clinicflow.test');
    Subscription::create(['tenant_id' => $this->clinic->id, 'package_id' => Package::query()->where('code', 'clinic-pro')->value('id'), 'status' => SubscriptionStatus::Active, 'current_period_ends_at' => now()->addMonth()]);
    $this->doctorUser = User::factory()->create();
    $this->managerUser = User::factory()->create();
    app(AddStaffMember::class)->handle($this->clinic, $this->doctorUser, StaffRole::Doctor);
    app(AddStaffMember::class)->handle($this->clinic, $this->managerUser, StaffRole::Manager);
    tenancy()->initialize($this->clinic);
    $this->doctor = Staff::query()->findOrFail($this->doctorUser->id);
    $this->order = app(LabWorkflow::class)->order(seenByDoctor($this, registerTestPatient('Thandi', '880412'), PayerType::Cash)->visit, $this->doctor, ['GLU', 'K']);
    $this->key = app(ApiKeys::class)->create('Precise LIS', ['lab:write'], [], null, 1)['key'];
    $this->noScope = app(ApiKeys::class)->create('Website', ['prices:read'], [], null, 1)['key'];
    tenancy()->end();
    $this->base = 'http://sunrise.clinicflow.test/api/lab/v1';
});

afterEach(function (): void {
    tenancy()->end();
    Provider::query()->get()->each->delete();
});

function oru(string $controlId, string $orderRef, array $obx): string
{
    $lines = ["MSH|^~\\&|LIS|PRECISE|CLINICFLOW|SUNRISE|20261101090000||ORU^R01|{$controlId}|P|2.5", 'PID|1||X1||Test^Thandi||19880412|F', "OBR|1|{$orderRef}|BC123|PANEL"];
    foreach ($obx as $i => [$code, $value, $unit, $flag, $status]) {
        // OBX-5 value, OBX-6 units, OBX-7 reference range, OBX-8 abnormal flag, OBX-11 result status.
        $lines[] = 'OBX|'.($i + 1)."|NM|{$code}^{$code}||{$value}|{$unit}||{$flag}|||{$status}";
    }

    return implode("\r", $lines);
}

it('applies final HL7 results through the normal classification, lets the lab raise a flag, and ignores duplicates', function (): void {
    $msg = oru('MSG001', strtoupper($this->order->id), [['GLU', '5.1', 'mmol/L', 'HH', 'F'], ['K', '4.2', 'mmol/L', 'N', 'F']]);
    $this->call('POST', "{$this->base}/hl7", [], [], [], ['HTTP_AUTHORIZATION' => "Bearer {$this->key}", 'CONTENT_TYPE' => 'x-application/hl7-v2+er7'], $msg)
        ->assertOk()->assertSee('MSA|AA|MSG001|Results applied', false);

    $this->clinic->run(function (): void {
        $order = LabOrder::query()->findOrFail($this->order->id);
        $flags = DB::table('lab_results')->where('lab_order_id', $order->id)->pluck('flag', 'test_code')->all();
        expect($order->status)->toBe('verified')->and($order->verified_at)->not->toBeNull()->and($order->has_critical)->toBeTrue()
            ->and($flags['GLU'])->toBe('critical')->and($flags['K'])->toBe('normal')
            ->and(DB::table('lab_inbound_messages')->value('raw'))->not->toContain('PID|1');
    });

    $this->call('POST', "{$this->base}/hl7", [], [], [], ['HTTP_AUTHORIZATION' => "Bearer {$this->key}", 'CONTENT_TYPE' => 'x-application/hl7-v2+er7'], $msg)
        ->assertOk()->assertSee('Already received', false);
    $this->clinic->run(fn () => expect(DB::table('lab_inbound_messages')->count())->toBe(1));
});

it('holds preliminary, partial and unmatched results for staff, who match or reject them', function (): void {
    $send = fn (string $body) => $this->call('POST', "{$this->base}/hl7", [], [], [], ['HTTP_AUTHORIZATION' => "Bearer {$this->key}", 'CONTENT_TYPE' => 'x-application/hl7-v2+er7'], $body);
    $send(oru('P1', $this->order->id, [['GLU', '5.1', 'mmol/L', '', 'P'], ['K', '4.2', 'mmol/L', '', 'P']]))->assertSee('held for review: Preliminary', false);
    $send(oru('P2', $this->order->id, [['GLU', '5.1', 'mmol/L', '', 'F']]))->assertSee('Waiting for: K', false);
    $send(oru('P3', 'UNKNOWN-ORDER', [['GLU', '5.1', 'mmol/L', '', 'F'], ['K', '4.2', 'mmol/L', '', 'F']]))->assertSee('No matching order', false);
    $send('not hl7')->assertStatus(400)->assertSee('MSA|AE', false);
    $this->call('POST', "{$this->base}/hl7", [], [], [], ['HTTP_AUTHORIZATION' => "Bearer {$this->noScope}"], oru('X', 'Y', []))->assertForbidden();

    $ids = $this->clinic->run(fn () => DB::table('lab_inbound_messages')->orderBy('id')->pluck('id')->all());
    $this->clinic->run(fn () => expect(DB::table('lab_inbound_messages')->where('status', 'unmatched')->count())->toBe(3)
        ->and(LabOrder::query()->findOrFail($this->order->id)->status)->toBe('ordered'));

    $this->actingAs($this->managerUser)->get('http://sunrise.clinicflow.test/lab/unmatched')->assertOk()
        ->assertInertia(fn ($p) => $p->component('Lab/Unmatched')->has('messages', 3)->where('messages.2.name', 'Thandi Test')->has('openOrders', 1));
    $this->actingAs($this->managerUser)->post("http://sunrise.clinicflow.test/lab/unmatched/{$ids[0]}/reject", ['reason' => ''])->assertSessionHasErrors('reason');
    $this->actingAs($this->managerUser)->post("http://sunrise.clinicflow.test/lab/unmatched/{$ids[0]}/reject", ['reason' => 'Preliminary; final report expected'])->assertSessionHasNoErrors();
    $this->actingAs($this->managerUser)->post("http://sunrise.clinicflow.test/lab/unmatched/{$ids[2]}/match", ['order_id' => $this->order->id])->assertSessionHas('success');

    $this->clinic->run(fn () => expect(LabOrder::query()->findOrFail($this->order->id)->status)->toBe('verified')
        ->and(DB::table('lab_inbound_messages')->where('id', $ids[2])->value('status'))->toBe('applied')
        ->and(DB::table('lab_inbound_messages')->where('id', $ids[0])->value('status'))->toBe('rejected'));
});

it('accepts FHIR DiagnosticReports matched by sample barcode', function (): void {
    $this->clinic->run(fn () => DB::table('lab_orders')->where('id', $this->order->id)->update(['sample_barcode' => 'BC-777']));
    $bundle = ['resourceType' => 'Bundle', 'id' => 'b-1', 'type' => 'collection', 'entry' => [
        ['resource' => ['resourceType' => 'DiagnosticReport', 'id' => 'r1', 'status' => 'final', 'specimen' => [['identifier' => ['value' => 'BC-777']]],
            'result' => [['reference' => 'Observation/o1'], ['reference' => 'Observation/o2']]]],
        ['resource' => ['resourceType' => 'Observation', 'id' => 'o1', 'status' => 'final', 'code' => ['coding' => [['code' => 'GLU']]], 'valueQuantity' => ['value' => 5.0, 'unit' => 'mmol/L']]],
        ['resource' => ['resourceType' => 'Observation', 'id' => 'o2', 'status' => 'final', 'code' => ['coding' => [['code' => 'K']]], 'valueQuantity' => ['value' => 4.0, 'unit' => 'mmol/L']]],
    ]];
    $this->withHeaders(['Authorization' => "Bearer {$this->key}"])->postJson("{$this->base}/fhir", $bundle)->assertStatus(201)->assertJsonPath('issue.0.diagnostics', 'Results applied');
    $this->clinic->run(fn () => expect(LabOrder::query()->findOrFail($this->order->id)->status)->toBe('verified'));
});

it('translates a lab system\'s own test codes through the mapping before filing results', function (): void {
    $keyId = $this->clinic->run(function (): int {
        $id = (int) DB::table('api_keys')->where('name', 'Precise LIS')->value('id');
        $c = app(LabConnections::class);
        $c->map($id, 'gluc', 'GLU');
        $c->map($id, 'POT', 'K');

        return $id;
    });
    expect($keyId)->toBeGreaterThan(0);
    $this->call('POST', "{$this->base}/hl7", [], [], [], ['HTTP_AUTHORIZATION' => "Bearer {$this->key}", 'CONTENT_TYPE' => 'x-application/hl7-v2+er7'],
        oru('MAP1', $this->order->id, [['GLUC', '5.0', 'mmol/L', '', 'F'], ['POT', '4.1', 'mmol/L', '', 'F']]))->assertSee('Results applied', false);
    $this->clinic->run(fn () => expect(LabOrder::query()->findOrFail($this->order->id)->status)->toBe('verified'));
});

it('sends new orders only to the chosen lab system, in its own codes, until it confirms receipt', function (): void {
    [$ordersKey, $otherKey, $newOrderId] = $this->clinic->run(function (): array {
        $keys = app(ApiKeys::class);
        $orders = $keys->create('Precise orders', ['lab:orders'], [], null, 1);
        $other = $keys->create('Other lab', ['lab:orders'], [], null, 1);
        $c = app(LabConnections::class);
        expect(fn () => $c->setOutgoingKey((int) DB::table('api_keys')->where('name', 'Website')->value('id')))->toThrow(ValidationException::class);
        $c->setOutgoingKey($orders['id']);
        $c->map($orders['id'], 'GLUC', 'GLU');
        tenancy()->initialize($this->clinic);
        $order = app(LabWorkflow::class)->order(seenByDoctor($this, registerTestPatient('Sipho', '850101', null, '0821112222'), PayerType::Cash)->visit, $this->doctor, ['GLU']);

        return [$orders['key'], $other['key'], $order->id];
    });
    tenancy()->end();

    $bundle = $this->withHeaders(['Authorization' => "Bearer {$ordersKey}"])->getJson("{$this->base}/orders")->assertOk()->json();
    expect($bundle['total'])->toBe(1)->and($bundle['entry'][1]['resource']['id'])->toBe($newOrderId)
        ->and($bundle['entry'][1]['resource']['code']['coding'][0]['code'])->toBe('GLUC')
        ->and(json_encode($bundle))->not->toContain('id_number');
    $this->withHeaders(['Authorization' => "Bearer {$ordersKey}"])->get("{$this->base}/orders.hl7")->assertOk()->assertSee('ORM^O01', false)->assertSee('|GLUC^', false);
    $this->withHeaders(['Authorization' => "Bearer {$otherKey}"])->getJson("{$this->base}/orders")->assertOk()->assertJsonPath('total', 0);
    $this->withHeaders(['Authorization' => "Bearer {$this->key}"])->getJson("{$this->base}/orders")->assertForbidden();

    $this->withHeaders(['Authorization' => "Bearer {$otherKey}"])->postJson("{$this->base}/orders/{$newOrderId}/received")->assertNotFound();
    $this->withHeaders(['Authorization' => "Bearer {$ordersKey}"])->postJson("{$this->base}/orders/{$newOrderId}/received")->assertOk();
    $this->withHeaders(['Authorization' => "Bearer {$ordersKey}"])->getJson("{$this->base}/orders")->assertJsonPath('total', 0);
});
