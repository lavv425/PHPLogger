<?php

declare(strict_types=1);

/**
 * Example configuration.
 *
 * Values must arrive already resolved: the package never reads the environment
 * by itself. Copy this file, wire the values from wherever the host application
 * keeps them, and pass the array to LoggerFactory::fromArray().
 */

return [
    // Identifies the emitter. With several services writing to the same
    // collector this is what keeps their dashboards apart.
    'service' => 'billing-api',
    'env' => 'production',
    'host' => null, // null falls back to gethostname()

    // Propagate InvalidLogEventException instead of degrading it into a meta
    // record. Enable in development and CI, leave off in production.
    'strict_events' => false,

    // HMAC key for session/user pseudonyms. Must come from a secret store, never
    // from the repository. Empty disables session_ref and user_ref entirely,
    // which is the safe default: the raw identifiers are never emitted.
    'pepper' => '',

    'default_channel' => 'stdout',

    'channels' => [
        'stdout' => [
            'min_level' => 'debug',
            'handlers' => [
                ['type' => 'stream', 'target' => 'php://stdout', 'locking' => false],
            ],
        ],

        // Errors go to stdout and to a file, so a collector outage does not
        // lose them.
        'errors' => [
            'min_level' => 'warning',
            'handlers' => [
                ['type' => 'stream', 'target' => 'php://stdout'],
                ['type' => 'stream', 'target' => '/var/log/app/errors.ndjson', 'locking' => true],
            ],
        ],

        'off' => [
            'handlers' => [['type' => 'null']],
        ],
    ],

    // Log type => channel. Anything not listed goes to default_channel.
    'routing' => [
        'db_query' => 'stdout',
        'service_call' => 'stdout',
        'php_log' => 'errors',
    ],

    // Share of records to keep, per log type. Failures and records at error
    // level or above are always kept regardless of this setting.
    'sampling' => [
        'db_query' => 1.0,
    ],

    'capture' => [
        // Emit the statement as written instead of its normalized fingerprint.
        // Keep false: it is the only thing standing between an interpolated
        // query and its values ending up in the log.
        'raw_statement' => false,

        // Deny by default: an array-valued field under "data" is dropped unless
        // its keys are listed here. Everything else is only best-effort scrubbed.
        'fields' => [
            'db_query' => [
                'params' => [], // e.g. ['tenant_id', 'status']
            ],
            'service_call' => [
                'payload' => [],
                'timing' => ['dns', 'connect', 'ttfb', 'total'],
            ],
            'php_log' => [
                'context' => [], // e.g. ['route', 'job_id']
            ],
        ],
    ],

    'limits' => [
        // A write to a POSIX pipe is atomic only up to PIPE_BUF (4096 bytes).
        // Larger records can interleave between FPM workers on the same stdout.
        'max_record_bytes' => 4096,
        'max_field_bytes' => 1024,
        'max_stack_frames' => 20,
        'max_depth' => 4,
    ],

    'fail_safe' => [
        'target' => 'php://stderr',
        'max_records' => 3,
    ],

    'circuit_breaker' => [
        'failure_threshold' => 5,
        'cooldown_seconds' => 30,
    ],
];
