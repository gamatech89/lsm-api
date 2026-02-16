<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Company Information (Bill To)
    |--------------------------------------------------------------------------
    |
    | The company that receives invoices from developers.
    | This appears in the "Bill to" section of generated invoice PDFs.
    |
    */

    'company_name' => env('INVOICE_COMPANY_NAME', 'LF Service UG (haftungsbeschraenkt)'),
    'company_address' => env('INVOICE_COMPANY_ADDRESS', "Marcel-Breuer-Straße 15\n80807 München"),
    'company_country' => env('INVOICE_COMPANY_COUNTRY', 'Germany'),

    /*
    |--------------------------------------------------------------------------
    | Tax Settings
    |--------------------------------------------------------------------------
    */
    'tax_rate' => env('INVOICE_TAX_RATE', 0),
    'tax_label' => env('INVOICE_TAX_LABEL', 'Tax'),
    'currency_symbol' => env('INVOICE_CURRENCY_SYMBOL', '$'),
];
