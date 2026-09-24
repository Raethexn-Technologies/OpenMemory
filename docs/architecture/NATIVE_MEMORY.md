# Native memory and portability

This document describes the Phase 3 implementation dated 2026-09-23. The proposed architecture remains broader than this implementation.

The subsequent [Phase 4 resolver](LOCAL_CONTEXT.md) can disclose selected active native excerpts under explicit application grants. Native CRUD and export/import remain owner-only, with unchanged storage and lifecycle semantics.

## What is a native memory?

A native memory is a statement the authenticated owner intentionally saves in the local SQL database. It is private by construction and independent of graph pruning, cache retention, ICP availability, model configuration, and commercial services.

Imported conversations are sources of potential memory, not automatically canonical memories themselves. OpenMemory stores native memory intentionally. External/federated context may remain at its source.

Documents, extracted entities, graph anchors, software inferences, and retrieved snippets do not become native memories merely because OpenMemory encounters them. This phase adds no automatic conversion or inference.

## Existing memory audit

These systems remain separate and retain their existing behavior.

| Existing system | Purpose and ownership | Lifecycle, privacy, and control | Canonical assessment |
|---|---|---|---|
| SQL MemoryNode and MemoryEdge records | Corpus-owner strings and agent partitions identify graph retrieval material. Nodes mix saved text, documents, entities, and generated metadata. | Classified nodes participate in reinforcement, decay, consolidation, and destructive pruning. Individual native correction semantics do not exist here. | These records cannot supply durable native authority. |
| GraphSnapshot records | Owner-scoped snapshots preserve historical graph representations. | Snapshots can retain content after live graph changes. No native records enter snapshots. | Snapshot retention remains independent of native memory. |
| ICP canister records | Provider principals own externally stored records with principal-and-counter identifiers. | Public, private, and sensitive classifications govern access. The owner can delete records, but correction and supersession APIs are absent. | Canister state remains authoritative only for its legacy integration. |
| IcpMemoryService mock records | Owner-keyed cache entries support the demonstration flow. | Entries expire after two hours. Global inspection returns only explicitly public records. | This cache cannot provide durable native memory. |
| Chat transcripts and memory proposals | Session-associated messages and reviewed proposals support existing chat behavior. | SQL transcripts, graph projections, and approved canister/cache writes have separate retention. | Existing chat approval does not write the new native table. |
| Imported conversations and raw records | Explicit corpus bindings govern private source archives. | Redacted retrieval remains separate from owner-only raw access and conversation deletion. | These records remain authoritative source artifacts, not native assertions. |
| Document chunks and EvidenceFact records | Graph documents and source-linked facts support retrieval. | Privacy follows source classification; separately authorized extraction can involve models. | Source material and extracted claims are not owner assertions. |
| Agent graphs and memory client | Agent partitions belong to an owner; the optional client uses legacy endpoints. | Agent deletion removes its graph partition. Existing disclosure grants govern external processing. | Agents receive no native-memory authority in this phase. |
| MCP memory operations | Shared application authority reaches existing public graph/canister operations. | Imported history remains excluded. Broad shared-key authority remains a documented limitation. | MCP neither reads nor writes native memory. |
| CLI-discovered memory files | Local project/provider files supply source candidates and manifest inputs. | Existing local inspection and import rules remain unchanged. | Discovery alone does not establish an intentional native save. |

The former Memory navigation label now reads Legacy memory. The separate Native memory page identifies its persistence as local SQL, even when legacy integration uses a mock or live canister.

## Schema and ownership

The additive native_memories table contains the following fields.

| Field | Purpose and inclusion rationale |
|---|---|
| id | This internal numeric key supports ordinary Eloquent persistence and is never exported. |
| owner_id | This foreign key identifies the authenticated Laravel user, not a corpus namespace or provider principal. |
| memory_id | This lowercase UUID preserves logical identity across compatible installations. |
| content | This field holds the intentional statement, limited to 8,000 UTF-8 bytes. |
| attribution | This field currently permits only user_asserted origin claims. |
| state | This field distinguishes active, archived, and superseded statements. |
| superseded_by | This nullable portable UUID records an explicit replacement without introducing a general graph. |
| revision | This integer supports conflict detection for owner mutations. |
| created_at and updated_at | These timestamps distinguish original creation from subsequent corrections and lifecycle changes. |

Uniqueness applies to owner_id and memory_id together. Two owners can import the same logical record without sharing authorization or revealing whether another account already holds that UUID. Internal database and account identifiers never enter native exports.

Arbitrary metadata, provider accounts, confidence scores, tags, application identities, and evidence graphs are deliberately absent. Future reviewed source references can be added without changing the meaning of content or ownership.

