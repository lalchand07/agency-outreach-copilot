<?php

/*
 * Product profile. Copy to config/product.php (gitignored) and edit.
 *
 * Everything the AI says about your product comes from this file. Keep it
 * factual: the drafts must not promise anything you cannot back up.
 */

declare(strict_types=1);

return [
    'product_name' => 'TBM',
    'one_line_pitch' => 'A back-office system for travel agencies: bookings, invoices, supplier payments and accounts in one place.',
    'key_benefits' => [
        'Every booking, invoice and supplier payment in one system instead of spreadsheets',
        'Umrah and Hajj package costing with visa, hotel and transport components',
        'Customer ledgers and agent statements that are always up to date',
        'ATOL-friendly records of what was sold and when (UK)',
    ],
    'ideal_customer' => 'Independent travel agencies with 2 to 50 staff that sell Umrah/Hajj packages, holidays, flights or visas, '
        . 'and still manage bookings and accounts in spreadsheets or several disconnected tools.',
    'proof_points' => [
        // Only real, checkable facts. Remove these examples.
        'Used by travel agencies in Pakistan and the UK',
    ],
    'offer' => 'A free 20-minute demo and a free import of your existing booking data.',
    'demo_links' => [
        // 'Two-minute video' => 'https://example.com/tbm-demo',
    ],
    'sender' => [
        'name' => 'Your Name',
        'title' => 'Founder',
        'company' => 'Your Company Ltd',
        'email' => 'you@example.com',
        'phone' => '+44 20 0000 0000',
        'website' => 'https://example.com',
        // A real postal address is required in commercial email in many countries.
        'address' => 'Unit 1, Example Street, London, EC1A 1AA, United Kingdom',
        'linkedin' => 'https://www.linkedin.com/in/your-profile',
    ],
    // Languages you can actually support in a sales conversation.
    'languages' => ['en', 'ur', 'ar'],
];
