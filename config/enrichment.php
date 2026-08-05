<?php

declare(strict_types=1);

use Elvesora\Enrichment\Laravel\Client;

return [
    'api_key' => env('ELVESORA_ENRICHMENT_API_KEY'),

    'base_url' => env('ELVESORA_ENRICHMENT_BASE_URL', Client::DEFAULT_BASE_URL),

    'timeout' => (float) env('ELVESORA_ENRICHMENT_TIMEOUT', 130),

    'connect_timeout' => (float) env('ELVESORA_ENRICHMENT_CONNECT_TIMEOUT', 10),

    'user_agent' => env('ELVESORA_ENRICHMENT_USER_AGENT', 'Elvesora-Enrichment-Laravel'),
];
