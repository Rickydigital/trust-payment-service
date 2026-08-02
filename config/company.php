<?php

return [
    'name' => env('COMPANY_NAME', 'Trada Payments'),
    'legal_name' => env('COMPANY_LEGAL_NAME', env('COMPANY_NAME', 'Trada Payments')),
    'tagline' => env('COMPANY_TAGLINE', 'Secure payments, escrow, settlements, and payouts.'),
    'website' => env('COMPANY_WEBSITE', 'https://trada.co.tz'),
    'support_email' => env('COMPANY_SUPPORT_EMAIL', env('MAIL_FROM_ADDRESS', 'support@trada.co.tz')),
    'support_phone' => env('COMPANY_SUPPORT_PHONE'),
    'address' => env('COMPANY_ADDRESS', 'Dar es Salaam, Tanzania'),
    'logo_url' => env('COMPANY_LOGO_URL'),
    'logo_path' => env('COMPANY_EMAIL_LOGO_PATH', 'images/trada-mail-logo.png'),
    'brand_color' => env('COMPANY_BRAND_COLOR', '#08A65A'),
    'accent_color' => env('COMPANY_ACCENT_COLOR', '#052E25'),
    'email_background' => env('COMPANY_EMAIL_BACKGROUND', '#F4F8F5'),
    'button_text_color' => env('COMPANY_EMAIL_BUTTON_TEXT_COLOR', '#FFFFFF'),
    'footer_note' => env('COMPANY_EMAIL_FOOTER_NOTE', 'This is an official Trada message. We will never ask for your password, PIN, or OTP by email.'),
];
