<?php

declare(strict_types=1);

namespace App\Domains\Website\Http\Controllers;

use App\Domains\Identity\Enums\Permission;
use App\Domains\Identity\Models\Staff;
use App\Domains\Platform\Models\Provider;
use App\Domains\Platform\Models\Setting;
use App\Domains\Platform\Models\SitePage;
use App\Domains\Platform\Storage\FileStore;
use App\Domains\Scheduling\Actions\AvailableSlots;
use App\Domains\Website\Actions\Feedback;
use App\Domains\Website\Actions\MediaLibrary;
use App\Domains\Website\Models\Media;
use App\Http\Controllers\Controller;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Website tools: media library, patient feedback, booking widget and SEO files.
 */
class WebsiteToolsController extends Controller
{
    // ---------------- media library ----------------

    public function media(MediaLibrary $library): Response
    {
        $this->authorize(Permission::SETTINGS_MANAGE);

        return Inertia::render('Website/Media', [
            'media' => Media::query()->latest()->get()->map(fn (Media $m) => ['id' => $m->id, 'url' => $m->url(), 'thumb' => $m->url('thumb'), 'alt' => $m->alt,
                'filename' => $m->filename, 'size' => "{$m->width}×{$m->height}", 'usedOn' => $library->usedOn($m)])->values(),
            'ogImage' => Setting::get('website', 'og_image'),
        ]);
    }

    public function upload(Request $request, MediaLibrary $library): RedirectResponse
    {
        $this->authorize(Permission::SETTINGS_MANAGE);
        $request->validate(['file' => ['required', 'file', 'max:5120'], 'alt' => ['required', 'string', 'max:200']], ['alt.required' => 'Describe the image for people using screen readers.']);
        $file = $request->file('file');
        abort_unless($file instanceof UploadedFile, 422);
        $media = $library->upload($file, $request->string('alt')->toString(), (int) $request->user()?->getAuthIdentifier());

        return back()->with('success', "Image added. Use {$media->url()} in your page sections.");
    }

    public function updateMedia(Request $request, Media $media): RedirectResponse
    {
        $this->authorize(Permission::SETTINGS_MANAGE);
        $data = $request->validate(['alt' => ['required', 'string', 'max:200'], 'og' => ['boolean']]);
        $media->forceFill(['alt' => trim($data['alt'])])->save();
        if ($data['og'] ?? false) {
            Setting::put('website', 'og_image', $media->url());
        }

        return back()->with('success', 'Image saved.');
    }

    public function deleteMedia(Media $media, MediaLibrary $library): RedirectResponse
    {
        $this->authorize(Permission::SETTINGS_MANAGE);
        $library->delete($media);

        return back()->with('success', 'Image deleted.');
    }

    public function serveMedia(Media $media, string $size = 'large'): HttpResponse
    {
        $path = $media->path($size === 'thumb' ? 'thumb' : 'large');
        abort_unless(Storage::disk(FileStore::DISK)->exists($path), 404);

        return response((string) Storage::disk(FileStore::DISK)->get($path), 200, ['Content-Type' => $media->mime, 'Cache-Control' => 'public, max-age=604800', 'X-Content-Type-Options' => 'nosniff']);
    }

    // ---------------- feedback ----------------

    public function feedbackForm(string $token): Response
    {
        $request = DB::table('feedback_requests')->where('token', $token)->first();
        $provider = tenant();

        return Inertia::render('Feedback/Form', [
            'token' => $token, 'practice' => $provider instanceof Provider ? $provider->name : '',
            'done' => $request === null || $request->completed_at !== null,
            'publicShown' => Feedback::publicEnabled(),
        ]);
    }

    public function feedbackSubmit(Request $request, string $token, Feedback $feedback): RedirectResponse
    {
        $data = $request->validate(['rating' => ['required', 'integer'], 'comment' => ['nullable', 'string', 'max:2000'], 'public_ok' => ['boolean']]);
        $feedback->submit($token, (int) $data['rating'], $data['comment'] ?? null, (bool) ($data['public_ok'] ?? false));

        return back()->with('success', 'Thank you for your feedback.');
    }

    public function reviews(): Response
    {
        $this->authorize(Permission::SETTINGS_MANAGE);
        $rows = DB::table('reviews')->leftJoin('staff', 'staff.id', '=', 'reviews.staff_id')->orderByDesc('reviews.id')->limit(200)
            ->get(['reviews.*', 'staff.name as doctor']);

        return Inertia::render('Website/Reviews', [
            'reviews' => $rows->map(fn ($r) => ['id' => $r->id, 'rating' => (int) $r->rating, 'comment' => $r->comment, 'doctor' => $r->doctor, 'publicOk' => (bool) $r->public_ok,
                'reply' => $r->reply, 'flagged' => $r->flagged_at !== null, 'date' => substr((string) $r->created_at, 0, 10)])->values(),
            'average' => round((float) DB::table('reviews')->whereNull('flagged_at')->avg('rating'), 1),
            'count' => DB::table('reviews')->count(),
            'requested' => DB::table('feedback_requests')->whereNotNull('sent_at')->count(),
            'settings' => ['enabled' => (bool) Setting::get('reviews', 'enabled', true), 'public' => Feedback::publicEnabled()],
        ]);
    }

