<?php

declare(strict_types=1);

namespace App\Domains\Platform\Http\Controllers;

use App\Domains\Identity\Enums\Permission;
use App\Domains\Platform\Actions\ResubmitVerificationCheck;
use App\Domains\Platform\Enums\VerificationStatus;
use App\Domains\Platform\Models\Provider;
use App\Domains\Platform\Models\VerificationCheck;
use App\Domains\Platform\Notifications\PracticeNotice;
use App\Domains\Platform\Storage\FileStore;
use App\Domains\Platform\Support\PracticeContacts;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Practice side: Settings → Verification. Each registration check with its status and the
 * reviewer's reason; a rejected check can be corrected and sent back.
 */
class VerificationSettingsController extends Controller
{
    public function index(): Response
    {
        $this->authorize(Permission::SETTINGS_MANAGE);
        $provider = $this->provider();

        return Inertia::render('Settings/Verification', [
            'status' => $provider->status->value,
            'checks' => $provider->verificationChecks()->orderBy('id')->get()->map(fn (VerificationCheck $c): array => [
                'id' => $c->id, 'label' => $c->type->label(), 'reference' => $c->reference, 'status' => $c->status->value,
                'reason' => $c->notes, 'resubmitted' => $c->resubmitted_at?->toDateString(),
                'document' => $c->document_name, 'documentAt' => $c->document_uploaded_at?->toDateString(),
            ])->values(),
        ]);
    }

    public function resubmit(Request $request, int $check, ResubmitVerificationCheck $action): RedirectResponse
    {
        $this->authorize(Permission::SETTINGS_MANAGE);
        $data = $request->validate(['reference' => ['required', 'string', 'max:80']]);
        $model = $this->provider()->verificationChecks()->whereKey($check)->firstOrFail();
        $user = $request->user();
        abort_unless($user instanceof User, 403);
        $action->handle($model, (string) $data['reference'], $user);

        return back()->with('success', 'Sent back for review. We will email you once it is checked.');
    }

    /**
     * Upload the document for a check (decision on beta #18): PDF, JPG or PNG up to 10 MB, virus
     * scanned on the way in, stored in the practice's own files. A rejected check is sent back for
     * review; the platform team is emailed.
     */
    public function upload(Request $request, int $check): RedirectResponse
    {
        $this->authorize(Permission::SETTINGS_MANAGE);
        $request->validate(['file' => ['required', 'file', 'max:10240', 'mimetypes:application/pdf,image/jpeg,image/png']],
            ['file.mimetypes' => 'Upload a PDF, JPG or PNG.', 'file.max' => 'The file may be at most 10 MB.']);
        $model = $this->provider()->verificationChecks()->whereKey($check)->firstOrFail();
        $file = $request->file('file');
        abort_unless($file instanceof UploadedFile, 422);
        if ($model->status === VerificationStatus::Verified) {
            return back()->with('error', 'This check is already verified.');
        }
        $old = $model->document_path;
        $path = (string) $file->store("verification/{$model->id}", FileStore::DISK);
        if ($old !== null && $old !== $path) {
            Storage::disk(FileStore::DISK)->delete($old);
        }
        $wasRejected = $model->status === VerificationStatus::Rejected;
        $model->forceFill(['document_path' => $path, 'document_name' => mb_substr($file->getClientOriginalName(), 0, 200), 'document_uploaded_at' => now()]
            + ($wasRejected ? ['status' => VerificationStatus::Pending, 'resubmitted_at' => now()] : []))->save();
        activity('platform')->causedBy($request->user())->performedOn($model)->withProperties(['type' => $model->type->value])->log('Verification document uploaded');
        $provider = $this->provider();
        Notification::send(PracticeContacts::platformAdmins(), new PracticeNotice("{$provider->name} uploaded {$model->type->label()}",
            ["{$provider->name} uploaded a document for {$model->type->label()}.".($wasRejected ? ' It was rejected before and is back for review.' : '')],
            'Review it', rtrim((string) config('app.url'), '/')."/admin/providers/{$provider->id}", 'check_document'));

        return back()->with('success', 'Document uploaded. We will email you once it is checked.');
    }

    private function provider(): Provider
    {
        $provider = tenant();
        abort_unless($provider instanceof Provider, 404);

        return $provider;
    }
}
