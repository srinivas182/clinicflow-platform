<?php

use App\Domains\Identity\Actions\AddStaffMember;
use App\Domains\Identity\Enums\StaffRole;
use App\Domains\Platform\Enums\ProviderType;
use App\Domains\Platform\Models\Provider;
use App\Domains\Platform\Models\SitePage;
use App\Domains\Platform\Support\Website\SiteSections;
use App\Domains\Scheduling\Models\RosterSession;
use App\Domains\Visits\Actions\CheckInPatient;
use App\Domains\Visits\Enums\PayerType;
use App\Domains\Visits\Enums\VisitStage;
use App\Domains\Website\Actions\Feedback;
use App\Domains\Website\Models\Media;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

uses(DatabaseMigrations::class);

beforeEach(function (): void {
    if (config('database.default') !== 'mysql') {
        $this->markTestSkipped('Tenancy tests require MySQL.');
    }

    $this->clinic = makeProvider('Sunrise Medical Centre', ProviderType::Clinic, 'sunrise.clinicflow.test');
    $this->ownerUser = User::factory()->create();
    $this->doctorUser = User::factory()->create(['name' => 'Dr Naidoo']);
    app(AddStaffMember::class)->handle($this->clinic, $this->ownerUser, StaffRole::Owner);
    app(AddStaffMember::class)->handle($this->clinic, $this->doctorUser, StaffRole::Doctor);
});

afterEach(function (): void {
    tenancy()->end();
    Provider::query()->get()->each->delete();
});

it('resizes uploaded images, serves them, allows them in sections and protects images in use', function (): void {
    $this->actingAs($this->ownerUser)->post('http://sunrise.clinicflow.test/settings/website/media', [
        'file' => UploadedFile::fake()->image('team.jpg', 3000, 2000), 'alt' => 'Our reception team',
    ])->assertSessionHasNoErrors();
    $this->actingAs($this->ownerUser)->post('http://sunrise.clinicflow.test/settings/website/media', [
        'file' => UploadedFile::fake()->create('notes.pdf', 20, 'application/pdf'), 'alt' => 'x',
    ])->assertSessionHasErrors('file');

    $media = $this->clinic->run(fn () => Media::query()->sole());
    expect($media->width)->toBe(1600)->and($media->height)->toBe(1067);
    $this->get("http://sunrise.clinicflow.test/media/{$media->id}")->assertOk()->assertHeader('Content-Type', 'image/jpeg');
    $this->get("http://sunrise.clinicflow.test/media/{$media->id}/thumb")->assertOk();

    $this->clinic->run(function () use ($media): void {
        $sections = SiteSections::clean([['type' => 'team', 'heading' => 'Our team', 'items' => [['title' => 'Dr Naidoo', 'text' => 'GP', 'image' => "/media/{$media->id}"]]]]);
        expect($sections[0]['items'][0]['image'])->toBe("/media/{$media->id}")
            ->and(fn () => SiteSections::clean([['type' => 'gallery', 'items' => [['image' => 'http://evil.test/x.jpg']]]]))->toThrow(ValidationException::class);
        SitePage::create(['slug' => 'meet-the-team', 'title' => 'Meet the team', 'sections' => $sections, 'published' => true]);
    });
    $this->actingAs($this->ownerUser)->delete("http://sunrise.clinicflow.test/settings/website/media/{$media->id}")->assertSessionHasErrors('media');
});

