<?php

// ETTS has no public cross-origin API. The kiosk (Phase 8) is served by this
// app itself, so no origin needs CORS access.
return [

    'paths' => [],

    'allowed_methods' => ['GET'],

    'allowed_origins' => [],

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => false,

];
