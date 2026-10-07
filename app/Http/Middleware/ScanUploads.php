<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domains\Platform\Security\VirusScanner;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Checks every uploaded file for viruses before any page handles it. An infected file is
 * refused (nothing is stored) and recorded; if the scanner is down, uploads are refused
 * unless the fail-open setting is chosen.
 */
class ScanUploads
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! (bool) config('clinicflow.security.virus_scan.enabled', false) || $request->allFiles() === []) {
            return $next($request);
        }
        $scanner = app(VirusScanner::class);
        foreach (Arr::flatten($request->allFiles()) as $file) {
            if (! $file instanceof UploadedFile || ! $file->isValid()) {
                continue;
            }
            try {
                $found = $scanner->scan((string) $file->getRealPath());
            } catch (\Throwable $e) {
                Log::error('Upload not checked: virus scanner unavailable', ['error' => $e->getMessage()]);
                if ((bool) config('clinicflow.security.virus_scan.fail_closed', true)) {
                    abort(503, 'Uploaded files cannot be checked right now. Please try again in a few minutes.');
                }

                continue;
            }
            if ($found !== null) {
                Log::warning('Infected upload refused', ['signature' => $found, 'name' => $file->getClientOriginalName(), 'user' => $request->user()?->getAuthIdentifier(), 'ip' => $request->ip()]);
                activity('security')->causedBy($request->user())->withProperties(['signature' => $found, 'file' => mb_substr($file->getClientOriginalName(), 0, 120)])
                    ->log('Infected upload refused');
                @unlink((string) $file->getRealPath());

                abort(422, 'This file looks unsafe and was not uploaded.');
            }
        }

        return $next($request);
    }
}
