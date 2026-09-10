<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Imported conversation history
    |--------------------------------------------------------------------------
    |
    | These settings govern how provider archives (ChatGPT, Claude, Gemini) are
    | read, normalized, and stored. Every value here is a local-processing
    | control. No setting in this file causes archive content to leave the
    | machine. Model calls happen only on the Ask path, which sends selected
    | redacted excerpts and never a whole archive.
    |
    */

    // Stable owner identity for imported history.
    //
    // The chat UI derives a user identity from Internet Identity or from a
    // per-session fallback, and a terminal command has neither. Imported history
    // needs one identity that both the CLI and the browser agree on, or an
    // archive imported from a shell would be invisible in the app that is meant
    // to review it. Set this to any stable string, or to your Internet Identity
    // principal if you want imported history to sit under the same owner as your
    // canister-signed memories.
    'local_user_id' => env('OPENMEMORY_LOCAL_USER_ID', ''),

    // Store the exact provider JSON for each imported conversation alongside the
    // normalized rows. Raw records are the authoritative evidence: a lossy
    // summary must never become the only surviving copy of what the user said.
    // Raw payloads are owner-only and are excluded from every retrieval, prompt,
    // and MCP path by construction. Set to false to import normalized rows only.
    'store_raw' => env('CONVERSATIONS_STORE_RAW', true),

    // Imported history is private by default. Private conversations are visible
    // to the owner in the app and usable by the first-party Ask path. They are
    // never eligible for the public memory graph, LLM recall in chat, or MCP
    // clients. Raising this default would hand a user's whole history to every
    // connected agent, which is the opposite of the intent.
    'default_visibility' => 'private',

    /*
    | Archive safety limits. An archive is untrusted input: it may be malformed,
    | maliciously crafted, or simply enormous. These caps bound memory, disk, and
    | time before any parsing happens.
    */
    'limits' => [
        // Reject a ZIP whose declared uncompressed size exceeds this. 8 GiB is
        // far beyond a real personal export and well under a decompression bomb.
        'max_total_uncompressed_bytes' => (int) env('CONVERSATIONS_MAX_TOTAL_BYTES', 8 * 1024 * 1024 * 1024),

        // Reject a single entry larger than this once decompressed.
        'max_entry_uncompressed_bytes' => (int) env('CONVERSATIONS_MAX_ENTRY_BYTES', 4 * 1024 * 1024 * 1024),

        // Reject an archive with more entries than this.
        'max_entries' => (int) env('CONVERSATIONS_MAX_ENTRIES', 200000),

        // Reject an entry whose uncompressed:compressed ratio exceeds this.
        // Ordinary JSON compresses around 10:1; 400:1 only happens on purpose.
        'max_compression_ratio' => (float) env('CONVERSATIONS_MAX_COMPRESSION_RATIO', 400.0),

        // Largest single JSON value the streaming reader will buffer. This bounds
        // peak memory for one conversation regardless of total archive size.
        'max_json_element_bytes' => (int) env('CONVERSATIONS_MAX_JSON_ELEMENT_BYTES', 64 * 1024 * 1024),

        // Longest message text retained after normalization. Longer bodies are
        // truncated in the normalized row with an explicit marker; the raw record
        // still holds the full text.
        'max_message_chars' => (int) env('CONVERSATIONS_MAX_MESSAGE_CHARS', 200000),
    ],

    /*
    | Ask path. Retrieval over imported history is deterministic and lexical.
    | Only the selected excerpts reach a model, never whole conversations.
    */
    'ask' => [
        // Maximum message excerpts selected as evidence for one question.
        'evidence_limit' => (int) env('CONVERSATIONS_ASK_EVIDENCE_LIMIT', 12),

        // Maximum characters of each excerpt sent to the model.
        'excerpt_chars' => (int) env('CONVERSATIONS_ASK_EXCERPT_CHARS', 600),

        // When false, the Ask endpoint returns retrieved evidence without
        // calling any model. Useful when no API key is configured, and the
        // honest default for anyone who does not want their history summarized
        // by a third-party model at all.
        'generate_answer' => env('CONVERSATIONS_ASK_GENERATE_ANSWER', true),
    ],

];
