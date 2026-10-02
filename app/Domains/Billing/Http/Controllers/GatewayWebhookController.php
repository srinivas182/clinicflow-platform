<?php

declare(strict_types=1);

namespace App\Domains\Billing\Http\Controllers;

use App\Domains\Billing\Actions\HandleGatewayWebhook;
use App\Domains\Billing\Enums\Gateway;
use App\Domains\Platform\Actions\SettleSubscriptionInvoice;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Gateway notifications. Provider webhooks arrive on the provider's domain;
 * platform (subscription) webhooks on the central domain.
 */
class GatewayWebhookController extends Controller
{
    public function provider(Request $request, string $gateway, HandleGatewayWebhook $action): Response
    {
        $handled = $action->handle(Gateway::from($gateway), $request);

        return response($handled ? 'OK' : 'IGNORED', 200);
    }

    public function platform(Request $request, string $gateway, SettleSubscriptionInvoice $action): Response
    {
        $handled = $action->fromWebhook(Gateway::from($gateway), $request);

        return response($handled ? 'OK' : 'IGNORED', 200);
    }
}
