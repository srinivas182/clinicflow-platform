<?php

declare(strict_types=1);

namespace App\Domains\Telemedicine\Http\Controllers;

use App\Domains\Identity\Enums\Permission;
use App\Domains\Identity\Models\Staff;
use App\Domains\Portal\Actions\PortalSignIn;
use App\Domains\Scribe\Actions\AiScribe;
use App\Domains\Telemedicine\Models\ChatMessage;
use App\Domains\Telemedicine\Models\ChatThread;
use App\Domains\Telemedicine\Models\TeleSession;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Chat consults (live, time-boxed) and follow-up windows. Doctor side in the
 * workspace, patient side in the portal. The screen refreshes every few seconds.
 */
class ChatController extends Controller
{
    public function doctorIndex(Request $request): Response
    {
        $this->authorize(Permission::CONSULTS_WRITE);

        return Inertia::render('Chat/Index', [
            'threads' => ChatThread::query()->with('patient')->where('doctor_staff_id', $request->user()?->getAuthIdentifier())
                ->where('closes_at', '>', now())->where('opens_at', '<=', now()->addDay())->orderBy('opens_at')->get()
                ->map(fn (ChatThread $t) => ['id' => $t->id, 'patient' => $t->patient->fullName(), 'kind' => $t->kind, 'open' => $t->isOpen(), 'closes' => $t->closes_at->format('j M H:i')])->values(),
        ]);
    }

    public function doctorShow(Request $request, ChatThread $thread): Response
    {
        $this->authorize(Permission::CONSULTS_WRITE);
        abort_unless($thread->doctor_staff_id === $request->user()?->getAuthIdentifier(), 403);

        return $this->render($thread, 'doctor', "/chats/{$thread->id}");
    }

    public function doctorPost(Request $request, ChatThread $thread): RedirectResponse
    {
        $this->authorize(Permission::CONSULTS_WRITE);
        abort_unless($thread->doctor_staff_id === $request->user()?->getAuthIdentifier(), 403);
        $this->post($request, $thread, 'doctor');
        if ($thread->appointment_id !== null) {
            TeleSession::query()->where('appointment_id', $thread->appointment_id)->whereNull('doctor_joined_at')->update(['doctor_joined_at' => now()]);
        }

        return back();
    }

    public function patientShow(Request $request, ChatThread $thread, PortalSignIn $signIn): Response
    {
        $this->ownPatient($request, $thread, $signIn);

        return $this->render($thread, 'patient', "/my/chats/{$thread->id}");
    }

    public function patientPost(Request $request, ChatThread $thread, PortalSignIn $signIn): RedirectResponse
    {
        $this->ownPatient($request, $thread, $signIn);
        $this->post($request, $thread, 'patient');

        return back();
    }

    private function render(ChatThread $thread, string $me, string $postUrl): Response
    {
        return Inertia::render('Chat/Thread', [
            'thread' => [
                'id' => $thread->id, 'kind' => $thread->kind, 'open' => $thread->isOpen(),
                'opensAt' => $thread->opens_at->toIso8601String(), 'closesAt' => $thread->closes_at->toIso8601String(),
                'other' => $me === 'doctor' ? $thread->patient->fullName() : (string) Staff::query()->whereKey($thread->doctor_staff_id)->value('name'),
            ],
            'me' => $me,
            'postUrl' => $postUrl,
            'scribe' => $thread->appointment_id === null ? null : [
                'appointmentId' => $thread->appointment_id,
                'session' => DB::table('scribe_sessions')->where('appointment_id', $thread->appointment_id)->latest('created_at')->first(['id', 'status']),
                'enabled' => app(AiScribe::class)->allowance((string) tenant('id'))['enabled'],
            ],
            'messages' => $thread->messages()->orderBy('id')->get()->map(fn (ChatMessage $m) => ['id' => $m->id, 'sender' => $m->sender, 'body' => $m->body, 'at' => $m->created_at->format('H:i')])->values(),
        ]);
    }

    private function post(Request $request, ChatThread $thread, string $sender): void
    {
        $data = $request->validate(['body' => ['required', 'string', 'max:2000']]);
        if (! $thread->isOpen()) {
            throw ValidationException::withMessages(['body' => $thread->opens_at->isFuture() ? 'This chat opens at '.$thread->opens_at->format('H:i').'.' : 'This chat has closed.']);
        }
        $thread->messages()->create(['sender' => $sender, 'body' => trim($data['body']), 'created_at' => now()]);
    }

    private function ownPatient(Request $request, ChatThread $thread, PortalSignIn $signIn): void
    {
        abort_unless($signIn->profiles((string) $request->session()->get('portal_cell'))->contains('id', $thread->patient_id), 403);
    }
}
