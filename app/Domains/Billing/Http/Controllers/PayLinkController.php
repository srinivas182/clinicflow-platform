<?php

declare(strict_types=1);

namespace App\Domains\Billing\Http\Controllers;

use App\Domains\Billing\Contracts\PaymentGateway;
use App\Domains\Billing\Enums\Gateway;
use App\Domains\Billing\Enums\PaymentStatus;
use App\Domains\Billing\Gateways\CheckoutRequest;
use App\Domains\Billing\Gateways\GatewayFactory;
use App\Domains\Billing\Models\GatewayConfig;
use App\Domains\Billing\Models\Payment;
use App\Domains\Billing\Support\FakePaymentGateway;
use App\Domains\Platform\Models\Setting;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Patient pay link: /pay/{token} on the provider's own domain. The checkout is
 * created only when the patient opens the link, so messaging apps that preview
 * links can't use up a one-time gateway page.
 */
class PayLinkController extends Controller
{
    public function show(Request $request, string $token): Response
    {
        $payment = Payment::query()->with('invoice.patient')->where('checkout_token', $token)->first();
        abort_unless($payment instanceof Payment, 404);

        if ($payment->status !== PaymentStatus::Pending) {
            return response()->view('payments.done', ['message' => 'This payment has already been made. Thank you.']);
        }

        $adapter = $this->adapter((string) $payment->gateway);
        $root = $request->getSchemeAndHttpHost();
        $patient = $payment->invoice->patient;
        $email = $patient->email ?? (string) Setting::get('branding', 'email', '');

        $start = $adapter->startCheckout(new CheckoutRequest(
            amountCents: $payment->amount_cents,
            reference: $token,
            description: "Invoice {$payment->invoice->number}",
            returnUrl: "{$root}/pay/{$token}/done",
            cancelUrl: "{$root}/pay/{$token}/done?cancelled=1",
            notifyUrl: "{$root}/webhooks/payments/{$payment->gateway}",
            email: $email !== '' ? $email : 'payments@'.$request->getHost(),
            customerName: $patient->fullName(),
        ));

        if ($start->gatewayReference !== null && $start->gatewayReference !== '') {
            $payment->forceFill(['gateway_reference' => $start->gatewayReference])->save();
        }

        if ($start->formAction !== null) {
            return response()->view('payments.redirect', ['action' => $start->formAction, 'fields' => $start->formFields]);
        }

        return redirect()->away((string) $start->redirectUrl);
    }

    public function done(Request $request): Response
    {
        return response()->view('payments.done', ['message' => $request->boolean('cancelled')
            ? 'The payment was cancelled. You can open the link again to try once more.'
            : 'Your payment is being confirmed. The practice will see it shortly.']);
    }

    private function adapter(string $gateway): PaymentGateway
    {
        if ($gateway === 'fake') {
            return app(FakePaymentGateway::class);
        }

        $config = GatewayConfig::query()->where('gateway', Gateway::from($gateway)->value)->first();
        abort_unless($config instanceof GatewayConfig && $config->enabled, 410, 'This payment option is no longer available. Please contact the practice.');

        return GatewayFactory::fromConfig($config);
    }
}
