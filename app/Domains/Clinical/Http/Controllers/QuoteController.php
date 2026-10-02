<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Http\Controllers;

use App\Domains\Clinical\Actions\ManageQuote;
use App\Domains\Clinical\Models\Quote;
use App\Domains\Identity\Enums\Permission;
use App\Domains\Visits\Models\Visit;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class QuoteController extends Controller
{
    public function store(Request $request, Visit $visit, ManageQuote $action): RedirectResponse
    {
        $this->authorize(Permission::CONSULTS_WRITE);
        $data = $request->validate([
            'lines' => ['required', 'array', 'min:1', 'max:20'],
            'lines.*.code' => ['required', 'string', 'max:12'], 'lines.*.description' => ['required', 'string', 'max:255'],
            'lines.*.quantity' => ['required', 'integer', 'min:1'], 'lines.*.amount' => ['required', 'numeric', 'min:0'],
        ]);
        $lines = array_values(array_map(fn (array $l) => [
            'code' => (string) $l['code'], 'description' => (string) $l['description'], 'quantity' => (int) $l['quantity'], 'unit_cents' => (int) round(((float) $l['amount']) * 100),
        ], $data['lines']));
        $quote = $action->create($visit, $lines, $this->user($request));

        return back()->with('success', 'Quote of R'.number_format($quote->total_cents / 100, 2, '.', ' ').' prepared.');
    }

    public function accept(Request $request, Quote $quote, ManageQuote $action): RedirectResponse
    {
        $this->authorize(Permission::BILLING_COLLECT);
        $data = $request->validate(['preauth_number' => ['nullable', 'string', 'max:40']]);
        $action->accept($quote, $data['preauth_number'] ?? null, $this->user($request));

        return back()->with('success', 'Quote accepted; procedure added to the invoice.');
    }

    public function decline(Request $request, Quote $quote, ManageQuote $action): RedirectResponse
    {
        $this->authorize(Permission::BILLING_COLLECT);
        $action->decline($quote, $this->user($request));

        return back()->with('success', 'Quote declined.');
    }

    private function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
