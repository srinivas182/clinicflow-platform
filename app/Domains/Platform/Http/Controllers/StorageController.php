<?php

declare(strict_types=1);

namespace App\Domains\Platform\Http\Controllers;

use App\Domains\Platform\Storage\FileStore;
use App\Domains\Platform\Storage\StorageTargets;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Super admin: where Dr Business Flow keeps files.
 */
class StorageController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Admin/Storage', [
            'targets' => DB::connection((string) config('tenancy.database.central_connection'))->table('storage_targets')->orderByDesc('active')->orderBy('name')->get()
                ->map(fn ($t) => ['id' => $t->id, 'name' => $t->name, 'driver' => $t->driver, 'provider' => $t->provider, 'bucket' => $t->bucket, 'region' => $t->region,
                    'endpoint' => $t->endpoint, 'pathStyle' => (bool) $t->path_style, 'encrypt' => (bool) $t->encrypt, 'root' => $t->root, 'active' => (bool) $t->active,
                    'verified' => $t->verified_at !== null, 'lastError' => $t->last_error, 'hasKeys' => $t->credentials !== null])->values(),
            'providers' => FileStore::PROVIDERS,
            'current' => config('filesystems.disks.'.FileStore::DISK.'.driver'),
        ]);
    }

    public function save(Request $request, StorageTargets $targets): RedirectResponse
    {
        $data = $request->validate([
            'id' => ['nullable', 'integer'], 'name' => ['required', 'string', 'max:80'], 'driver' => ['required', 'in:local,s3'], 'provider' => ['nullable', 'string', 'max:20'],
            'bucket' => ['nullable', 'string', 'max:63'], 'region' => ['nullable', 'string', 'max:40'], 'endpoint' => ['nullable', 'string', 'max:255'],
            'path_style' => ['boolean'], 'encrypt' => ['boolean'], 'root' => ['nullable', 'string', 'max:120'], 'key' => ['nullable', 'string', 'max:200'], 'secret' => ['nullable', 'string', 'max:300'],
        ]);
        $targets->save(isset($data['id']) ? (int) $data['id'] : null, [
            'name' => $data['name'], 'driver' => $data['driver'], 'provider' => $data['provider'] ?? null, 'bucket' => $data['bucket'] ?? null, 'region' => $data['region'] ?? null,
            'endpoint' => $data['endpoint'] ?? null, 'path_style' => (bool) ($data['path_style'] ?? false), 'encrypt' => (bool) ($data['encrypt'] ?? true),
            'root' => $data['root'] ?? null, 'key' => $data['key'] ?? null, 'secret' => $data['secret'] ?? null,
        ]);

        return back()->with('success', 'Storage saved. Test the connection next.');
    }

    public function act(int $target, string $action, StorageTargets $targets): RedirectResponse
    {
        if ($action === 'test') {
            return $targets->test($target)
                ? back()->with('success', 'Connection works: a test file was written, read back and deleted.')
                : back()->with('error', 'The connection test failed. Check the details and the bucket permissions.');
        }
        $targets->activate($target);

        return back()->with('success', 'Storage activated. New files now go here.');
    }
}
