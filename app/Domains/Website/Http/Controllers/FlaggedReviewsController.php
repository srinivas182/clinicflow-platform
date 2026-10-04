<?php

declare(strict_types=1);

namespace App\Domains\Website\Http\Controllers;

use App\Domains\Platform\Models\Provider;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Super admin decides reported reviews: remove (abusive) or keep (restored).
 */
class FlaggedReviewsController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Admin/FlaggedReviews', [
            'flags' => DB::connection((string) config('tenancy.database.central_connection'))->table('flagged_reviews')->whereNull('decision')->orderBy('created_at')->get()->map(fn ($f) => [
                'id' => $f->id, 'practice' => Provider::query()->whereKey($f->tenant_id)->value('name'), 'rating' => (int) $f->rating, 'comment' => $f->comment, 'reason' => $f->reason,
            ])->values(),
        ]);
    }

    public function decide(Request $request, int $flag): RedirectResponse
    {
        $data = $request->validate(['decision' => ['required', Rule::in(['remove', 'keep'])]]);
        $f = DB::connection((string) config('tenancy.database.central_connection'))->table('flagged_reviews')->where('id', $flag)->whereNull('decision')->first();
        abort_if($f === null, 404);
        Provider::query()->findOrFail((string) $f->tenant_id)->run(function () use ($f, $data): void {
            $data['decision'] === 'remove'
                ? DB::table('reviews')->where('id', $f->review_id)->update(['comment' => null, 'public_ok' => false])
                : DB::table('reviews')->where('id', $f->review_id)->update(['flagged_at' => null, 'flag_reason' => null]);
        });
        DB::connection((string) config('tenancy.database.central_connection'))->table('flagged_reviews')->where('id', $flag)->update(['decision' => $data['decision'], 'decided_at' => now(), 'updated_at' => now()]);
        activity('platform')->withProperties(['flag' => $flag, 'decision' => $data['decision']])->log('Reported review decided');

        return back()->with('success', $data['decision'] === 'remove' ? 'Review comment removed.' : 'Review restored.');
    }
}
