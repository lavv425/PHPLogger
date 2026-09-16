# Log schema — version 1

The JSON emitted by this package is a public contract: dashboards, alerts and
queries are built on these field names. Treat it like an API.

Format: newline delimited JSON (one record per line), UTF-8, written with
`JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE |
JSON_PRESERVE_ZERO_FRACTION`.

## Structure

Every record is an **envelope** plus a type-specific **`data`** object. The
envelope owns every reserved name, so a new log type can use any field name it
wants inside `data` without ever colliding with it.

```json
{
  "schema_version": 1,
  "timestamp": "2026-01-15T10:00:00.123456+00:00",
  "level": "info",
  "service": "billing-api",
  "env": "production",
  "host": "pod-7f9c",
  "log_type": "db_query",
  "event": "user_lookup",
  "outcome": "success",
  "success": true,
  "duration": 0.045,
  "error": null,
  "request_id": "req_abc123",
  "session_ref": "a1b2c3d4e5f60718",
  "user_ref": null,
  "user_agent": "Mozilla/5.0",
  "data": { }
}
```

### Envelope

| Field | Type | Notes |
| --- | --- | --- |
| `schema_version` | int | Major version of this contract |
| `timestamp` | string | ISO 8601 UTC with microseconds |
| `level` | string | `debug` `info` `notice` `warning` `error` `critical` `alert` `emergency` |
| `service` | string | Emitter. Required, no default |
| `env` | string | Deployment environment |
| `host` | string\|null | `gethostname()` unless configured |
| `log_type` | string | Discriminator; selects the shape of `data` |
| `event` | string | Event name, charset `[A-Za-z0-9._:-]{1,128}` |
| `outcome` | string | `success` \| `failure` \| `unknown` |
| `success` | bool\|null | Derived from `outcome`; `null` when unknown |
| `duration` | float\|null | Seconds |
| `error` | object\|null | See below |
| `request_id` | string\|null | Groups the records of one endpoint call |
| `session_ref` | string\|null | **Pseudonym** of the session id, not the id |
| `user_ref` | string\|null | **Pseudonym** of the user id, not the id |
| `user_agent` | string\|null | Truncated to 256 characters |
| `_truncated` | bool | Present only when the record was shrunk to fit |

`outcome` answers "did the operation succeed", `level` answers "how much should
you care". A deprecation notice is `level: notice`, `outcome: unknown`: it says
nothing about the success of the surrounding work. Count failures with
`outcome = "failure"`, never with `success = false`, which is also false for
nothing at all when the outcome is unknown.

### `error`

Present on any log type. `code` and `severity` are different concepts and never
share a field.

| Field | Type | Notes |
| --- | --- | --- |
| `type` | string | `exception` `php_error` `timeout` `curl_error` `http_error` `db_error` `other` |
| `code` | string\|null | Exception code, cURL errno, HTTP status, SQLSTATE |
| `message` | string | Scrubbed and length-capped |
| `class` | string\|null | Exception class |
| `severity` | string\|null | PHP `E_*` constant name, PHP errors only |
| `severity_code` | int\|null | Its numeric value, PHP errors only |
| `stack_trace` | string[]\|null | Frames without call arguments |

Stack frames are rendered from file, line, class and function only.
`getTraceAsString()` is never used: it inlines call arguments, which is one of
the most common ways a password reaches a log.

## `data` per log type

### `db_query`

| Field | Type | Notes |
| --- | --- | --- |
| `database` | string | |
| `operation` | string | `SELECT` `INSERT` `UPDATE` `DELETE` `REPLACE` `CALL` `SHOW` `TRUNCATE` `OTHER` |
| `statement` | string\|null | **Normalized fingerprint**, all literals replaced by `?` |
| `statement_hash` | string | 16 hex chars of the fingerprint; use it to aggregate |
| `statement_raw` | string\|null | Only when `capture.raw_statement` is enabled |
| `params` | object\|null | Allow-listed keys only; `_omitted` counts what was dropped |
| `row_count` | int\|null | |

The statement is always emitted normalized, even when the caller passed a
correctly parameterized query. A statement built by interpolation therefore
cannot leak its values, and `statement_hash` gives dashboards a stable key.

Trade-off: double-quoted sequences are normalized too, so PostgreSQL quoted
identifiers appear as `?` in the fingerprint.

### `service_call`

| Field | Type | Notes |
| --- | --- | --- |
| `method` | string | `GET` `POST` `PUT` `PATCH` `DELETE` `HEAD` `OPTIONS` |
| `url` | string | Scrubbed |
| `query_string` | string\|null | Derived from the URL |
| `http_code` | int\|null | |
| `payload` | object\|string\|null | Allow-listed keys only |
| `timing` | object\|null | `dns` `connect` `ttfb` `total`, seconds; needs an allow-list entry |

A non-2xx response is not automatically a failure: whether it is belongs to the
caller, which sets `outcome` explicitly.

### `php_log`

| Field | Type | Notes |
| --- | --- | --- |
| `message` | string\|null | Always present, mirrors `error.message` when there is an error |
| `file` | string\|null | |
| `line` | int\|null | |
| `class` | string\|null | |
| `function` | string\|null | |
| `memory_usage` | int\|null | Bytes |
| `context` | object\|null | Allow-listed keys only |

### `logger_error`

Emitted by the fail-safe path when the pipeline itself fails. Carries
`reason` and `dropped_log_type` and nothing else: no event name, no data, no
correlation. A record that could not be sanitized is never written in any form.

## Compatibility policy

`schema_version` identifies the contract; it does not protect it. The policy
does, and the golden files in `tests/golden/` enforce it.

Within a major version:

* fields may be **added**;
* a field may never be **renamed**, **removed**, or change **type**;
* a deprecated field keeps being emitted until the next major;
* key order is stable (the golden tests compare raw JSON).

A breaking change requires bumping `schema_version` and one of:

* **dual emit** — both versions are written for a transition window while the
  dashboards migrate; or
* **a new `log_type`** — the old one keeps working untouched.

Any change to the output makes `tests/run.php` fail on the golden files. That
failure is the decision point: update the golden file only together with the
compatibility decision above.

## Querying notes

* **Loki**: `| json` flattens nested keys with an underscore, so `data.timing.ttfb`
  is queried as `data_timing_ttfb`.
* **Elasticsearch**: `data.params`, `data.payload` and `data.context` have
  caller-defined keys. Map them as `flattened` or exclude them from the index,
  otherwise the mapping explodes.
* Records are capped at 4096 bytes by default. A write to a POSIX pipe is atomic
  only up to `PIPE_BUF`; above it, concurrent PHP-FPM workers writing to the same
  stdout can interleave and produce unparseable lines.
