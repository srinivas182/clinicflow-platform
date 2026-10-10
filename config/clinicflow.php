<?php

declare(strict_types=1);

return [

    /*
     * Platform release version. Bumped on every release (see CHANGELOG.md)
     * and reported by GET /api/v1/health.
     */
    'version' => env('CLINICFLOW_VERSION', '0.68.0'),

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

    // Super admins, owners and practice admins must use an authenticator app (off in the test configuration).
    'security' => [
        // Two-step sign-in default until the super admin sets it (Admin → Security). Off suits a demo;
        // switch it on before real patient data.
        'two_factor_default' => (bool) env('CLINICFLOW_TWO_FACTOR', false),
        'require_authenticator_for_admins' => (bool) env('CLINICFLOW_REQUIRE_AUTHENTICATOR', true),
        // Refuse passwords known from data breaches (privacy-preserving check: only 5 characters of the hash are sent).
        // Content-security policy: null = on in production only; true/false to force. Report-only for a trial period.
        'csp' => env('CLINICFLOW_CSP') === null ? null : (bool) env('CLINICFLOW_CSP'),
        'csp_report_only' => (bool) env('CLINICFLOW_CSP_REPORT_ONLY', false),
        // Virus scanning of every upload with ClamAV (clamd). Off until the scanner is deployed; refuses uploads if it is down.
        'virus_scan' => [
            'enabled' => (bool) env('CLINICFLOW_VIRUS_SCAN', false),
            'host' => env('CLAMAV_HOST', 'clamav'),
            'port' => (int) env('CLAMAV_PORT', 3310),
            'fail_closed' => (bool) env('CLINICFLOW_VIRUS_SCAN_FAIL_CLOSED', true),
        ],
        'check_leaked_passwords' => (bool) env('CLINICFLOW_CHECK_LEAKED_PASSWORDS', true),
        // One person opening this many different patient records in an hour alerts the owners; the hard limit refuses more.
        'record_views_alert' => (int) env('CLINICFLOW_RECORD_VIEWS_ALERT', 100),
        'record_views_hard_limit' => (int) env('CLINICFLOW_RECORD_VIEWS_LIMIT', 300),
    ],

    // Data retention in days (operational logs only; clinical records are never removed by data:prune).
    'retention' => [
        'codes' => (int) env('RETENTION_CODES_DAYS', 7),
        'failed_jobs' => (int) env('RETENTION_FAILED_JOBS_DAYS', 30),
        'api_requests' => (int) env('RETENTION_API_REQUESTS_DAYS', 90),
        'webhook_deliveries' => (int) env('RETENTION_WEBHOOK_DAYS', 90),
        'sign_ins' => (int) env('RETENTION_SIGN_INS_DAYS', 365),
        'record_views' => (int) env('RETENTION_RECORD_VIEWS_DAYS', 365),
        'lab_messages' => (int) env('RETENTION_LAB_MESSAGES_DAYS', 365),
        'messages' => (int) env('RETENTION_MESSAGES_DAYS', 730),
        'audit' => (int) env('RETENTION_AUDIT_DAYS', 2555),
    ],

    'performance' => [
        // Cache the practice lookup by domain (cleared immediately when a practice or its domains change).
        'cache_tenant_lookup' => (bool) env('CLINICFLOW_CACHE_TENANT_LOOKUP', true),
    ],

    // Shared cPanel hosting: practice databases are created through cPanel's API (TENANCY_DB_MANAGER=cpanel).
    'cpanel' => [
        'host' => env('CPANEL_HOST', ''),
        'port' => (int) env('CPANEL_PORT', 2083),
        'user' => env('CPANEL_USER', ''),
        'token' => env('CPANEL_API_TOKEN', ''),
        'db_user' => env('CPANEL_DB_USER', env('DB_USERNAME')),
    ],
];
