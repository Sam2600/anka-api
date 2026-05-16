<?php

return [

    /*
    |---------------------------------------------------------------------------
    | Contract Provider — fallback (used only when the tenant has no data)
    |---------------------------------------------------------------------------
    |
    | Per-tenant branding lives on the tenants table (tenants.name +
    | tenants.logo_path). The contract PDF + customer email pull from there
    | first. These values only kick in when the tenant has no name or no
    | logo set — for example during local dev with the seeded demo tenant.
    |
    | The env overrides (CONTRACT_PROVIDER_*) keep environments where you
    | want a single global identity working without touching the database.
    |
    */

    'provider_fallback' => [
        'name'    => env('CONTRACT_PROVIDER_NAME', 'Brycen Myanmar Ltd.'),
        'address' => env('CONTRACT_PROVIDER_ADDRESS', ''),
        'phone'   => env('CONTRACT_PROVIDER_PHONE', ''),
        'email'   => env('CONTRACT_PROVIDER_EMAIL', ''),
        // Absolute filesystem path. Defaults to the legacy seed location
        // (storage/app/public/contract-assets/brycen-logo.png). Only used
        // when the tenant has no logo_path of its own.
        'logo_path' => env(
            'CONTRACT_PROVIDER_LOGO_PATH',
            storage_path('app/public/contract-assets/brycen-logo.png'),
        ),
    ],

    /*
    |---------------------------------------------------------------------------
    | PDF render output location
    |---------------------------------------------------------------------------
    |
    | Rendered draft PDFs cache to `storage/app/{path}/{draft_id}/v{version}.pdf`.
    | Re-sends reuse the file. New version = new file.
    |
    */
    'pdf_storage_path' => 'contract-drafts',
];
