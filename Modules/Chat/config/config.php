<?php

declare(strict_types=1);

/*
 | Task 8: live support chat. Values are configuration, not measurements. Rate limits live in
 | config/rate-limits.php (chat-*), the per-user connection cap in rate-limits.concurrency.chat-connections.
 */
return [
    'name' => 'Chat',

    // redis (real `state` connection, keys prefixed `chat:`) | memory (Feature tests only, like RATE_LIMIT_SLIDING_DRIVER)
    'state_driver' => env('CHAT_STATE_DRIVER', 'redis'),
    'state_connection' => 'state',

    // Counted in characters (mb_strlen after trim), not bytes. See docs/chat-protocol.md "Size arithmetic".
    'body_max_chars' => (int) env('CHAT_BODY_MAX_CHARS', 2000),

    'messages' => [
        'default_limit' => (int) env('CHAT_MESSAGES_DEFAULT_LIMIT', 50),
        'max_limit' => 200, // hard maximum: a larger `limit` is rejected with 422, never clamped
    ],

    'per_page' => (int) env('CHAT_PER_PAGE', 20),
    'per_page_max' => (int) env('CHAT_PER_PAGE_MAX', 50),

    'presence_ttl_seconds' => (int) env('CHAT_PRESENCE_TTL_SECONDS', 60),
    'presence_list_max' => (int) env('CHAT_PRESENCE_LIST_MAX', 500),

    'typing_ttl_seconds' => 5,
    'typing_broadcast_every_seconds' => 3,

    // Redis unread counters are a cache of conversation_reads (the truth); backfilled values expire.
    'unread_ttl_seconds' => (int) env('CHAT_UNREAD_TTL_SECONDS', 300),

    // 0 = no cap. Open (claimed) conversations one support agent may hold at once.
    'agent_max_open' => (int) env('CHAT_AGENT_MAX_OPEN', 0),

    // How many stale queue entries one claim may skip before answering 204.
    'claim_attempts' => (int) env('CHAT_CLAIM_ATTEMPTS', 5),

    'sweep_chunk' => (int) env('CHAT_SWEEP_CHUNK', 500),
];
