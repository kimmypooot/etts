<?php

return [

    /*
    | Fees for certification and authentication requests, in pesos per copy.
    | The legacy form priced a request as COE copies x 100 + authentication
    | copies x 50 (so "COE-1, AUTH-2" is 200).
    */
    'prices' => [
        'coe' => (int) env('ETTS_PRICE_COE', 100),
        'auth' => (int) env('ETTS_PRICE_AUTH', 50),
    ],

    /*
    | Applicant attachments (IDs, certificates). Only these types are accepted;
    | each is checked by its real content, not only its file name.
    */
    'attachments' => [
        'max_kb' => (int) env('ETTS_ATTACHMENT_MAX_KB', 5120),
        'types' => [
            'pdf' => ['application/pdf'],
            'jpg' => ['image/jpeg'],
            'jpeg' => ['image/jpeg'],
            'png' => ['image/png'],
        ],
    ],

    /*
    | The reasons offered for a certification or authentication request. The
    | form also takes "Others" with a free-text reason, stored as "Others: ...".
    */
    'purposes' => [
        'Employment',
        'Promotion',
        'Personal',
        'Did not receive original certificate',
        'Replacement of lost certificate',
        'Replacement of old/torn/worn-out certificate',
    ],

    /*
    | The public walk-in kiosks (the ESD kiosk and the PACD kiosk are the same
    | app: a terminal is paired to an office, and decides where requests go).
    |
    |  - domains: the host names that serve the kiosk (for example
    |    kiosk.cscro8.com and the PACD kiosk's host). When none is set (local
    |    development) the kiosk is served under /kiosk on the main host. A kiosk
    |    host serves ONLY the kiosk: every staff page answers 404 there.
    |  - files_at_esd: office codes whose terminals file their requests under ESD
    |    (the PACD kiosk is a front desk for ESD's transactions).
    |  - pairing_minutes: how long a one-time pairing code can be used.
    |  - idle_seconds / done_seconds: how long a form may sit untouched before it
    |    is cleared, and how long the confirmation stays up.
    */
    'kiosk' => [
        'domains' => array_values(array_filter(array_map('trim', explode(',', (string) env('ETTS_KIOSK_DOMAINS', ''))))),
        'files_at_esd' => ['PALD'],
        'pairing_minutes' => (int) env('ETTS_KIOSK_PAIRING_MINUTES', 15),
        'idle_seconds' => (int) env('ETTS_KIOSK_IDLE_SECONDS', 180),
        'done_seconds' => (int) env('ETTS_KIOSK_DONE_SECONDS', 20),
        'session_minutes' => 720,
    ],

    /*
    | "Sync from legacy": lets an administrator pull the legacy database's
    | latest changes in while the old app is still the one in use. Leave it
    | OFF in production once ETTS is live: from then on this system is the
    | source of truth and the legacy copy is stale.
    */
    'legacy_sync' => [
        'enabled' => (bool) env('ETTS_LEGACY_SYNC', false),
    ],

    /*
    | The sister systems in the header's app switcher ("Examination-Related
    | Info. System", as in the legacy header): ESD staff jump to them from
    | here. Paths hang off ETTS_ERIS_URL, so a move of ERIS is one .env edit.
    | `icon` is a name from Components/UI/Icon.vue.
    */
    'related_systems' => [
        'base_url' => rtrim((string) env('ETTS_ERIS_URL', 'https://imis.cscro8.com/eris/esd'), '/'),
        'items' => [
            ['label' => 'ONSA', 'path' => '/db_onsa', 'icon' => 'grid'],
            ['label' => 'ENLOOKUP', 'path' => '/enlookup/index', 'icon' => 'shield'],
            ['label' => 'COMEXAMS', 'path' => '/index_comex_sched', 'icon' => 'users'],
            ['label' => 'Central DB', 'path' => '/db', 'icon' => 'database'],
            ['label' => 'COPI Project', 'path' => '/index_copi', 'icon' => 'user'],
            ['label' => 'ATON', 'path' => '/aton/index', 'icon' => 'globe'],
        ],
    ],

    /*
    | How IMIS names each office (its organisational unit code) against the code
    | this system has used since the start. Sign-in uses it to put a person at
    | their office; the reference import writes it into `offices.imis_code`.
    */
    'imis_office_codes' => [
        'ESD' => 'ESD',
        'PALD' => 'PALD',
        'FO-LEYTE 1' => 'LFO-I',
        'FO-LEYTE 2' => 'LFO-II',
        'FO-EASTERN SAMAR' => 'ESFO',
        'FO-SAMAR' => 'SFO',
        'FO-BILIRAN' => 'BFO',
        'FO-SOUTHERN LEYTE' => 'SLFO',
        'FO-NORTHERN SAMAR' => 'NSFO',
        'SO-WESTERN LEYTE' => 'WLSO',
    ],

    /*
    | Default wording and signatories of the routing slip. They are only
    | defaults: the person printing a slip can change every field. A change of
    | signatory is a one-line .env edit, never a code change.
    */
    'transmittal' => [
        'esd_to_fo' => [
            'rsu' => env('ETTS_SLIP_RSU', 'Examination Services Division'),
            'signatory' => env('ETTS_SLIP_SIGNATORY', 'ATTY. GERVIEN T. BERECIO'),
            'designation' => env('ETTS_SLIP_DESIGNATION', 'Attorney V / ESD Caretaker'),
            'body' => "Dear Director [lastname],\n\nWe transmit to your Office the following documents for release to the concerned individuals:",
            'closing' => "Please ensure that each document is released only upon compliance with the above-stated conditions.\n\nThank you.",
        ],
        'fo_to_ro' => [
            'rsu' => env('ETTS_SLIP_RO_NAME', 'CSC Regional Office VIII'),
            'addressee' => env('ETTS_SLIP_RO_ADDRESSEE', 'ATTY. MARILYN E. TALDO'),
            'position' => env('ETTS_SLIP_RO_POSITION', 'Director IV'),
            'address' => env('ETTS_SLIP_RO_ADDRESS', 'Government Center, Palo, Leyte'),
            'designation' => env('ETTS_SLIP_FO_DESIGNATION', 'Director II'),
            'body' => "Dear Sir/Madam,\n\nWe are transmitting to your Office the following Special Eligibility Applications and supporting documents for your evaluation and appropriate action:",
            'closing' => "We look forward to your favorable action on the above.\n\nThank you.",
        ],
    ],

];
