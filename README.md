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

## Adapters

`src/Adapter/` holds the three integration points most applications need. They
are the only part of the package that knows anything about PDO, cURL or PHP's
error machinery, and the judgement they cannot make on their own — did this
operation succeed? — is always injected rather than hard-coded.

### Database

`LoggingPdo` is a `PDO` subclass, so it drops into any signature that already
type-hints `PDO`:

```php
$pdo = new LoggingPdo($dsn, $user, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION], $logger, 'production_db');
$pdo->prepare('SELECT id FROM users WHERE tenant_id = ?')->execute([$tenantId]);
```

It needs four interception points, not one. Prepared statements report from
`LoggingStatement`, but `exec()` and `query()` never build one, and SQLite and
PostgreSQL reject a broken statement inside `prepare()` itself, so `execute()` is
never reached. A failure is recorded **before** the exception is rethrown: with
`ERRMODE_EXCEPTION` the failed queries are precisely the ones a wrapper that logs
after the call would lose.

Values bound with `bindValue()` and `bindParam()` are captured as well, the
latter by reference so the value logged is the one actually sent. What reaches
the log is still decided by `capture.fields`.

### Outbound HTTP

There is nothing to subclass — a handle is a `resource` on 7.4 and a
`CurlHandle` on 8.0+ — so the adapter is a collaborator around `curl_exec()`:

```php
$recorder = new CurlRecorder($logger);              // or: new CurlRecorder($logger, $policy)
$body = $recorder->execute($handle, 'GET');         // drop-in for curl_exec()
$recorder->record($handle, 'POST');                 // when curl_multi owns the call
```

The whole timing breakdown comes from `curl_getinfo()`, so the caller measures
nothing. Whether a status counts as a failure is `HttpOutcomePolicyInterface`:
`StatusHttpOutcomePolicy` treats a transport error and anything from 400 up as a
failure, and a transfer with no status at all as `unknown` rather than claiming a
success nobody observed. An API where 404 means "not found, which is fine" ships
its own policy.

### PHP errors

```php
(new ErrorHandlerBridge($logger))->register();
```

Three hooks, because PHP reports failures in three unrelated ways. Nothing is
swallowed: the error handler returns `false` so normal handling continues, the
previous handlers are chained rather than replaced, and an uncaught exception is
rethrown so the process still dies the way PHP intended.

Two details that are easy to get wrong, and are covered by tests:

- **Suppression is honoured** through `(error_reporting() & $severity) === 0`,
  which catches both the `@` operator and severities switched off in `php.ini`.
- **A fatal is reported once.** An uncaught exception leaves an `E_ERROR` behind
  when it is rethrown, and `E_USER_ERROR` reaches the error handler *and* ends
  the request; both would otherwise be filed twice.

A buffer is reserved at `register()` and released at shutdown, because an
out-of-memory fatal leaves nothing to allocate — not even the record describing
it. Set the first constructor argument to `0` to switch that off.

Stack traces for non-fatal errors are off by default; enabling them uses
`DEBUG_BACKTRACE_IGNORE_ARGS`, since the arguments are what leaks a password into
a log line.

### On PHP 8

`PDO::prepare()`, `exec()` and `query()` declare union return types that PHP 7.4
cannot express, so the overrides carry `#[\ReturnTypeWillChange]`. On 7.4 the
attribute is read as a comment; on 8.x it keeps the deprecation notice away. The
adapters are exercised on 7.4.3, 8.1 and 8.3.

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

Integration adapters for PDO, cURL and PHP's error handlers now ship in
`src/Adapter/` — see [Adapters](#adapters). They stay out of the pipeline
proper: the judgement they exist to make, whether an operation succeeded, is
injected rather than decided by the core.

Still missing:

- **Asynchronous delivery.** Writes happen on the request path; there is no
  queueing and no batching. The circuit breaker covers a destination that is
  down, not the cost of writing itself.
- **An adapter per query event.** `LoggingPdo` logs every query under one event
  name. Code that wants `user_lookup` and `invoice_fetch` told apart builds its
  own payload, or wires a second instance.
- **Static analysis and a CI pipeline.** The Docker environment runs the suite
  on the minimum supported runtime, but nothing runs it automatically.
