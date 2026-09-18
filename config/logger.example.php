<?php

declare(strict_types=1);

/**
 * Example configuration, with every key filled in.
 *
 * Values must arrive already resolved: the package never reads getenv(), $_ENV
 * or a framework helper by itself. Copy this file, wire the values from wherever
 * the host application keeps them, and pass the array to
 * LoggerFactory::fromArray().
 *
 * Every constraint below is enforced at boot. An invalid configuration throws
 * InvalidConfigurationException before the application serves any traffic,
 * rather than failing on the first record that happens to be wrong.
 */

return [
    // ---------------------------------------------------------------- identity
    // Required, non-empty. With several services writing to one collector this
    // is what keeps their dashboards apart.
    'service' => 'billing-api',

    // Required, non-empty. Free-form: 'production', 'staging', 'local'...
    'env' => 'production',

    // Optional. null falls back to gethostname(), which is usually what you
    // want; set it explicitly when the hostname is a meaningless container id.
    'host' => null,

    // Optional, default false.
    //   false -> an event built with invalid data is degraded into a meta
    //            record and the application carries on.
    //   true  -> InvalidLogEventException is propagated. Use in development and
    //            CI, where a malformed event is a bug you want to see.
    'strict_events' => false,

    // Optional, default ''. HMAC key for the session and user pseudonyms.
    //   ''    -> pseudonymiser disabled: session_ref and user_ref are simply
    //            absent. This is the safe default - the raw identifiers are
    //            never emitted.
    //   '...' -> session_ref and user_ref become stable, non-reversible
    //            references. Must come from a secret store, never from this
    //            file. Rotating it invalidates historical correlation.
    'pepper' => '',

    // ---------------------------------------------------------------- channels
    // Required: the channel used by anything the routing table does not cover.
    // Must name one of the channels below.
    'default_channel' => 'stdout',

    // Required, at least one channel. A channel is a level floor plus one or
    // more destinations.
    'channels' => [
        'stdout' => [
            // Optional, default 'debug'. Records below this level are dropped
            // before reaching any handler.
            // Options: debug | info | notice | warning | error | critical
            //          | alert | emergency
            'min_level' => 'debug',

            // Required, at least one. Four handler types exist, no others are
            // accepted.
            'handlers' => [
                [
                    // 'stream' writes to any PHP stream target.
                    'type' => 'stream',

                    // Required for 'stream'. php://stdout, php://stderr, or a
                    // file path such as /var/log/app/events.ndjson
                    'target' => 'php://stdout',

                    // Optional, default false. flock() around each write.
                    // Turn it on when several processes share one file; it is
                    // pointless on stdout and costs a syscall per record.
                    'locking' => false,
                ],
            ],
        ],

        'errors' => [
            'min_level' => 'warning',
            'handlers' => [
                ['type' => 'stream', 'target' => 'php://stdout'],

                // A second destination, so a collector outage does not lose the
                // records that matter most. One surviving handler is enough:
                // a channel only reports a failure when every handler failed.
                ['type' => 'stream', 'target' => '/var/log/app/errors.ndjson', 'locking' => true],
            ],
        ],

        'fallback' => [
            'handlers' => [
                [
                    // 'error_log' hands the line to PHP's error_log().
                    // Where it lands, and whether it gets a prefix, depends on
                    // the error_log ini setting and on the SAPI: it is not
                    // guaranteed to be stderr and not guaranteed to stay valid
                    // NDJSON. Prefer 'stream' when the collector parses JSON.
                    'type' => 'error_log',
                ],
            ],
        ],

        'off' => [
            'handlers' => [
                // 'null' discards everything. Pair it with a routing entry to
                // switch a log type off without touching any code.
                ['type' => 'null'],
            ],
        ],

        'collected' => [
            'handlers' => [
                // 'memory' keeps records and their rendered lines in memory.
                // For tests and for local inspection; it never frees them, so
                // it has no business in a long-running process.
                ['type' => 'memory'],
            ],
        ],
    ],

    // ---------------------------------------------------------------- routing
    // Optional, default []. Log type => channel name; the channel must exist.
    // Anything not listed goes to default_channel.
    'routing' => [
        'db_query' => 'stdout',
        'service_call' => 'stdout',
        'php_log' => 'errors',

        // Emitted only by the fail-safe path, and carries no caller data by
        // construction.
        'logger_error' => 'errors',

        // Any name matching [A-Za-z0-9._:-]{1,128} works here, which is how a
        // payload class of your own gets routed:
        // 'queue_job' => 'off',
    ],

    // --------------------------------------------------------------- sampling
    // Optional, default []. Share of records to keep, per log type, between 0
    // and 1. Unlisted types are always kept.
    //
    // Failures and records at error level or above are NEVER sampled away,
    // whatever the rate says: this cuts the cost of the boring records, it does
    // not hide the interesting ones.
    'sampling' => [
        'db_query' => 0.25,     // keep a quarter of the successful queries
        'service_call' => 1.0,  // keep everything (same as omitting the line)
        // 'php_log' => 0.0,    // drop all of them except failures and errors
    ],

    // ---------------------------------------------------------------- capture
    'capture' => [
        // Optional, default false.
        //   false -> only the normalized fingerprint is emitted.
        //   true  -> data.statement_raw carries the statement as written. It is
        //            the one setting that can put query values in a log; the
        //            fingerprint exists precisely so you do not need it.
        'raw_statement' => false,

        // Optional, default []. THE structural guarantee of this package.
        //
        // Every array-valued field under "data" is dropped unless its keys are
        // listed here. Deny by default, no content inspection involved. The
        // number of dropped entries is reported as "_omitted", so the omission
        // is visible rather than silent.
        //
        // Shape: log type => field name => list of allowed keys.
        'fields' => [
            'db_query' => [
                // Bound query parameters. An empty list drops all of them and
                // reports the count.
                'params' => ['tenant_id', 'status'],
            ],

            'service_call' => [
                // Request or response body, when the caller attaches one.
                'payload' => ['order_id', 'status'],

                // Timing breakdown; these four are the keys the adapter fills.
                'timing' => ['dns', 'connect', 'ttfb', 'total'],
            ],

            'php_log' => [
                // Free-form context passed by the application.
                'context' => ['route', 'job_id', 'section'],
            ],

            // A payload class of your own declares its own array fields here,
            // otherwise they are dropped - which is the intended default:
            // 'queue_job' => ['attempts' => ['count', 'last_error_code']],
        ],
    ],

    // ----------------------------------------------------------------- limits
    // All four are positive integers.
    'limits' => [
        // Default 4096, chosen because a write to a POSIX pipe is atomic only
        // up to PIPE_BUF: above that, concurrent workers writing to the same
        // stdout can interleave and produce unparseable lines. Raise it only
        // when the destination is a file.
        'max_record_bytes' => 4096,

        // Default 1024. Per-field cap for strings. Must NOT exceed
        // max_record_bytes, or the configuration is refused.
        'max_field_bytes' => 1024,

        // Default 20. Frames kept from a stack trace.
        'max_stack_frames' => 20,

        // Default 4. How deep nested arrays are walked before the rest is
        // replaced by an "_omitted" count.
        'max_depth' => 4,
    ],

    // -------------------------------------------------------------- fail safe
    // Where a meta record goes when the pipeline itself fails. Deliberately
    // primitive: it shares no code with the pipeline that just broke, and the
    // record it writes carries no caller-supplied data at all.
    'fail_safe' => [
        // Default 'php://stderr'. Non-empty string; a separate destination is
        // a good idea, since the usual one may be exactly what failed.
        'target' => 'php://stderr',

        // Default 3, minimum 0. Rate limit per request, so a broken pipeline
        // cannot flood the destination. 0 disables meta records entirely.
        'max_records' => 3,
    ],

    // --------------------------------------------------------- circuit breaker
    // Stops hammering a destination that keeps failing. The trip is reported
    // once, so the failure is visible; afterwards records are dropped silently
    // until the cooldown expires, then one record probes the destination again.
    'circuit_breaker' => [
        // Default 5, minimum 1. Consecutive failures before the breaker opens.
        // A success resets the count.
        'failure_threshold' => 5,

        // Default 30, minimum 1. Seconds to wait before probing again.
        'cooldown_seconds' => 30,
    ],
];
