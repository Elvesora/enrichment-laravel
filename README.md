# Elvesora Enrichment for Laravel

Self-contained Laravel 12 and 13 client for the [Elvesora Company Enrichment API](https://www.elvesora.com/products/data-enrichment), with auto-discovery, dependency injection, a namespaced facade, immutable results and credit metadata, typed exceptions, idempotency support, and publishable configuration.

This is a client-only package. By default, it performs one outbound HTTPS request to the Enrichment API for each call. It does not require a database, Redis, a cache, queues, workers, migrations, or package-owned server infrastructure; API-side enrichment, credit accounting, and idempotency storage remain the responsibility of the Elvesora service.

## Requirements

- PHP 8.2 or later
- PHP `json` and `mbstring` extensions
- Laravel 12 or 13
- An Elvesora API key

Laravel 13 requires PHP 8.3 or later.

Obtain and manage the key through the [Elvesora Enrichment documentation](https://www.elvesora.com/docs/enrichment). Keep it in server-side configuration; never expose it in browser or mobile client code.

## Installation

```console
composer require elvesora/enrichment-laravel
```

The service provider is discovered automatically. This package contains its own Guzzle client and does not require `elvesora/enrichment-php`.

## Configuration

Add the API key to `.env`:

```dotenv
ELVESORA_ENRICHMENT_API_KEY=your-api-key
```

Optional settings:

```dotenv
ELVESORA_ENRICHMENT_BASE_URL=https://enrichment.elvesora.com/api/v1
ELVESORA_ENRICHMENT_TIMEOUT=130
ELVESORA_ENRICHMENT_CONNECT_TIMEOUT=10
ELVESORA_ENRICHMENT_USER_AGENT=My-Laravel-App/1.0
```

Timeout values are seconds and must be finite and greater than zero. The connection timeout is capped internally at the total timeout. A custom base URL must be an absolute HTTP or HTTPS URL with a host and cannot contain embedded credentials, a query string, or a fragment. Keep production endpoints on HTTPS; plain HTTP is intended only for controlled local testing. Redirects are not followed, so domain data stays on the configured API origin. The user agent must be a non-empty, valid UTF-8 string without control characters.

Publish the configuration when you need to customize or inspect it:

```console
php artisan vendor:publish --tag=enrichment-config
```

Credentials are checked only when the client is first resolved, so package discovery and unrelated Artisan commands do not require an API key.

## Dependency injection

```php
<?php

namespace App\Services;

use Elvesora\Enrichment\Laravel\Contracts\EnrichmentClientInterface;
use Elvesora\Enrichment\Laravel\EnrichmentResult;

final class EnrichAccount
{
    public function __construct(
        private readonly EnrichmentClientInterface $enrichment,
    ) {
    }

    public function handle(string $domain, string $jobId): EnrichmentResult
    {
        return $this->enrichment->enrich(
            domain: $domain,
            idempotencyKey: $jobId,
        );
    }
}
```

## Facade

```php
use Elvesora\Enrichment\Laravel\Facades\Enrichment;

$result = Enrichment::enrich(
    domain: 'acme.com',
    idempotencyKey: 'crm-account-123',
);

echo $result->data['company_name'] ?? $result->domain;
echo $result->credits->remaining;
```

The facade is namespaced and is not registered as a global Laravel alias.

## Results and credits

`EnrichmentResult` exposes readonly properties for:

- `success`, `resultType`, and `message`
- `data`, containing the structured company payload
- `domain`, a nullable convenience value read from `data.domain`
- `credits`, an immutable `CreditMetadata` object
- `statusCode` and an optional API `responseCode`
- `idempotencyStatus`, which is `replayed` when the API returns a cached idempotent response

`CreditMetadata` exposes readonly `limit`, `used`, `remaining`, `consumedByRequest`, `periodStartedAt`, and `periodEndsAt` properties. Use `$result->toArray()`, `$result->credits->toArray()`, or `json_encode()` when arrays or JSON are more convenient.

`$result->isEnriched()` confirms the successful `ENRICHED` contract. `$result->wasReplayed()` reports whether `Idempotency-Status: replayed` was present.

## Idempotency

The optional idempotency key is sent as `Idempotency-Key`. Responses are retained by the API for 24 hours. Reusing a key with the same domain returns the retained response without consuming another credit. Reusing it with a different domain throws `IdempotencyConflictException` and exposes `idempotencyStatus === 'conflict'`.

Domains and idempotency keys must be valid UTF-8. They are trimmed and limited to 255 characters. An empty optional key is omitted. Invalid local values are rejected before an HTTP request and consume no credit.

## Errors and retries

```php
use Elvesora\Enrichment\Laravel\Exception\EnrichmentException;
use Elvesora\Enrichment\Laravel\Facades\Enrichment;

try {
    $result = Enrichment::enrich('acme.com', 'crm-account-123');
} catch (\InvalidArgumentException $exception) {
    // Invalid client configuration, domain, or idempotency key.
} catch (\LogicException $exception) {
    // ELVESORA_ENRICHMENT_API_KEY or another package setting is missing.
} catch (EnrichmentException $exception) {
    report($exception);

    if ($exception->retryable) {
        $retryAfter = $exception->retryAfter;
    }
}
```

### Exception reference

| Exception | When it is thrown |
| --- | --- |
| `\LogicException` | A required Laravel package setting is missing, empty, or not the expected scalar type when the client is resolved. |
| `\InvalidArgumentException` | A configured URL, timeout, user agent, domain, or idempotency key has an invalid value; no HTTP request is sent. |
| `AuthenticationException` | The response has `result_type: UNAUTHORIZED`, or is a bare HTTP 401 response. |
| `IdempotencyConflictException` | The response has `result_type: IDEMPOTENCY_KEY_CONFLICT`, or is a bare HTTP 409 response. |
| `ValidationException` | The response has `result_type: VALIDATION_ERROR`, or is a bare HTTP 422 response. Use `errors()` for field-level messages. |
| `UsageLimitException` | The response has `result_type: LIMIT_EXCEEDED`, or is a bare HTTP 429 response. Use `limit()` and `remaining()` for credit metadata. |
| `ApiException` | The API returns another non-success response, including non-chargeable HTTP 400 outcomes and `UPSTREAM_ERROR`. |
| `InvalidResponseException` | The API response is malformed JSON, is not a JSON object, has an invalid idempotency header, or violates the successful result contract. |
| `TransportException` | The HTTP client cannot complete the request because of a connection, DNS, TLS, or similar transport failure. |

All package API exceptions extend `Elvesora\Enrichment\Laravel\Exception\EnrichmentException`. It exposes readonly `statusCode`, `resultType`, `retryable`, `retryAfter`, `responseBody`, `credits`, and `idempotencyStatus` properties. Retryable gateway responses preserve a numeric `Retry-After` value even when their body is malformed. API keys are never added to exception messages or retained transport exceptions, and native plus Symfony/Laravel debug output redacts the stored key. Treat `responseBody` as application data and redact it according to your logging policy.

Exception selection follows `result_type` before HTTP status. For example, an upstream failure preserved as HTTP 422 with `result_type: UPSTREAM_ERROR` remains an `ApiException`, not a local request-validation exception. An upstream `429` is a retryable `ApiException`; an account-level `LIMIT_EXCEEDED` response remains a non-retryable `UsageLimitException`.

The client does not retry automatically. A successful `ENRICHED` response normally consumes one credit; an idempotent replay reports `consumedByRequest === 0`. Non-chargeable HTTP 400 outcomes, conflicts, local validation failures, exhausted-credit responses, and server failures do not consume an enrichment credit according to the API response metadata. Use explicit retry policy in your application and reuse the same idempotency key for a retry of the same logical request.

## Testing applications

Replace the interface binding with a test double; no live HTTP request is needed:

```php
$this->app->instance(
    \Elvesora\Enrichment\Laravel\Contracts\EnrichmentClientInterface::class,
    $fakeEnrichmentClient,
);
```

## Package development

```console
composer install
composer check
```

The test suite uses mocked HTTP responses and sends no requests to the live Elvesora API.

## License

MIT. See [LICENSE](LICENSE).
