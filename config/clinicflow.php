<?php

declare(strict_types=1);

return [

    /*
     * Platform release version. Bumped on every release (see CHANGELOG.md)
     * and reported by GET /api/v1/health.
     */
    'version' => env('CLINICFLOW_VERSION', '0.1.0'),

    /*
     * Hosting region. All patient data must stay in South Africa (POPIA s72).
     */
    'region' => env('CLINICFLOW_REGION', 'af-south-1'),

    'tenancy' => [
        /*
         * Provision new provider databases on the queue. Keep false in tests
         * and local development; true in staging and production.
         */
        'queue_provisioning' => (bool) env('TENANCY_QUEUE_PROVISIONING', false),
    ],

];