    public function reviewAction(Request $request, int $review, string $action, Feedback $feedback): RedirectResponse
    {
        $this->authorize(Permission::SETTINGS_MANAGE);
        $action === 'reply' ? $feedback->reply($review, $request->string('reply')->toString()) : $feedback->flag($review, $request->string('reason')->toString());

        return back()->with('success', $action === 'reply' ? 'Reply saved.' : 'Reported to Clinic Flow. The review is hidden until it is reviewed.');
    }

    public function reviewSettings(Request $request): RedirectResponse
    {
        $this->authorize(Permission::SETTINGS_MANAGE);
        $data = $request->validate(['enabled' => ['required', 'boolean'], 'public' => ['required', 'boolean'], 'legal_confirmed' => ['boolean']]);
        if ($data['public'] && ! ($data['legal_confirmed'] ?? false)) {
            return back()->withErrors(['legal_confirmed' => 'Confirm that your legal reviewer approved showing patient feedback publicly (HPCSA advertising rules).']);
        }
        Setting::put('reviews', 'enabled', (bool) $data['enabled']);
        Setting::put('reviews', 'public', (bool) $data['public']);
        Setting::put('reviews', 'public_confirmed', (bool) $data['public']);
        activity('website')->causedBy($request->user())->withProperties($data)->log('Feedback settings changed');

        return back()->with('success', 'Feedback settings saved.');
    }

    // ---------------- booking widget and SEO ----------------

    /**
     * Next free times per doctor for the coming week. Public: names and times only.
     */
    public function widgetSlots(AvailableSlots $slots): JsonResponse
    {
        $doctors = Staff::query()->orderBy('name')->get()->filter(fn (Staff $s) => $s->hasAnyRole(['doctor', 'locum_doctor']));
        $out = [];
        foreach ($doctors as $doctor) {
            $times = [];
            for ($d = 0; $d < 7 && count($times) < 3; $d++) {
                foreach ($slots->handle($doctor->id, CarbonImmutable::today()->addDays($d)) as $slot) {
                    $times[] = $slot['starts_at']->toIso8601String();
                    if (count($times) === 3) {
                        break;
                    }
                }
            }
            if ($times !== []) {
                $out[] = ['doctor' => $doctor->name, 'times' => $times];
            }
        }
        $provider = tenant();

        return response()->json(['practice' => $provider instanceof Provider ? $provider->name : '', 'bookUrl' => url('/my'), 'doctors' => $out])
            ->header('Access-Control-Allow-Origin', '*')->header('Cache-Control', 'public, max-age=120');
    }

    public function widgetScript(): HttpResponse
    {
        $origin = rtrim(url('/'), '/');
        $js = <<<JS
(function(){var s=document.currentScript,t=document.createElement('div');t.className='clinicflow-widget';s.parentNode.insertBefore(t,s);
t.style.cssText='font-family:system-ui,sans-serif;border:1px solid #d9e2df;border-radius:12px;padding:16px;max-width:360px';
fetch('{$origin}/widget/slots').then(function(r){return r.json()}).then(function(d){
var h='<div style="font-weight:600;margin-bottom:8px">Book at '+esc(d.practice)+'</div>';
if(!d.doctors.length){h+='<p style="color:#5b6b66;font-size:14px">No free times this week. Call us to book.</p>';}
d.doctors.forEach(function(doc){h+='<div style="margin:8px 0"><div style="font-size:14px">'+esc(doc.doctor)+'</div>';doc.times.forEach(function(x){var dt=new Date(x);
h+='<a target="_blank" rel="noopener" href="'+d.bookUrl+'" style="display:inline-block;margin:4px 4px 0 0;padding:4px 8px;border-radius:6px;background:#0f766e;color:#fff;font-size:13px;text-decoration:none">'+dt.toLocaleDateString(undefined,{weekday:'short',day:'numeric',month:'short'})+' '+dt.toLocaleTimeString(undefined,{hour:'2-digit',minute:'2-digit'})+'</a>';});h+='</div>';});
h+='<a target="_blank" rel="noopener" href="'+d.bookUrl+'" style="font-size:13px;color:#0f766e">See all times</a>';t.innerHTML=h;}).catch(function(){t.innerHTML='<a href="{$origin}/my" target="_blank" rel="noopener">Book online</a>';});
function esc(v){return String(v).replace(/[&<>"']/g,function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];});}})();
JS;

        return response($js, 200, ['Content-Type' => 'application/javascript; charset=utf-8', 'Cache-Control' => 'public, max-age=3600', 'Access-Control-Allow-Origin' => '*']);
    }

    public function sitemap(): HttpResponse
    {
        $base = rtrim(url('/'), '/');
        $urls = SitePage::query()->where('published', true)->get()->map(fn (SitePage $p) => '<url><loc>'.$base.($p->slug === 'home' ? '/' : "/p/{$p->slug}").'</loc><lastmod>'.$p->updated_at?->toDateString().'</lastmod></url>')->implode('');

        return response('<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'.$urls.'</urlset>', 200, ['Content-Type' => 'application/xml']);
    }

    public function robots(): HttpResponse
    {
        return response("User-agent: *\nDisallow: /my\nDisallow: /workspace\nDisallow: /feedback\nSitemap: ".url('/sitemap.xml')."\n", 200, ['Content-Type' => 'text/plain']);
    }
}
