<?php

declare(strict_types=1);

namespace App\Domains\Platform\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Super admin: the platform activity log — every admin change (with what was submitted, secrets
 * masked), setting changes with old and new values, approvals, billing and security events.
 * Practice activity stays in each practice's own audit log.
 */
class ActivityAdminController extends Controller
{
    public const PER_PAGE = 50;

    public function index(Request $request): Response
    {
        $data = $request->validate(['log' => ['nullable', 'string', 'max:30'], 'q' => ['nullable', 'string', 'max:100'], 'page' => ['nullable', 'integer', 'min:1']]);
        $central = DB::connection((string) config('tenancy.database.central_connection'));
        $query = $central->table('activity_log')->orderByDesc('id');
        if (filled($data['log'] ?? null)) {
            $query->where('log_name', $data['log']);
        }
        if (filled($data['q'] ?? null)) {
            $q = '%'.str_replace(['%', '_'], ['\\%', '\\_'], (string) $data['q']).'%';
            $query->where(fn ($w) => $w->where('description', 'like', $q)->orWhere('properties', 'like', $q));
        }
        $page = (int) ($data['page'] ?? 1);
        $rows = $query->offset(($page - 1) * self::PER_PAGE)->limit(self::PER_PAGE + 1)->get();
        $users = User::query()->whereIn('id', $rows->where('causer_type', User::class)->pluck('causer_id')->filter()->unique())->pluck('name', 'id');

        return Inertia::render('Admin/Activity', [
            'entries' => $rows->take(self::PER_PAGE)->map(fn ($r): array => [
                'id' => (int) $r->id, 'log' => $r->log_name, 'description' => $r->description, 'at' => (string) $r->created_at,
                'by' => $r->causer_id !== null ? ($users[(int) $r->causer_id] ?? 'User #'.$r->causer_id) : 'System',
                'properties' => $r->properties === null ? null : json_decode((string) $r->properties, true),
            ])->values(),
            'logs' => $central->table('activity_log')->distinct()->orderBy('log_name')->pluck('log_name')->filter()->values(),
            'filters' => ['log' => $data['log'] ?? null, 'q' => $data['q'] ?? null, 'page' => $page],
            'hasMore' => $rows->count() > self::PER_PAGE,
        ]);
    }
}
