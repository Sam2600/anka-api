<?php

return [

    /*
    |---------------------------------------------------------------------------
    | Contract Provider (Issuer)
    |---------------------------------------------------------------------------
    |
    | Hard-coded issuer block that appears at the top of every generated
    | contract PDF and in the Parties block. Pulled from config (not from
    | tenant data) for v1 because Brycen Myanmar is the only issuer today.
    |
    | When we go multi-tenant: replace these reads with $tenant->* fields and
    | seed the equivalent columns on the tenants table. The Blade view + PDF
    | service touch this config in exactly one place each.
    |
    */

    'provider' => [
        'name'    => env('CONTRACT_PROVIDER_NAME', 'Brycen Myanmar Ltd.'),
        'address' => env('CONTRACT_PROVIDER_ADDRESS', ''),
        'phone'   => env('CONTRACT_PROVIDER_PHONE', ''),
        'email'   => env('CONTRACT_PROVIDER_EMAIL', ''),
        // Absolute filesystem path resolved at runtime. Drop the file at
        // anka-api/storage/app/public/contract-assets/brycen-logo.png.
        // PNG or JPG, ~200–400 px wide. SVG won't render in Dompdf.
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
