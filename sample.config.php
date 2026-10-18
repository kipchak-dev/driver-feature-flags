<?php

use function Kipchak\Core\env;

/**
 * Feature flags. Each connection is one project or environment in Unleash, Flagsmith or GrowthBook; get it with
 * FeatureFlags::get('<name>'). Install the SDK for each provider you use:
 *
 *   composer require unleash/client                   # Unleash
 *   composer require flagsmith/flagsmith-php-client    # Flagsmith (needs ext-bcmath and ext-gmp)
 *   composer require growthbook/growthbook            # GrowthBook
 */
return [
    'connections' => [
        'default' => [
            'provider' => 'unleash',

            // The Unleash API (ending in /api) and a backend (client) token for one environment.
            'url' => env('UNLEASH_URL', 'http://unleash:4242/api'),
            'token' => env('UNLEASH_TOKEN', ''),

            // How this API appears under Applications in Unleash. The instance id defaults to the hostname.
            'app_name' => env('UNLEASH_APP_NAME', 'kipchak-api'),
            // 'instance_id' => 'api-1',

            // Sets the "environment" context field, for strategies that use it. Leave it unset otherwise: the
            // token already selects which environment's toggles are fetched.
            // 'environment' => 'production',

            // Usage reporting to Unleash (sent from the request that is evaluating when the interval runs out).
            'metrics' => true,
            'metrics_interval' => 60,
            'register' => true,

            // How long Unleash's last answer is kept to fall back on while Unleash is unreachable, in seconds.
            'stale_ttl' => 86400,

            // Settings every provider takes:

            // Seconds between fetches of the flag definitions. Flags are evaluated in process from these.
            'refresh_interval' => 30,

            // Where the definitions are kept between requests: "memory" (each FrankenPHP worker keeps its own),
            // "file" (Kipchak's Filecache driver, shared by the processes on one machine), or the container key of
            // another Kipchak cache, such as "drivers.cache.valkey.cache" (shared by every machine). Initialise the
            // cache driver before FeatureFlags. Outside worker mode, "memory" lasts one request, so use "file" or
            // a shared cache there.
            'cache' => 'memory',

            // Seconds. Fetches happen on the request path, so keep these short.
            'timeout' => 2.0,
            'connect_timeout' => 1.0,

            // A psr18 client from kipchak.http to fetch with instead (for a proxy or custom TLS). The timeouts
            // above then do not apply.
            // 'http_client' => 'flags',

            // Flag definitions to start from, used until the first fetch works. With no url, they are the only
            // source: no fetching at all, which suits tests and air-gapped deployments.
            //  - Unleash: a /api/client/features response
            //  - Flagsmith: an environment document (/api/v1/environment-document/)
            //  - GrowthBook: an SDK endpoint response (/api/features/<client key>)
            // 'bootstrap_file' => __DIR__ . '/flags.json',

            // false: an unreachable provider is logged and every check returns your default. true: it throws a
            // FeatureFlagException.
            'strict' => false,
        ],

        // 'flagsmith' => [
        //     'provider' => 'flagsmith',
        //     // Defaults to Flagsmith's hosted edge API. For self-hosted Flagsmith, the API ending in /api/v1.
        //     'url' => env('FLAGSMITH_URL', 'https://edge.api.flagsmith.com/api/v1'),
        //     // A server-side key ("ser....") evaluates locally from the environment document, downloaded every
        //     // refresh_interval. A client key evaluates remotely: one API call per check, cached for
        //     // refresh_interval, and Flagsmith stores each identity's traits.
        //     'environment_key' => env('FLAGSMITH_ENVIRONMENT_KEY', ''),
        //     // Defaults to true for a server-side key.
        //     // 'local_evaluation' => true,
        //     // Flag usage analytics, sent to Flagsmith.
        //     'analytics' => false,
        //     'refresh_interval' => 30,
        //     'cache' => 'memory',
        // ],

        // 'growthbook' => [
        //     'provider' => 'growthbook',
        //     // Defaults to GrowthBook Cloud's CDN. For self-hosted GrowthBook, its API host.
        //     'url' => env('GROWTHBOOK_API_HOST', 'https://cdn.growthbook.io'),
        //     // The SDK connection's client key, and its decryption key when the connection encrypts features.
        //     'client_key' => env('GROWTHBOOK_CLIENT_KEY', ''),
        //     'decryption_key' => env('GROWTHBOOK_DECRYPTION_KEY', ''),
        //     'refresh_interval' => 30,
        //     'cache' => 'memory',
        // ],
    ],
];
