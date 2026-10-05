<?php

declare(strict_types=1);

namespace App\Domains\Scribe\Http;

use App\Domains\Clinical\Models\Consultation;
use App\Domains\Identity\Enums\Permission;
use App\Domains\Portal\Actions\PortalSignIn;
use App\Domains\Scheduling\Models\Appointment;
use App\Domains\Scribe\Actions\AiScribe;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * Doctor and patient actions for the AI scribe. It only runs when the doctor starts it
 * and the patient agrees; only transcribed minutes are charged.
 */
class ScribeSessionController extends Controller
{
    /** In person: the doctor confirms the patient agreed (or records the decline). */
    public function start(Request $request, Consultation $consultation, AiScribe $scribe): JsonResponse
    {
        $this->authorize(Permission::CONSULTS_WRITE);
        $id = $scribe->start($consultation, $this->staffId($request), $request->boolean('agreed'));

        return response()->json(['session' => $id, 'status' => $id === null ? 'declined' : 'created']);
    }

    /** Video, audio or chat: ask the patient on their own screen. */
    public function requestConsent(Request $request, Appointment $appointment, AiScribe $scribe): JsonResponse
    {
        $this->authorize(Permission::CONSULTS_WRITE);
        abort_unless($appointment->staff_id === $this->staffId($request), 403, 'This consult is booked with another doctor.');
        $consultation = Consultation::query()->whereIn('visit_id', DB::table('visits')->where('appointment_id', $appointment->id)->pluck('id'))->latest()->firstOrFail();
        $id = $scribe->request($consultation, $appointment->id, $this->staffId($request), $request->string('source')->toString() === 'chat' ? 'chat' : 'call');

        return response()->json(['session' => $id, 'status' => 'awaiting']);
    }

    public function audio(Request $request, string $session, AiScribe $scribe): JsonResponse
    {
        $this->authorize(Permission::CONSULTS_WRITE);
        $this->own($request, $session);
        $request->validate(['audio' => ['required', 'file', 'max:61440', 'mimetypes:audio/webm,video/webm,audio/ogg,audio/mp4,audio/mpeg,audio/wav,audio/x-wav'], 'seconds' => ['required', 'integer', 'min:1']]);
        $file = $request->file('audio');
        abort_unless($file instanceof UploadedFile, 422);
        $scribe->process($session, (string) file_get_contents($file->getRealPath()), (string) $file->getMimeType(), $request->integer('seconds'));
        @unlink($file->getRealPath());

        return $this->show($request, $session, $scribe);
    }

    public function act(Request $request, string $session, string $action, AiScribe $scribe): JsonResponse|RedirectResponse
    {
        $this->authorize(Permission::CONSULTS_WRITE);
        $this->own($request, $session);
        match ($action) {
            'chat' => $scribe->fromChat($session),
            'redraft' => $scribe->draft($session),
            'accept' => $scribe->close($session, true),
            'discard' => $scribe->close($session, false),
            default => abort(404),
        };

        return $this->show($request, $session, $scribe);
    }

    public function show(Request $request, string $session, AiScribe $scribe): JsonResponse
    {
        $row = DB::table('scribe_sessions')->where('id', $session)->first();
        abort_if($row === null, 404);

        return response()->json(['session' => $row->id, 'status' => $row->status, 'minutes' => (int) $row->minutes_billed, 'error' => $row->error,
            'draft' => in_array($row->status, ['drafted', 'accepted'], true) ? $scribe->draftFor($row->id) : null]);
    }

    /** The patient answers from their portal (video/audio call or chat). */
    public function patientAnswer(Request $request, string $session, string $answer, PortalSignIn $signIn, AiScribe $scribe): JsonResponse
    {
        $mine = $signIn->profiles((string) $request->session()->get('portal_cell'))->pluck('id')->map(fn ($v) => (string) $v);
        $patientId = (string) DB::table('scribe_sessions')->where('id', $session)->value('patient_id');
        abort_unless($mine->contains($patientId), 403);
        $scribe->answer($session, $patientId, $answer === 'agree');

        return response()->json(['status' => $answer === 'agree' ? 'created' : 'declined']);
    }

    private function own(Request $request, string $session): void
    {
        abort_unless((int) DB::table('scribe_sessions')->where('id', $session)->value('staff_id') === $this->staffId($request), 403);
    }

    private function staffId(Request $request): int
    {
        return (int) $request->user()?->getAuthIdentifier();
    }
}
