<?php
/**
 * Client config template. Copy to clients/<slug>.php (lowercase, no spaces),
 * fill in every value, then set CLIENT=<slug> in that client's .env.
 * See BUILD_GUIDE.md, "New-client setup".
 * Secrets (database, mailbox password, APP_SECRET) never go here; they go in .env.
 */
return [
    'name'      => 'Client Business Name',
    'portal'    => 'HQ',                 // top bar shows "Client Business Name HQ"
    'monogram'  => 'CB',                 // 1–3 letters for the favicon and login tile
    'logo'      => '',                   // optional image URL; blank = text name
    'tagline'   => 'What the business does, in one line',
    'location'  => 'Town, ST',
    'domain'    => 'clientdomain.com',
    'site_origin' => 'https://clientdomain.com', // their website, allowed to post leads in

    'colors' => [
        'accent'      => '#1a6b4a',      // buttons, links, active nav
        'accent_dark' => '#35b082',      // accent in dark mode (lighter, readable on dark)
        'deep'        => '#0F3D2A',      // quote headings + dark total box (must be dark)
        'tint'        => '#E6F4EC',      // quote section bands (must be very light)
        'highlight'   => '#5fcfa0',      // the portal word ("HQ") in the dark top bar
    ],

    // Ops Essentials products this client pays for
    'products' => [
        'customers' => true,             // Ops Customers
        'quotes'    => true,             // Ops Quotes (Catalog included)
        'inbox'     => true,             // Ops Inbox
    ],
    'previews' => false,                 // Modern Ops only; leave false

    'email' => [
        'contact'         => 'info@clientdomain.com',
        'notify'          => 'info@clientdomain.com',
        'from'            => 'info@clientdomain.com',
        'from_name'       => 'Client Business Name',
        'quote_from'      => 'info@clientdomain.com',
        'quote_from_name' => 'Client Business Name',
        'smtp_host'       => 'mail.clientdomain.com',
        'smtp_port'       => 465,
        'smtp_secure'     => 'ssl',
        'imap_host'       => 'mail.clientdomain.com',
        'imap_port'       => 993,
        'imap_encryption' => 'ssl',
        'accounts'        => ['info@clientdomain.com'],
    ],

    'quotes' => [
        'tax_rate'      => '6',
        'tax_label'     => 'PA Sales Tax',
        'first_number'  => '1001',
        'valid_days'    => 30,
        'margin'        => '30',
        'notes'         => 'Quote valid for 30 days from issue date.',
        'thank_you'     => 'Thank you for choosing Client Business Name.',
        'file_prefix'   => 'Client-Business-Quote',
        'email_subject' => 'Your quote from Client Business Name (#{number})',
        'email_body'    => "Hi {name},\n\nYour quote is attached as a PDF. Reply to this email with any questions.\n\nThanks,\nClient Business Name",
        // [heading, text] pairs. Use the client's own terms; leave empty until they send them.
        'terms' => [],
    ],

    // Starting Catalog: [title, description, price, billing]
    // billing: one_time | monthly | quarterly | semiannual | annual
    'catalog' => [],
];