NativeMemoryService accepts a verified User and scopes every query by that user's database key. Controllers obtain that object from Laravel's web guard. Internal callers remain trusted application code; this service is not a plugin sandbox.

## Attribution and provenance

New owner saves use user_asserted attribution. This establishes intentional submission by the authenticated owner, not the truth of the statement.

Import preserves exported attribution instead of confusing transport with original authorship. Imported attribution is an unverified origin claim, not a signature or proof that the receiving owner originally made the assertion.

Application-saved assertions, source-converted assertions, derived observations, and system state are not supported attribution values. Creation rejects client-supplied attribution. Future memory.read, memory.write, and memory.delete application authority requires separate grants; no application-write path is introduced here.

## Lifecycle

| Operation | Implemented semantics |
|---|---|
| Creation | A new UUID starts active at revision one. |
| Correction | PATCH replaces content on an active or archived record while preserving its identity and creation time. Earlier content is intentionally not retained. |
| Supersession | One transaction creates a new active record and marks the previous record superseded with a replacement UUID. Earlier content remains inspectable. |
| Archival | PATCH changes an active record to archived, excluding it from default retrieval while preserving it in exports. |
| Restoration | PATCH changes an archived record back to active. |
| Deletion | DELETE physically removes the row and clears incoming replacement pointers belonging to that owner. Predecessors remain superseded. |

Superseded records cannot be corrected, reactivated, or superseded again. They remain inspectable, exportable, and deletable. Deletion does not recursively erase other statements or remove data from backups, database journals, browser memory, or downloaded files.

Mutations require the inspected integer revision; stale requests return HTTP 409. Owner-row locking serializes mutations on databases supporting row locks. SQLite serializes writes, and transient transaction failures are retried.

Revisions stop at 2,147,483,647 rather than overflowing. At that bound, a record remains readable, exportable, and deletable, but cannot be corrected or superseded.

## API

Routes use Laravel session authentication and the accepted owner middleware. JSON writes and searches require the normal CSRF header. MCP keys, configured import owners, and browser principal strings do not authenticate these requests.

| Method and path | Request and response |
|---|---|
| GET /native-memory | This route opens the authenticated control page. |
| POST /api/native-memories | The content field creates a record returned under data. |
| GET /api/native-memories | This route lists active records using state, after, and limit filters. |
| POST /api/native-memories/search | JSON q and list filters request literal substring search. |
| GET /api/native-memories/{id} | This route returns one owner-scoped record in any lifecycle state. |
| PATCH /api/native-memories/{id} | Revision and content and/or state request correction or active/archived transitions. |
| POST /api/native-memories/{id}/supersede | Revision and content create a replacement and return its record. |
| DELETE /api/native-memories/{id} | Revision authorizes a current-version deletion returning HTTP 204. |
| GET /api/native-memories/export | This route returns one export page with an attachment filename. |
| POST /api/native-memories/import | An export envelope returns imported and skipped counts. |

List and export limits default to 100 and range from one to 1,000. Export can return fewer records to keep encoded records within eight MiB, leaving envelope space below the ten-MiB import limit. Records sort by UUID with an exclusive after cursor and nullable next_cursor. List/search state accepts active, archived, superseded, or all.

Search escapes SQL wildcards and follows the database's text collation; cross-database Unicode case folding is not promised. Search text travels in POST bodies instead of access-log URLs. The UI lists 50 records and downloads 100 records per export page.

Same-origin authenticated owner clients can use these payloads with their CSRF header:

~~~text
POST /api/native-memories
{"content":"My preferred editor is VS Code."}

PATCH /api/native-memories/{id}
{"revision":1,"content":"My preferred editor is Zed."}

POST /api/native-memories/search
{"q":"preferred editor","state":"active","limit":20}
~~~

This session API does not constitute a third-party application grant system.

## Export format

The [JSON Schema](../schemas/native-memory-v1.schema.json) defines openmemory-export-v1. Its envelope separates owned native_memories from external_references, which must be an empty array in this version.

The following example contains only fabricated content.

~~~json
{
  "format": "openmemory-export-v1",
  "exported_at": "2026-09-23T12:00:00Z",
  "native_memories": [
    {
      "id": "12345678-1234-4234-9234-123456789abc",
      "content": "My preferred editor is VS Code.",
      "attribution": "user_asserted",
      "state": "active",
      "superseded_by": null,
      "revision": 1,
      "created_at": "2026-09-23T11:00:00Z",
      "updated_at": "2026-09-23T11:00:00Z"
    }
  ],
  "external_references": [],
  "next_cursor": null
}
~~~

