# Feature Flags Driver for Kipchak

A feature flag driver for Kipchak: turn features on and off, roll them out to some users, and run experiments,
with the flags managed in [Unleash](https://www.getunleash.io), [Flagsmith](https://www.flagsmith.com) or
[GrowthBook](https://www.growthbook.io).

* **One API for three services**: `isEnabled()`, `value()` and `variant()` work the same whichever service a
  connection uses, so moving between them changes configuration, not code.
* **Evaluated in process**: the flag definitions are fetched every `refresh_interval` and each check is worked out
  in PHP, so a check costs microseconds, not a network call.
* **Never takes the API down**: when the service cannot be reached, the last flags fetched keep being used, and
  before any have been fetched every check returns the default you give it. With `'strict' => true` it throws instead.
* **Worker mode**: each FrankenPHP worker keeps the definitions in memory between requests, and nothing about one
  request's user reaches the next.
* **Shared caches**: the definitions can live in Kipchak's Filecache or Valkey drivers, so processes and machines
  share one fetch.
* **Bootstrap files**: start from, or run entirely on, a file of flag definitions, for tests and air-gapped
  deployments.

Built on each service's own PHP SDK, so targeting rules, percentage rollouts, segments and experiments are
evaluated exactly as the service defines them. Tested against Unleash 8.2, Flagsmith 2.286 and GrowthBook (October
2026).

#### Composer Package

`kipchak/driver-feature-flags`

## Installation

Install the driver and the SDK of each service you use:

```bash
composer require kipchak/driver-feature-flags

composer require unleash/client                   # Unleash
composer require flagsmith/flagsmith-php-client    # Flagsmith (needs ext-bcmath and ext-gmp)
composer require growthbook/growthbook            # GrowthBook
```

Initialise it in `drivers/drivers.php`, after the Config, Logger, Http and Filecache drivers:

```php
use Kipchak\Driver\FeatureFlags\FeatureFlags;

FeatureFlags::initialise($container);
```

The Logger driver is optional; when it is there, failures to reach the service are logged through it.

## Configuration

Copy [`sample.config.php`](sample.config.php) to `config/kipchak.feature-flags.php`. Each connection is one
project or environment in one service:

```php
return [
    'connections' => [
        'default' => [
            'provider' => 'unleash',
            'url' => env('UNLEASH_URL', 'http://unleash:4242/api'),
            'token' => env('UNLEASH_TOKEN', ''),
            'app_name' => 'orders-api',
        ],
        'experiments' => [
            'provider' => 'growthbook',
            'client_key' => env('GROWTHBOOK_CLIENT_KEY', ''),
        ],
    ],
];
```

Fetch them by name: `FeatureFlags::get()` is `default`, `FeatureFlags::get('experiments')` the other.

### Settings for every provider

| Key | Default | Meaning |
|---|---|---|
| `provider` | required | `unleash`, `flagsmith` or `growthbook` |
| `refresh_interval` | `30` | Seconds between fetches of the flag definitions |
| `cache` | `memory` | Where the definitions are kept between requests: see [Caching](#caching) |
| `timeout` | `2.0` | Seconds for a whole fetch. Fetches happen on the request path, so keep it short. |
| `connect_timeout` | `1.0` | Seconds to connect |
| `http_client` | none | A `psr18` client from `kipchak.http` to fetch with, for a proxy or custom TLS. The two timeouts above then come from that client. |
| `bootstrap_file` | none | Flag definitions to start from, used until the first fetch works. Without a `url` or key, they are the only source and nothing is fetched. |
| `strict` | `false` | `false`: when the service cannot be reached, log it and return the default. `true`: throw a `FeatureFlagException`. |

### Unleash

| Key | Default | Meaning |
|---|---|---|
| `url` | required | The Unleash API, ending in `/api` |
| `token` | none | A backend (client) token. It selects the environment whose toggles are fetched. |
| `app_name` | `kipchak` | How this API appears under Applications in Unleash |
| `instance_id` | the hostname | |
| `environment` | none | Fills the `environment` context field, for strategies that constrain on it |
| `metrics` | `true` | Report toggle usage to Unleash, every `metrics_interval` seconds (`60`) |
| `register` | `true` | Register with Unleash when a worker first uses the connection |
| `stale_ttl` | `86400` | Seconds the last answer from Unleash is kept, to fall back on while it is down |

### Flagsmith

| Key | Default | Meaning |
|---|---|---|
| `url` | Flagsmith's edge API | For self-hosted Flagsmith, its API, ending in `/api/v1` |
| `environment_key` | required | A server-side key (`ser.…`) or a client key |
| `local_evaluation` | `true` for a server-side key | Evaluate locally from the environment document |
| `analytics` | `false` | Send flag usage analytics to Flagsmith |

With a server-side key, the environment document is downloaded every `refresh_interval` and flags are evaluated
locally. With a client key, flags are evaluated remotely: each check for a new user calls the Flagsmith API (the
answer is cached for `refresh_interval`), and Flagsmith stores the user's attributes as traits of that identity.
Use a server-side key on servers.

### GrowthBook

| Key | Default | Meaning |
|---|---|---|
| `url` | GrowthBook Cloud's CDN | For self-hosted GrowthBook, its API host |
| `client_key` | required | The SDK connection's client key (`sdk-…`) |
| `decryption_key` | none | For an SDK connection that encrypts its features |

## Usage

```php
use Kipchak\Driver\FeatureFlags\FeatureFlags;
use Kipchak\Driver\FeatureFlags\Request\Context;

$flags = FeatureFlags::get();
$context = new Context(userId: $customer->id, attributes: ['plan' => $customer->plan, 'country' => 'GB']);

if ($flags->isEnabled('new-checkout', $context)) {
    // ...
}

$limit = $flags->value('rate-limit', default: 100, context: $context);
$colour = $flags->variant('button-colour', $context);    // the variant's name, or null
```

| Method | Returns |
|---|---|
| `isEnabled($flag, $context, $default = false)` | Whether the flag is on. `$default` for a flag the service does not have, or when it cannot be reached. |
| `value($flag, $default = null, $context)` | The flag's value, or `$default` when it has none |
| `variant($flag, $context)` | The name of the variant this user was given, or `null` |
| `evaluate($flag, $context)` | A `Flag` with all of it: `enabled`, `value`, `variant`, and `source` |
| `refresh()` | Fetches the definitions again now. Call it from a webhook the service sends when a flag changes. |

`$context` can be left out, for flags that do not depend on who is asking.

### The context

`Context` is who the flag is being evaluated for. It is immutable: build one per request.

```php
$context = new Context(
    userId: 'customer-42',
    attributes: ['plan' => 'pro', 'beta' => true, 'tags' => ['wholesale', 'eu']],
    sessionId: $sessionId,
    ipAddress: $request->getServerParams()['REMOTE_ADDR'] ?? null,
);
$context = $context->withAttribute('country', 'GB');
```

Each service receives it in its own terms:

| | Unleash | Flagsmith | GrowthBook |
|---|---|---|---|
| `userId` | `userId` | the identity | attribute `id` |
| `attributes` | custom properties, as strings (`true`/`false`, lists joined with commas) | traits (lists joined with commas) | attributes, as they are |
| `sessionId` | `sessionId` | not used | attribute `sessionId` |
| `ipAddress` | `remoteAddress` | not used | attribute `ipAddress` |

Without a `userId`, Flagsmith returns the environment's flags and ignores the attributes. Attributes that are `null`
are left out: in Flagsmith's remote evaluation, a null trait would delete the trait stored for the identity.

### Values and variants

What `value()` returns depends on what the service attaches to a flag:

* **Unleash**: the payload of the variant the user was given. JSON is decoded to an array, a number to an `int` or
  `float`. A toggle that is off has no value.
* **Flagsmith**: the feature's value. Flagsmith keeps the value whether the flag is on or off.
* **GrowthBook**: the feature's value. GrowthBook calls a feature on when its value is truthy, so `0` or `""` is a
  real value of a feature that is off; `value()` returns it.

`variant()` is the Unleash variant, or the key of the GrowthBook experiment variation the user is in. Flagsmith does
not name its multivariate values, so it is always `null` there.

`evaluate()` also says where the answer came from: `$flag->source` is `provider` when the service evaluated it,
`unknown` when the service has no such flag, and `error` when it could not be reached. Unleash does not say when a
toggle is unknown, so an unknown toggle comes back as `provider` and off.

## Caching

The `cache` setting says where the flag definitions are kept between requests:

* `memory` (the default): in the worker's memory. Each FrankenPHP worker fetches the definitions once per
  `refresh_interval`. Outside worker mode, memory lasts one request, so the definitions would be fetched on every
  request: use one of the others there.
* `file`: Kipchak's [Filecache driver](https://kipchak.dev/docs/driver-filecache), shared by every PHP process on
  the machine. Initialise `Filecache` before `FeatureFlags`.
* The container key of another Kipchak cache, such as `drivers.cache.valkey.cache` from the
  [Valkey driver](https://kipchak.dev/docs/driver-valkey), shared by every machine using it. Any PSR-6 or PSR-16
  cache in the container works.

Each connection gets its own namespace in a shared cache.

## When the service is down

A flag check never fails the request unless the connection is `strict`:

* After a fetch has worked, the last definitions fetched keep being used, for as long as the service is down.
  Unleash also keeps its last answer in the cache for `stale_ttl`, so a restarted worker can use it.
* Before any fetch has worked, every check returns its default and the failure is logged, at most once a minute.
  A `bootstrap_file` gives the checks something better than the default to answer with.
* A fetch that fails is retried after 5 seconds, then 10, 20, 40, and once a minute while the service stays down.
  Between attempts, checks do not wait on the network. Unleash's SDK keeps its own schedule and retries once per
  `refresh_interval`.

Each attempt is made by whichever request is being handled when it is due, so a service that hangs costs that one
request up to `timeout`.

## Errors

| Exception | Meaning |
|---|---|
| `FeatureFlagException` | Only with `'strict' => true`: the service could not be reached and nothing had been fetched, or it rejected the key. `$flag` and `$provider` say which. |
| `InvalidArgumentException` | A configuration mistake, such as a missing key or an SDK that is not installed. The message names the connection. |

## Testing

```bash
composer install
vendor/bin/phpunit --testsuite Unit

# Integration tests run against real Unleash, Flagsmith and GrowthBook servers in Docker. The script starts them,
# creates the flags the tests expect and writes the keys to tests/Integration/.keys.env:
tests/Integration/start-services.sh
vendor/bin/phpunit

docker compose -f tests/Integration/compose.yaml down -v
```

## What is a Kipchak Driver?

Kipchak Drivers are used to connect Kipchak to various data sources or storage systems.

They provide a standardized interface for interacting with different data sources, allowing developers to
focus on building their applications rather than dealing with the complexities of each data source.

Drivers are basically Container Dependencies injected into Kipchak's Service Container.

## Licence

MIT.
