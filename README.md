# PHPLogger

Structured logging for PHP 7.4+, no Composer, no dependencies.

The developer picks a log type, passes typed data, and the package produces one
NDJSON line per event on a stable schema — see [SCHEMA.md](SCHEMA.md).

```
Logger::log(payload)
  → RecordFactory   build and validate the envelope
  → SanitizingGate  fingerprint SQL, allow-list structures, scrub, cap size
  → Channel         level floor, processors
  → Handler[]       stream / error_log / null, behind a circuit breaker
```

## Install

No package manager. Copy the directory into the project and require the
autoloader:

```php
require __DIR__ . '/vendor-local/PHPLogger/autoload.php';
```

`autoload.php` registers a PSR-4 autoloader for a single namespace prefix.
Change `PHP_LOGGER_NAMESPACE_PREFIX` there to the company namespace — the
directory layout already matches PSR-4, so nothing else has to change, today or
the day Composer becomes an option.

### Layout

Implementations sit under `src/<Module>/`. Every **interface** lives under
`src/Interfaces/<Module>/<Name>Interface.php`, namespaced
`Logger\Interfaces\<Module>`, so the contracts are all in one place:

```
src/Interfaces/Handler/HandlerInterface.php   Logger\Interfaces\Handler\HandlerInterface
src/Interfaces/Context/ContextProviderInterface.php
src/Handler/StreamHandler.php                 implements the first
```

`Logger\Contract` keeps `LogRecord` and `LogError`: those are concrete value
objects, not contracts.

## Quick start

```php
use Logger\Enum\Outcome;
use Logger\LoggerFactory;
use Logger\Payload\DbQueryPayload;
use Logger\Support\Stopwatch;

$logger = LoggerFactory::fromArray(require __DIR__ . '/config/logger.php');

$stopwatch = Stopwatch::start();
$statement = $pdo->prepare($sql);
$executed = $statement->execute($params);

$logger->log(
    DbQueryPayload::create('user_lookup', 'production_db')
        ->withStatement($sql, $params)
        ->withRowCount($statement->rowCount())
        ->withDuration($stopwatch->elapsed())
        ->withOutcome($executed ? Outcome::SUCCESS : Outcome::FAILURE)
);
```

PHP 7.4 has no named arguments, so payloads are built with immutable `with*`
methods. Every one of them returns a copy.

```php
// Outbound call
ServiceCallPayload::create('si_service_call', 'GET', $url)
    ->withHttpCode(200)
    ->withTiming($dns, $connect, $ttfb, $total)
    ->withDuration($elapsed)
    ->withSuccess();

// Exception
PhpLogPayload::fromThrowable('uncaught_exception', $exception);

// PHP error, from an error handler
PhpLogPayload::fromPhpError('error_handler', $severity, $message, $file, $line);

// Free-form application log
PhpLogPayload::create('custom_log', 'checkout started')->withContext(['route' => '/checkout']);
```

### Channels

Routing is per log type, with an explicit override when needed:

```php
$logger->log($payload);                 // routed by log_type
$logger->channel('errors')->log($payload); // pinned channel
```

Channels, routing, sampling and limits live in the config file; see
[config/logger.example.php](config/logger.example.php), which documents every key.
Configuration values must arrive **already resolved**: the package never reads
`getenv()`, `$_ENV` or a framework helper.

### Third party libraries

`PsrStyleLogger` exposes the PSR-3 method signatures. The interface itself is not
declared because `psr/log` cannot be installed without Composer; when the host
application already provides it, a subclass adding `implements
\Psr\Log\LoggerInterface` is enough.

## What the sanitizer guarantees, and what it does not

Two different strengths, worth keeping straight:

**Structural (a guarantee).** Every array-valued field under `data` —
`params`, `payload`, `context` — is dropped unless the configuration allow-lists
its keys. Deny by default, no content inspection involved. The number of dropped
entries is reported as `_omitted` so the omission is visible.

SQL statements are emitted as a normalized fingerprint, so an interpolated query
cannot leak its values. Stack frames never carry call arguments. `session_ref`
and `user_ref` are HMAC pseudonyms; with no `pepper` configured they are simply
absent, never the raw value.

**Best effort (a mitigation).** Free text — an exception message, a URL, a user
agent — goes through a scrubber that masks bearer tokens, JWTs, secrets in query
strings, emails, IBANs, Italian fiscal codes and card numbers that pass the Luhn
check. A secret in an unexpected shape gets through. Do not rely on it as the
only line of defence, and note that it is deliberately aggressive: a long opaque
string in a payload may be masked even when it was harmless.