Record timestamps use UTC with second precision. Import requires valid dates from 1970 onward, creation no later than update, and neither record timestamp in the future. Receiving-server clock skew can therefore require correction before import.

Exports include active, archived, and superseded records. They exclude deleted records, account tables, provider configuration, credentials, source archives, graph data, and internal database keys. Text content is rechecked against protected categories before export.

Download the first page, then request after equal to next_cursor until the cursor becomes null. Import every page on the destination. Pagination is not a consistent snapshot across requests; pause writes during transfer to avoid skipping newly inserted UUIDs or mixing revisions.

Unknown fields and unsupported versions are rejected rather than silently discarded. Future extensions require explicitly implemented compatibility rules.

## Import semantics

Each request accepts at most 10 MiB of JSON, 1,000 records, and bounded JSON depth. These are request limits, not account-capacity limits; additional pages can be transferred indefinitely. Deployment request-size limits must also permit the intended page size.

Validation completes before insertion, and each page commits atomically.

| Condition | Deterministic outcome |
|---|---|
| The authenticated owner does not have the UUID. | Import inserts its portable fields without transferring another account's ownership. |
| The UUID exists with exactly equal portable values. | Import skips the record without changing timestamps or revision. JSON field order is irrelevant. |
| The UUID exists with different portable values. | HTTP 409 aborts the whole page without overwriting records. |
| One page repeats a UUID. | HTTP 422 rejects the page, including identical duplicates. |
| Input names an owner or supplies unknown fields. | HTTP 422 rejects unsupported information instead of accepting authority claims. |
| A replacement UUID is not present locally yet. | Import retains an unresolved pointer without granting access or querying another owner. |
| Input introduces self-links or replacement cycles. | HTTP 422 rejects the page, including cycles completed by later pages. |
| External references or unsupported attribution appear. | HTTP 422 rejects the page without fetching or converting anything. |

Unresolved replacements can become available after other pages arrive. A null replacement remains valid for a superseded record after deletion. Import never follows URLs, invokes providers or models, or inserts graph records.

There is no force-overwrite switch or automatic merge. An intentional import can restore an earlier deleted UUID because deletion leaves no content tombstone or synchronization history.

## Privacy and disclosure

Native content remains untrusted data. The UI interpolates text rather than raw HTML, and native operations never invoke models or construct trusted prompts. Provenance establishes origin, not truth or instruction authority.

RedactionService runs locally before storage and export, enforcing its configured floor even when general redaction is disabled. Matching content is rejected instead of silently rewritten, preserving exact round trips for accepted records. Supplemental checks reject common labeled credentials, bearer tokens, and credential-bearing URLs.

These checks cannot recognize arbitrary unlabeled secrets. Native memory is not a password vault; users must not save credentials. Non-floor personal content can remain private native state, so exports must be treated as sensitive files.

Only authenticated owners receive native records and exports. Retrieval does not authorize disclosure to models, MCP clients, connectors, or other applications. No public-native flag or automatic disclosure path is added.

Normal operations do not log payloads. Validation errors are generic, and native payload fields are excluded from session flash data. Successful responses use private no-store headers. Operators must separately disable body capture, SQL-binding diagnostics, and proxy telemetry that retains sensitive data.

Exports are plain JSON, not encrypted archives. SQL encryption, backups, filesystem permissions, and transport security remain deployment responsibilities. Export filenames are ignored by git, but manually renamed files still require care.

## Self-hosting and migration

Use the existing local setup, run php artisan migrate from app, create a local account with openmemory:user:create, and sign in at /login. The /native-memory page requires neither a model key nor ICP. A Laravel application key, persistent SQL storage, and session storage are still required.

Migration 2026_09_23_000001_create_native_memories_table adds one table and indexes. It performs no backfill, conversion, or provider call. Rolling it back drops native records, so export real data before any rollback.

Existing import commands, graph maintenance, canister records, and MCP tools retain their behavior. A future provider wrapper can call NativeMemoryService listing and get without replacing this storage layer or bypassing disclosure authorization.

## Current limitations and open questions

This phase provides no application-write grants, native-memory CLI, content revision journal, signed provenance, external evidence imports, arbitrary metadata, or automated memory promotion. Existing chat approval continues using its legacy stores.

Transfers require a quiet write period rather than supplying consistent snapshots or synchronization. Duplicate JSON object keys follow PHP's last-member decoding behavior; duplicate record IDs are independently rejected. Automated tests use SQLite, leaving other databases' locking and collation behavior unverified.

Future work must decide how reviewed source references are attached, how separate application authorities are granted, and how projections invalidate after deletion. These decisions do not justify implementing federation or a Context Resolver in this phase.