it('asks for feedback once after a visit, keeps it private by default and shows only consented reviews once approved', function (): void {
    $this->clinic->run(function (): void {
        $patient = registerTestPatient('Thandi', '880412', null, '0825550147');
        $visit = app(CheckInPatient::class)->handle($patient, PayerType::Cash);
        $visit->forceFill(['stage' => VisitStage::Done, 'visit_date' => now()->subDay()->toDateString()])->save();

        $feedback = app(Feedback::class);
        expect($feedback->sendRequests())->toBe(1)->and($feedback->sendRequests())->toBe(0)
            ->and(DB::table('message_log')->where('recipient', '0825550147')->where('body', 'like', '%/feedback/%')->exists())->toBeTrue();
        test()->token = (string) DB::table('feedback_requests')->value('token');
    });

    $url = 'http://sunrise.clinicflow.test/feedback/'.test()->token;
    $this->get($url)->assertOk()->assertInertia(fn ($p) => $p->component('Feedback/Form')->where('publicShown', false));
    $this->post($url, ['rating' => 9])->assertSessionHasErrors('rating');
    $this->post($url, ['rating' => 5, 'comment' => 'Kind and quick.', 'public_ok' => true])->assertSessionHasNoErrors();
    $this->post($url, ['rating' => 4])->assertSessionHasErrors('token');

    $this->clinic->run(fn () => expect(Feedback::publicReviews())->toBeNull());
    $this->actingAs($this->ownerUser)->put('http://sunrise.clinicflow.test/settings/website/feedback', ['enabled' => true, 'public' => true])->assertSessionHasErrors('legal_confirmed');
    $this->actingAs($this->ownerUser)->put('http://sunrise.clinicflow.test/settings/website/feedback', ['enabled' => true, 'public' => true, 'legal_confirmed' => true])->assertSessionHasNoErrors();
    $this->clinic->run(function (): void {
        $shown = Feedback::publicReviews();
        expect($shown)->toHaveCount(1)->and($shown[0]['rating'] ?? null)->toBe(5)->and($shown[0]['comment'] ?? null)->toBe('Kind and quick.')
            ->and($shown[0]['name'] ?? '')->toMatch('/^T\. [A-Z]\.$/');
    });

    $reviewId = $this->clinic->run(fn () => (int) DB::table('reviews')->value('id'));
    $this->actingAs($this->ownerUser)->post("http://sunrise.clinicflow.test/settings/website/feedback/{$reviewId}/flag", ['reason' => 'Contains a staff member\'s phone number'])->assertSessionHasNoErrors();
    $this->clinic->run(fn () => expect(Feedback::publicReviews())->toBe([]));
    $flag = DB::table('flagged_reviews')->sole();

    $admin = User::factory()->create();
    $admin->forceFill(['is_platform_admin' => true])->save();
    $this->actingAs($admin)->post("http://localhost/admin/reviews/{$flag->id}", ['decision' => 'keep'])->assertSessionHasNoErrors();
    $this->clinic->run(fn () => expect(Feedback::publicReviews())->toHaveCount(1));
});

it('serves an embeddable booking widget with free times only, plus a sitemap and robots file', function (): void {
    $this->clinic->run(function (): void {
        RosterSession::create(['staff_id' => $this->doctorUser->id, 'starts_at' => now()->addDay()->setTime(9, 0), 'ends_at' => now()->addDay()->setTime(10, 0), 'slot_minutes' => 30]);
        SitePage::create(['slug' => 'test-services', 'title' => 'Test services', 'sections' => [], 'published' => true]);
        SitePage::create(['slug' => 'test-draft', 'title' => 'Draft', 'sections' => [], 'published' => false]);
    });

    $this->get('http://sunrise.clinicflow.test/widget.js')->assertOk()->assertHeader('Access-Control-Allow-Origin', '*')->assertSee('/widget/slots', false);
    $slots = $this->get('http://sunrise.clinicflow.test/widget/slots')->assertOk()->assertHeader('Access-Control-Allow-Origin', '*')->json();
    expect($slots['practice'])->toBe('Sunrise Medical Centre')
        ->and($slots['doctors'][0]['doctor'])->toBe('Dr Naidoo')
        ->and($slots['doctors'][0]['times'])->toHaveCount(2)
        ->and(array_keys($slots['doctors'][0]))->toBe(['doctor', 'times']);

    $sitemap = $this->get('http://sunrise.clinicflow.test/sitemap.xml')->assertOk()->getContent();
    expect($sitemap)->toContain('/p/test-services')->not->toContain('/p/test-draft');
    $this->get('http://sunrise.clinicflow.test/robots.txt')->assertOk()->assertSee('Disallow: /my', false);
});