Filesystem paths are the one shape explicitly kept out of that rule, so
`data.file` and the frames in `error.stack_trace` stay readable. The cost is
that a standard-base64 blob whose slashes happen to fall close together can slip
past; the hex and JWT rules still cover the common shapes.

Cookies are never emitted, in any configuration.

## What happens when something breaks

| Situation | Behaviour |
| --- | --- |
| Invalid configuration | `InvalidConfigurationException` at boot. The application does not start |
| Event built with invalid data | `InvalidLogEventException`; propagated when `strict_events` is on, degraded into a meta record otherwise |
| Sanitization fails | Record dropped everywhere, including the fail-safe path |
| Destination unavailable | Caught, circuit breaker opens, the application is not interrupted |
| Failure while logging an exception | The original exception always survives |

The guard wraps the whole pipeline, not only the I/O, and a reentrancy flag
prevents an error raised inside the logger from re-entering it through the
application error handler. Fail-safe records are rate limited per request and
carry no caller-supplied data at all.

Write failures are detected properly: `fwrite` can write only part of the buffer
and can return `false`, and both are handled — a half-written line is a corrupted
record for the collector.

## Destination

The default target is `php://stdout`. `error_log()` is available as a fallback
handler, but where its output ends up and whether it gets a prefix depends on the
`error_log` ini setting and on the SAPI: it is not guaranteed to be stderr and
not guaranteed to stay valid NDJSON. Prefer a stream target when the collector
parses JSON.

## Extending

A new log type is one class:

```php
final class QueueJobPayload extends AbstractPayload
{
    public function logType(): string { return 'queue_job'; }

    public function data(): array
    {
        return ['queue' => $this->queue, 'attempts' => $this->attempts];
    }
}
```

The pipeline never needs to know the concrete class. Remember to allow-list any
array-valued field in `capture.fields`, otherwise it is dropped — which is the
intended default.

## Tests

There are two ways to get a working suite. Pick either one; they run the same
tests.

**With Docker — nothing needed on the host.** No PHP, no Composer, no
extension. The image builds its own `vendor/`, on the oldest runtime the package
supports:

```bash
docker compose up                                # build everything and run the suite
docker compose run --rm test                     # the same, with a real exit code for CI
docker compose run --rm test --testsuite unit    # any PHPUnit argument
docker compose run --rm coverage                 # the suite with a coverage report
docker compose run --rm php -v                   # a PHP 7.4.3 command line
docker compose run --rm composer update          # dependency work
```

`docker compose up` starts only the test service; the others sit behind a
profile, which `docker compose run` enables on its own. See
[docker-compose.yml](docker-compose.yml).

**With Composer — on a host that already has PHP 7.4+:**

```bash
composer install                     # installs PHPUnit, nothing else
vendor/bin/phpunit                   # everything
vendor/bin/phpunit --testsuite unit
vendor/bin/phpunit --filter Sanitize
```

`vendor/` is generated and git-ignored; it is never committed. Coverage needs a
driver (pcov or Xdebug), which the Docker image already ships — the `coverage`
service is the easier route.

### Why Composer is here at all

Composer is **dev-only tooling**. It installs PHPUnit and autoloads the
`Logger\Tests\` namespace, and it declares no autoload rule for the library:
`tests/bootstrap.php` requires the `autoload.php` that ships with the package
instead, so every test exercises the loading path a consumer actually gets and
that file cannot rot. Nothing in `src/` has a runtime dependency, and copying
the directory into a project keeps working exactly as before.

`config.platform.php` is pinned to `7.4.3`, so Composer resolves for the oldest
supported runtime no matter which PHP runs it.

### What the suite pins

`tests/Integration/SchemaContractTest.php` pins the wire format: any change to a
field name, type or order fails the suite on purpose — see the compatibility
policy in [SCHEMA.md](SCHEMA.md).

Tests run in random order, so a test that depends on another one fails quickly.

## Not included yet

Integration adapters (PDO, cURL, `set_error_handler` / `set_exception_handler` /
`register_shutdown_function`) are deliberately out of the core: deciding whether
an operation succeeded belongs to them, not to a generic wrapper. A `Stopwatch`
that only measures duration is provided in the meantime.
