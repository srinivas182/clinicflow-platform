<?php

declare(strict_types=1);

return [

    /*
     * Platform release version. Bumped on every release (see CHANGELOG.md)
     * and reported by GET /api/v1/health.
     */
    'version' => env('CLINICFLOW_VERSION', '0.41.0'),

    /*
     * Hosting region. All patient data must stay in South Africa (POPIA s72).
     */
    'region' => env('CLINICFLOW_REGION', 'af-south-1'),

    /*
     * Parent domain for free provider subdomains: <slug>.clinicflow.co.za.
     */
    'provider_domain' => env('PROVIDER_DOMAIN', 'clinicflow.co.za'),

    'payments' => [
        /*
         * Allow the fake gateway when no real gateway is connected. Local and
         * test environments only — never in production.
         */
        'allow_fake' => (bool) env('PAYMENTS_ALLOW_FAKE', false),
        'vat_rate' => 0.15,
    ],

    'website' => [
        // Contact details shown on clinicflow.co.za.
        'email' => env('WEBSITE_EMAIL', 'hello@clinicflow.co.za'),
        'phone' => env('WEBSITE_PHONE', ''),
        'address' => env('WEBSITE_ADDRESS', 'South Africa'),
    ],

    'whatsapp' => [
        'addon_monthly_cents' => (int) env('WHATSAPP_ADDON_CENTS', 19900),
    ],

    'api' => [
        'per_minute' => (int) env('API_PER_MINUTE', 60),
    ],

    'locums' => [
        'booking_fee_cents' => (int) env('LOCUM_BOOKING_FEE_CENTS', 0),
    ],

    'branches' => [
        'extra_monthly_cents' => (int) env('EXTRA_BRANCH_CENTS', 49900),
    ],

    'telemedicine' => [
        // Monthly Telemedicine add-on fee (cents, excl. VAT); usage is paid from the wallet.
        'addon_monthly_cents' => (int) env('TELEMEDICINE_ADDON_CENTS', 29900),
    ],

    'messaging' => [
        // Price per message above the package allowance (email or 160-character SMS segment), cents excl. VAT.
        'unit_price_cents' => (int) env('MESSAGING_UNIT_PRICE_CENTS', 35),
    ],

    'tenancy' => [
        /*
         * Provision new provider databases on the queue. Keep false in tests
         * and local development; true in staging and production.
         */
        'queue_provisioning' => (bool) env('TENANCY_QUEUE_PROVISIONING', false),
    ],

];
