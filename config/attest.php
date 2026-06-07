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

    /** Defaults used by Artisan anchoring commands and queued batches. */
    'anchoring' => [
        'default_driver' => env('ATTEST_DEFAULT_DRIVER', 'local-only'),
        'default_chain' => env('ATTEST_DEFAULT_CHAIN'),
        'calendars' => array_filter(array_map('trim', explode(',', env('ATTEST_OTS_CALENDARS', '')))),
        'min_calendars' => (int) env('ATTEST_OTS_MIN_CALENDARS', 1),
        'queue' => env('ATTEST_ANCHOR_QUEUE'),
        'connection' => env('ATTEST_ANCHOR_QUEUE_CONNECTION'),
    ],

    /** Verification policy defaults for commands and jobs.
     *  trusted_keys entries use <key_id>=<base64-pubkey>. */
    'verification' => [
        'min_anchor_outcome' => env('ATTEST_MIN_ANCHOR'),
        'require_trusted_key' => env('ATTEST_REQUIRE_TRUSTED_KEY', true),
        'trusted_keys' => [],
        'trusted_key_files' => [],
        'allow_provider_disagreement' => false,
    ],

    /** Optional Bitcoin header providers used by verification commands. */
    'headers' => [
        'bitcoin_core_rpc' => env('ATTEST_BITCOIN_CORE_RPC'),
        'bitcoin_core_cookie' => env('ATTEST_BITCOIN_CORE_COOKIE'),
        'esplora_url' => env('ATTEST_ESPLORA_URL'),
    ],
];
