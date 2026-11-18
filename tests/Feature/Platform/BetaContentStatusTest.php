<?php

use App\Domains\Messaging\Models\MessageTemplate;
use App\Domains\Messaging\WhatsApp\WhatsAppTemplate;
use App\Domains\Platform\Models\CmsPage;
use App\Domains\Platform\Support\StatusPage;
use App\Domains\Platform\Support\Website\SiteData;
use App\Http\Middleware\RequireRecentConfirmation;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;

/*
 * Beta testing T4b: status page (232–234), WhatsApp templates (246, 247), reset wording (283) and
 * website pages (293, 295, 296). Reviews (109, 110) are in WebsiteFeedbackWidgetTest.
 */

uses(DatabaseMigrations::class);

beforeEach(function (): void {
    $this->admin = User::factory()->create();
    $this->admin->forceFill(['is_platform_admin' => true])->save();
    $this->as = fn () => $this->actingAs($this->admin)->withSession([RequireRecentConfirmation::SESSION_KEY => now()->getTimestamp()]);
});

it('expires a hand-set status, re-checks on Automatic, checks payments and shows open incidents in the banner (beta #232–#234)', function (): void {
    $status = app(StatusPage::class);
    $status->check();
    $component = fn () => DB::table('status_components')->where('key', 'payments')->first();
    expect($component()->status)->toBe('degraded'); // no gateway switched on

    ($this->as)()->post('http://localhost/admin/status/component', ['key' => 'payments', 'status' => 'operational', 'hours' => 2])->assertSessionHasNoErrors();
    expect($component()->status)->toBe('operational')->and((bool) $component()->manual)->toBeTrue();
    $this->travel(3)->hours();
    $status->check();
    expect((bool) $component()->manual)->toBeFalse()->and($component()->status)->toBe('degraded');

    expect($status->summary()['overall'])->toBe('degraded');
    DB::table('status_components')->update(['status' => 'operational', 'manual' => true, 'manual_until' => now()->addHour()]);
    expect($status->summary()['overall'])->toBe('operational');
    $status->report('Card payments failing', 'incident', 'critical', ['payments'], 'Investigating.');
    expect($status->summary()['overall'])->toBe('outage');
});

it('keeps one WhatsApp template per message per language, approved only by the supplier sync (beta #246, #247)', function (): void {
    $save = fn (array $d) => ($this->as)()->post('http://localhost/admin/whatsapp/templates', $d + ['message_key' => 'pharmacy.ready', 'category' => 'utility']);
    $save(['template_name' => 'pharmacy_ready', 'language' => 'en', 'status' => 'approved'])->assertSessionHasErrors('status');
    $save(['template_name' => 'pharmacy_ready', 'language' => 'en', 'status' => 'pending'])->assertSessionHasNoErrors();
    $save(['template_name' => 'pharmacy_ready_zu', 'language' => 'zu', 'status' => 'pending'])->assertSessionHasNoErrors();
    expect(WhatsAppTemplate::query()->count())->toBe(2);

    WhatsAppTemplate::query()->where('language', 'en')->update(['status' => 'approved']);
    $save(['template_name' => 'pharmacy_ready', 'language' => 'en', 'status' => 'pending'])->assertSessionHas('success', 'Template saved; it stays approved.');
    $save(['template_name' => 'pharmacy_ready_v2', 'language' => 'en', 'status' => 'pending']);
    expect(WhatsAppTemplate::query()->where('language', 'en')->value('status'))->toBe('pending');
});

it('resets a message to its original wording (beta #283)', function (): void {
    ($this->as)()->put('http://localhost/admin/messaging/templates', ['key' => 'pharmacy.ready', 'channel' => 'sms', 'language' => 'en', 'body' => '{{ practice }}: ready. Code {{ code }}.'])->assertSessionHasNoErrors();
    expect(MessageTemplate::query()->count())->toBe(1);
    ($this->as)()->post('http://localhost/admin/messaging/templates/reset', ['key' => 'pharmacy.ready', 'channel' => 'sms'])->assertSessionHas('success', 'Back to the original wording.');
    expect(MessageTemplate::query()->count())->toBe(0);
});

it('orders the public menu by position, redirects old addresses and archives pages (beta #293, #295, #296)', function (): void {
    $make = fn (string $slug, ?string $label, ?int $order) => CmsPage::query()->forceCreate(['slug' => $slug, 'title' => ucfirst($slug), 'body' => '<p>x</p>', 'menu_label' => $label, 'menu_order' => $order, 'published' => true]);
    $make('home', null, null);
    $make('about', 'About us', 5);
    $make('for-clinics', 'Clinics', 1);
    $make('careers', 'Careers', 25);
    expect(array_column(SiteData::platformSite()['menu'], 'label'))->toBe(['About us', 'Solutions', 'Find Care', 'Careers', 'Pricing']);

    $careers = CmsPage::query()->where('slug', 'careers')->sole();
    ($this->as)()->post('http://localhost/admin/pages', ['id' => $careers->id, 'slug' => 'jobs', 'title' => 'Jobs', 'menu_label' => 'Jobs', 'menu_order' => 25, 'published' => true, 'body' => '<p>x</p>'])->assertSessionHasNoErrors();
    $this->get('http://localhost/pages/careers')->assertRedirect('/pages/jobs')->assertStatus(301);

    ($this->as)()->post("http://localhost/admin/pages/{$careers->id}/archive")->assertSessionHasNoErrors();
    expect($careers->fresh()?->archived_at)->not->toBeNull()->and($careers->fresh()?->published)->toBeFalse();
    $this->get('http://localhost/pages/jobs')->assertNotFound();
    $home = CmsPage::query()->where('slug', 'home')->sole();
    ($this->as)()->post("http://localhost/admin/pages/{$home->id}/archive")->assertSessionHas('error');
    expect($home->fresh()?->archived_at)->toBeNull();
});
