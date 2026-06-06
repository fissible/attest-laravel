<?php

return [
    /**
     * Which Laravel database connection holds the attest tables.
     * null falls back to config('database.default'). Operators commonly
     * want a dedicated connection (separate schema or even separate
     * server) for the evidence chain.
     */
    'connection' => env('ATTEST_CONNECTION'),

    /** Total wall-clock seconds the locker will wait before throwing
     *  ChainLockUnavailable. */
    'lock_timeout_seconds' => 10,

    /** Postgres locker polling interval (microseconds). */
    'postgres_lock_poll_us' => 50_000,

    /** Ed25519 signing material. Both env vars are required when the
     *  Attest facade is used; the registry throws a clear error on
     *  first use if either is missing. */
    'signing_key' => [
        'seed_env'   => 'ATTEST_SIGNING_KEY_SEED',
        'key_id_env' => 'ATTEST_SIGNING_KEY_ID',
    ],

    /** AnchorClaim TTL — reclaimable after this many seconds of being
     *  incomplete. */
    'claim_ttl_seconds' => 3600,
];
