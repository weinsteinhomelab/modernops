<?php
/**
 * Client config: Modern Ops (our own copy of HQ).
 *
 * Everything that changes from one client to the next lives in this one file.
 * The code reads it through client() in admin/config.php. Secrets (database,
 * mailbox password, APP_SECRET) never go here; they stay in .env.
 * Copy _template.php to start a new client.
 */
return [
    // Who this copy of HQ belongs to
    'name'      => 'Modern Ops',
    'portal'    => 'HQ',                 // shown after the name in the top bar: "Modern Ops HQ"
    'monogram'  => 'MO',                 // favicon letters (first letter dark, rest white)
    'logo'      => '',                   // optional image URL for the top bar and login page; blank = text name
    'logo_file' => 'public/brand/modernops.png', // PNG/JPG for quotes and PDFs (path inside hq/app); blank = name in text
    'tagline'   => 'Web & business operations for small businesses',
    'location'  => 'Serving clients across the US',
    'timezone'  => 'America/New_York',   // dates and times in HQ, on quotes and acceptance records
    'domain'    => 'modern-ops.net',     // bare domain, no https://
    'site_origin' => 'https://modern-ops.net', // the website allowed to post leads to /api/submit.php
    'public_url' => 'https://hq.kobylab.dev', // HQ's public address; email-signature images load from here (blank = the address you're using)

    // Brand colors. accent drives buttons/links; deep + tint are used on quotes and PDFs.
    'colors' => [
        'accent'      => '#1a6b4a',
        'accent_dark' => '#35b082',      // accent in dark mode
        'deep'        => '#0F3D2A',
        'tint'        => '#E6F4EC',
        'highlight'   => '#5fcfa0',      // the portal word in the dark top bar
    ],

    // Which Ops Essentials products this client pays for
    'products' => [
        'customers' => true,             // Ops Customers: Customers + Requests
        'quotes'    => true,             // Ops Quotes: Quotes + Catalog
        'inbox'     => true,             // Ops Inbox: Inbox tab
    ],
    // Most people who can log in at once (each one is an Ops Customers seat). 0 = no limit.
    'seats' => 0,
    // Modern Ops-only extra: link to the client preview hub (site_origin + /demos/)
    'previews' => true,

    // Email addresses. The shared mailbox password is SMTP_PASS in .env.
    'email' => [
        'contact'         => 'info@modern-ops.net',   // printed on quotes and in error messages
        'notify'          => 'info@modern-ops.net',   // new website leads are emailed here
        'from'            => 'info@modern-ops.net',
        'from_name'       => 'Modern Ops',
        'quote_from'      => 'quote@modern-ops.net',
        'quote_from_name' => 'Modern Ops Quotes',
        'smtp_host'       => 'mail.modern-ops.net',
        'smtp_port'       => 465,
        'smtp_secure'     => 'ssl',
        'imap_host'       => 'mail.modern-ops.net',
        'imap_port'       => 993,
        'imap_encryption' => 'ssl',
        // Mailboxes shown in Ops Inbox (all share SMTP_PASS)
        'accounts'        => ['peter@modern-ops.net', 'info@modern-ops.net', 'service@modern-ops.net', 'quote@modern-ops.net'],
        // Signature pictures in emails load from here (the website's email-img/ relay), so they come from our own domain
        'signature_images' => 'https://modern-ops.net/email-img/',
    ],

    // Quotes
    'quotes' => [
        'tax_rate'      => '0',
        'tax_label'     => 'Sales Tax',
        'first_number'  => '1001',
        'valid_days'    => 30,
        'margin'        => '30',          // default margin % on new lines
        // Public page where customers view and accept a quote (?t=<token> is added). Blank = no online acceptance.
        'accept_page'   => 'https://modern-ops.net/quote/',
        // Shown on every quote after the totals. Plain sentences.
        'next_steps'    => [
            'Accept this quote online using the link on this quote, or sign and return the last page.',
            'We send an invoice for the amount due on acceptance.',
            'Once it is paid, we schedule the work and keep you posted along the way.',
        ],
        'payment'       => 'One-time fees are due when you accept this quote. Recurring fees are due at the start of each billing period. We accept check, bank transfer, or another payment method we agree on.',
        'notes'         => 'Quote valid for 30 days from issue date. Payment due upon acceptance.',
        'thank_you'     => 'Thank you for choosing Modern Ops. We look forward to working with you.',
        'file_prefix'   => 'Modern-Ops-Quote',
        'email_subject' => 'Your quote from Modern Ops (#{number})',
        'email_body'    => "Hi {name},\n\nThank you for your interest in Modern Ops. Please find your quote attached as a PDF.\n\nYou can also view and accept it online here:\n{link}\n\nIf you have any questions, just reply to this email.\n\nBest regards,\nModern Ops\nquote@modern-ops.net\nmodern-ops.net",
        'terms' => [
            ['Client Approval & Acceptance', 'The Client is responsible for reviewing all deliverables and communicating any concerns in writing prior to final approval. Once the Client approves a deliverable in writing (including via email), that item is considered complete and accepted as-is. Modern Ops shall not be held liable for any dissatisfaction arising from approved work. Requests to redo approved work will be treated as new work and billed accordingly.'],
            ['Payment Terms', 'All one-time fees are due upon acceptance of the quote unless otherwise agreed in writing. Recurring fees are due on the 1st of each billing period (monthly, quarterly, semi-annually or annually, as listed on the quote). Invoices not paid within 15 days of the due date may result in suspension of services. All fees paid are non-refundable once work has commenced. Modern Ops accepts payment via check, bank transfer, or any agreed-upon payment platform.'],
            ['Website Ownership & Intellectual Property', 'Upon receipt of full payment, the Client owns the website content and design created specifically for them. Third-party tools, themes, plugins, and software remain subject to their respective licenses. The Client represents that they own or have rights to all content they provide. Modern Ops is not liable for any intellectual property claims arising from Client-provided content. Modern Ops retains the right to display completed work in its portfolio unless the Client requests otherwise in writing.'],
            ['Hosting, Domain & Third-Party Services', 'Hosting and domain fees are billed annually and are non-refundable once paid. Modern Ops is not liable for outages, data loss, or service interruptions caused by third-party hosting providers, domain registrars, or any other external services. The Client is responsible for maintaining their domain registration. Modern Ops does not guarantee uninterrupted availability of any website.'],
            ['Monthly Maintenance & Support', 'Monthly maintenance covers only the tasks explicitly described in the quote. Modern Ops is not liable for security breaches, data loss, or site issues caused by third-party plugins, external attacks, or Client actions. Monthly services may be cancelled with 30 days written notice. No refunds are issued for partial months.'],
            ['Limitation of Liability', 'To the maximum extent permitted by law, Modern Ops shall not be liable for any indirect, incidental, special, or consequential damages, including loss of revenue, data, business, or reputation, arising out of or related to the services provided. Modern Ops total liability for any claim shall not exceed the total amount paid by the Client for the specific service giving rise to the claim.'],
            ['Dispute Resolution', 'Both parties agree to first attempt resolution through good-faith written communication. If a resolution cannot be reached within 30 days, both parties agree to pursue mediation before initiating any legal action. This Agreement shall be governed by the laws of the Commonwealth of Pennsylvania. Any legal proceedings shall take place in Delaware County, Pennsylvania.'],
            ['No Warranty', 'Modern Ops provides all services on an as-is basis and makes no warranties, express or implied, regarding fitness for a particular purpose, search engine rankings, business results, or any specific outcomes.'],
            ['Confidentiality', 'Both parties agree to keep confidential any proprietary or sensitive information shared during the engagement and not to disclose it to third parties without prior written consent.'],
            ['Entire Agreement', 'This Agreement, together with the accompanying quote, constitutes the entire agreement between the parties and supersedes all prior discussions. Any modifications must be made in writing and signed by both parties.'],
        ],
    ],

    // Starting pricebook. Only used to fill an empty Catalog on first run;
    // after that, the Catalog page is the source of truth.
    'catalog' => [
        ['Website Design & Setup', 'Custom website, hosting configuration, domain setup, SSL', 350.00, 'one_time'],
        ['Business Email Setup', 'Professional email address at your domain', 0.00, 'one_time'],
        ['Domain Registration', 'Annual: 1-year domain registration', 15.00, 'one_time'],
        ['Hosting', 'Annual: managed website hosting', 60.00, 'one_time'],
        ['Google Business Profile Setup', 'Create and optimize your Google Business listing', 0.00, 'one_time'],
        ['Social Media Profile Setup', 'Create and brand your social media pages', 0.00, 'one_time'],
        ['Additional Email Account Setup', 'Extra email addresses at your domain', 0.00, 'one_time'],
        ['Site Maintenance', 'Security patches, uptime monitoring, backups', 70.00, 'monthly'],
        ['Site Maintenance & Content Updates', 'Everything in maintenance plus text and photo updates', 120.00, 'monthly'],
    ],
];
