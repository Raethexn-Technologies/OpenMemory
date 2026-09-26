# End-to-end validation of one local consuming application

Date: 2026-09-25. This report follows the accepted MODIFY conclusion in the [post-Phase-5 review](POST_PHASE_5_REVIEW.md). It records implementation and synthetic verification separately from live validation. The milestone's live success criterion has **not yet been met**.

## Status and authorized environment

The reference application, explicit model permission, SQL improvements, audit intent records, and race regressions are implemented. The owner selected OpenAI GPT-5.4 with medium reasoning, no web/search, no tools, and no model conversation memory. GitHub scope is initially limited to `Raethexn-Technologies/OpenMemory`. The initial request assumed an existing bound owner/corpus; the owner subsequently confirmed this checkout as the installation and authorized real owner/corpus setup here after inspection found neither.

Before setup, this checkout's SQLite database had zero users, conversations, messages, and import records, and no corpus-owner binding table. No unspecified installation or unrelated archive was searched. The five pending September 22 through September 25 migrations are now applied, bringing the migration count to 23. The existing application key was preserved, debug output disabled, and the local URL configured as `http://127.0.0.1:8000`.

The loopback Core server started successfully: `/up` and `/login` returned 200, while unauthenticated application permissions and Native Memory APIs returned 401. There are still zero users and bindings. Real account creation, the owner's explicitly supplied archive path, actual Native Memory assertions, and local provider-secret configuration remain required. No sample identity, corpus, private fixture, or model/GitHub request was fabricated.

The owner will enter the real login email and password locally and has explicitly paused history import until supplying an archive. No archive path is currently authorized. The client setup guide now documents the exact supported ChatGPT, Claude, and Gemini JSON formats and their ZIP/directory/single-file containers. Setup must not infer an email or search the filesystem for private exports.

| Validation layer | Result |
|---|---|
| Backend before this milestone | 613 tests passed with 3,285 assertions. |
| Backend after implementation | 626 tests passed with 3,277 assertions in 20.73 seconds. |
| Owner frontend | 42 tests passed; the production build passed with the existing large-chunk warning. |
| Reference client | Twelve isolated HTTP, disclosure, prompt-placement, and local-server tests passed with fabricated data. |
| Retained CLI/security boundaries | Five CLI tests and six disclosure-security tests passed. |
| Live GitHub | No connection or retrieval has been run. |
| Actual corpus questions | Zero of the ten questions have been run. |
| Actual model and human usefulness | No model request or human evaluation has been run. |

Assertion counts are not security scores. Several existing checks execute assertions in redaction callbacks; removing repeated work changes that count. No existing test was removed. New tests exercise model grants, permission preflight, owner isolation, revocation during normalization/permission inspection, deletion during resolution, outbound audit ordering/failure, mid-retrieval deselection, and the synthetic query budget. Existing federation and disclosure tests remain in the full suite.

## Architecture and setup

The implementation is in [examples/context-client](../../examples/context-client/README.md). It is a dependency-free Node 22 application serving a loopback-only page. Its server holds application/model credentials; the browser receives neither. It imports no Laravel service, reads no Core database, and never receives owner-session authority or the GitHub credential. Its local browser session does not confer authority to Core.

The owner configures Core in the existing UI. The independent client calls `GET /api/app/context/permissions` and `POST /api/app/context/resolve` with the Phase 4 bearer token. It validates `context-bundle-v1`, shows an evidence projection, and preserves source outcomes and provenance. A separate confirmed action rechecks permissions and calls one model destination directly. Core remains usable without this client or a model credential.

The setup guide contains commands, grant combinations, environment keys, loopback restrictions, and teardown. Initial real-owner creation is authorized for this empty installation and uses the existing interactive CLI with hidden password entry. Discover its generated owner ID and binding afterward instead of guessing them. Import only a specifically authorized archive path, locally, without copying archive contents into fixtures or reports. Multiple accounts require owner identification rather than selecting the first row.

The only new milestone migration is `2026_09_25_000001_add_context_model_permission_and_audit_correlation.php`. It adds nullable JSON `context_applications.model_disclosure` and nullable indexed UUID `context_access_events.context_request_id`. Rollback removes both fields and the index. The September 24 connection migration belongs to Phase 5. Tests apply migrations to isolated SQLite. Authorized local setup has now also applied the pending existing migrations to this checkout's database; no additional migration was introduced for setup.

## Model disclosure boundary

OpenMemory enforces whether evidence leaves Core for an application. It cannot technically govern plaintext after the application receives it. A malicious authorized application can retain or forward text regardless of grant wording. Revocation cannot recall earlier plaintext, and permission preflight does not create remote control over the client.

The added mechanism records owner intent as one optional application grant:

```json
{
  "destination": "https://api.openai.com",
  "model": "gpt-5.4",
  "sources": ["native_memory", "history", "github"]
}
```

Core validates an HTTPS origin, model identifier, and source names. Each source requires ordinary resolve/retrieval/disclosure capabilities. Core stores no model key and makes no model request. Registration defaults to no onward permission; grant replacement omitting this field clears it. Permission changes increment the existing grant revision.

Additive bundle disclosure metadata includes `grant_revision`, nullable `model`, and `onward_disclosure`, either `not_authorized` or `application_responsibility`. The fragment contract and bundle version remain unchanged. Third-party compatibility has not been tested; clients must deny unknown authorization states. The permission endpoint reports current source flags, expiry, revision, and model permission, with a final application validity check.

The reference client compares current permission with the resolved bundle, requires the configured destination/model, filters evidence by source, rejects expired or changed grants, and requires explicit user action. It never silently re-resolves and substitutes evidence at send time. It excludes coverage from sources denied model disclosure while preserving failed outcomes from permitted sources with no fragments.

Permission preflight does not refresh every previously delivered source record. A deletion or upstream change after bundle delivery can leave its already-received excerpt in the client's five-minute model-use window. Resolve again to obtain a fresh bundle; do not interpret preflight as retroactive source revocation. A top-level failed HTTP resolution has no bundle measurements; record that failure separately without logging its question or server response body.

Static trusted instructions, the entered question, and a JSON `OPENMEMORY_EVIDENCE` message are separate. The latter is a user-role message explicitly marked untrusted. Imported instructions and commit text never enter the system message. Adversarial fixtures verify placement, not immunity of a real model to persuasion. No tools are available for model execution.

The selected [GPT-5.4 model](https://developers.openai.com/api/docs/models/gpt-5.4) uses the Responses API with `reasoning.effort=medium`, `max_output_tokens=4096`, `store=false`, `background=false`, `tools=[]`, and `tool_choice=none`. No conversation, previous response, web tool, or external memory is configured. The provider's [data controls](https://developers.openai.com/api/docs/guides/your-data) distinguish API application state from abuse-monitoring retention. Disabling storage does not establish zero retention. Live entitlement and responses have not been verified.

The client assigns per-bundle labels E1, E2, and so on. Citations map only to fragments actually supplied. Unknown labels remain unknown, and `semantic_support=not_verified` states the limit. Provenance can establish what a source said without proving its claim.

## Ten actual-data questions and measurements

Run these against existing data without adding statements or importing archives to manufacture success. The owner may adjust wording to fit a real topic; retain the query ID and document the reason locally without committing private content. An absent topic should produce insufficient evidence.

For every run, select Q1 through Q10, inspect the bundle, optionally request the permitted answer and no-context baseline, and record human judgments. The client records requested sources, source grants, sources actually touched, outcomes, fragment count, bundle bytes, resolver/HTTP latency, SQL count, external request count, model context/input bytes, provider token counts when supplied, and three human choices. Actual queried sources come from resolver-local DB reads or attempted HTTP calls. The `searched` outcome can include an attempt stopped locally. Grant flags are a pre-resolution snapshot; returned outcomes determine the effective result.

| ID | Question | Sources and mode | Expected benefit | Useless result |
|---|---|---|---|---|
| Q1 | What preferences have I explicitly saved about software development? | Native Memory; no dates. | Accepted preferences guide coding choices. | Generic or invented preferences. |
| Q2 | What constraints have I explicitly saved for OpenMemory? | Native Memory; no dates. | Saved constraints prevent an incompatible plan. | Irrelevant keyword matches. |
| Q3 | What have I previously discussed about OpenMemory portability? | History; no dates. | Dated excerpts recover earlier tradeoffs. | Claiming complete discussion history from a few matches. |
| Q4 | What concerns did I raise about permission-controlled context? | History; no dates. | Relevant questions distinguish concerns from decisions. | Treating imported model suggestions as owner decisions. |
| Q5 | What was I working on in the OpenMemory repository last week? | GitHub; enter the intended UTC week. | Commit excerpts and references support a bounded work summary. | Inferring unsaved work or other branches. |
| Q6 | Which recent changes are relevant to context authorization? | GitHub; explicit window of at most 31 days. | Commit excerpts identify changes worth inspecting. | Claiming implementation correctness without code evidence. |
| Q7 | How does what I currently want OpenMemory to become differ from my earlier discussions? | Native Memory plus History; no dates. | Current assertions and older excerpts support a qualified comparison. | Inventing changed intent from missing matches. |
| Q8 | What repository work was happening around the time I discussed federated context? | History plus GitHub; temporal option. | A discussion and nearby commits support a timeline. | Implying causality from proximity. |
| Q9 | What repository work coincided with my discussions of portability? | History plus GitHub; temporal option. | Cross-source evidence helps prepare a project update. | An unrelated first match defining the wrong window. |
| Q10 | Using what I saved, discussed, and implemented, summarize how OpenMemory's architecture evolved. | All three; temporal option. | Distinct assertions, discussions, and events improve a qualified narrative. | Fluent chronology unsupported by excerpts. |

All ten records are **NOT RUN**. Counts, sizes, latencies, relevance, irrelevant-evidence dominance, and benefit are unmeasured, not zero. Each selected source needs retrieval and recipient disclosure; GitHub also needs a selected resource grant. Q8 through Q10 require both directed disclosure permissions. Model use requires the named model grant for each source sent. No actual evidence or expected answer has been fabricated.

Human evaluation asks whether relevant evidence was found, whether irrelevant evidence dominated, and whether the answer materially benefited from context. Compare the optional same-question baseline and inspect citations. Do not turn these into an automatic score. Better justified uncertainty or prevention of a wrong recommendation can be beneficial; fluent paraphrase alone is insufficient.

## Live GitHub checklist

Every row remains pending on the identified installation. Use the authorized repository only. Do not alter repository content, visibility, other people's access, or commits, and do not exhaust a rate limit. Test local controls through the owner UI.

| Check | Procedure and expected behavior |
|---|---|
| Connection and identity | Connect the locally configured expiring fine-grained token. Identity comes from `/user`, never a supplied OpenMemory owner ID. Confirm it in the UI. |
| Discovery and selection | Select only `Raethexn-Technologies/OpenMemory`. Discovery alone authorizes no context retrieval. |
| Live evidence | Grant that resource to the application and resolve a bounded interval. Compare SHA, canonical URL, commit time, and retrieval time with GitHub without saving payloads. |
| Private access | Test only if the already-authorized repository is private and accessible. Otherwise record not applicable under current scope; do not add a repository. |
| Application grants | Remove read, disclosure, and resource grants separately. Expect denial and no GitHub HTTP attempt; local access remains independent. |
| Temporal permission | Run Q8 with both consent levels. Only repository/date/page parameters leave for GitHub. Inspect safe counters and audit intent, not tokens or full traffic capture. |
| Temporal denial | Remove the directed grant, then connection consent separately. Expect `query_disclosure_denied`, zero GitHub requests, and local history availability. |
| Deselection | Deselect and resolve again. Commit retrieval stops. Reselection does not recreate removed application resource grants automatically. |
| Application revocation | Revoke the experiment registration and retry. Authentication fails and model preflight prevents new model calls. |
| Invalid credential | Revoke a disposable token used solely for this connection at GitHub, then retry. An upstream 401 becomes `credential_invalid`, clears the local credential, and makes later attempts fail locally. Do not revoke a shared token. |
| Expiry | Observe a legitimately short-lived credential or configured deadline. Local expiry is `credential_expired`; upstream 401 cannot reliably distinguish early expiry from revocation. Do not edit the primary database to force expiry. |
| Disconnection | Disconnect in Core. Credentials and selections disappear; future GitHub resolution fails closed while Native Memory works. The application cannot reconnect. |
| Partial results | Combine a valid local source with disconnected/invalid GitHub. Local evidence survives with explicit failure. Successful no-match retrieval remains distinct. |
| Rate limiting | If naturally encountered, inspect retry metadata and cooldown without repeated retries. Otherwise leave live behavior unexercised and cite synthetic coverage. |
| Truncation | Use a naturally busy authorized window if available. Exceeding the page cap produces incomplete coverage, not exhaustive work history. |

Connection/discovery have separate owner operations; resolver counters cover resolution only. A successful connection must still be followed by corpus/model questions before judging usefulness.

## SQL investigation and performance

`ContextPerformanceTest` reproduces the review workload using 10,000 fabricated native rows, 1,000 imported messages, 100 matching candidates per local source, and three mocked repositories with two pages each. It returns twenty fragments and performs nine HTTP calls: metadata validation plus two commit pages per repository. It never queries raw conversation records.

The original resolver produced 621 cold SQL queries. The review's 619 warm result excludes two schema/redaction-policy initialization queries. Its 89.01 ms median remains historical. The reproduction took 108.56 ms with query-origin tracing. After batching and additional audit/lifecycle checks, a traced cold run produced **149 queries**, nine HTTP calls, about 15.1 KiB of JSON, and 60.35 ms. These in-memory observations do not measure production SQL or live GitHub latency.

A verification after the interrupted session again produced 149 queries, nine HTTP calls, and 15,147 bytes, but took 901.62 ms under that run's host conditions. The focused suite passed 112 tests with 689 assertions. Query counts reproduced; elapsed time varied materially and does not support a latency improvement claim from these individual runs.

| Table/category | Before | After | Explanation |
|---|---:|---:|---|
| Application authorization | 264 | 38 | Capability conjunctions share one fresh row within a checkpoint. |
| Corpus bindings | 214 | 18 | Normalization resolves the binding once; anchor checks stay fresh. |
| Native rows | 11 | 3 | Search and two batched lifecycle checks replace per-fragment reads. |
| Imported messages | 34 | 17 | Disclosure checks are batched; temporal anchor checks remain. |
| Conversation relation | 1 | 1 | Candidate relationship loading was already bounded. |
| GitHub connections | 52 | 35 | Resource checks reuse the current application row and batch fragments. |
| GitHub resources | 42 | 25 | Checks operate per resource/checkpoint instead of per commit. |
| Audit inserts | 1 | 10 | Nine durable outbound intents precede the final event. |
| Schema/policy initialization | 2 | 2 | Cold initialization remains. |
| Total | 621 | 149 | A ceiling of 160 detects material regression in this workload. |

Original query callers included 241 policy `allows` queries, 214 corpus-key queries, 66 resource-policy queries, 33 history freshness queries, eighteen provider resource loads, nine connection refreshes, ten native freshness queries, twenty GitHub freshness queries, and ten remaining queries. The optimized breakdown is 37 application checks, eighteen corpus-key lookups, 26 resource-policy queries, sixteen history lifecycle queries, eighteen provider resource loads, nine connection checks, ten audit inserts, two native lifecycle queries, four GitHub lifecycle queries, and nine other queries. Eager loading resources and connections issues separate SQL statements.

Repeated checks between network calls remain intentional. Request-long authorization caching would hide revocation, and history anchors are checked before outbound calls. `BatchContextSource` is optional: the existing search/isCurrent contract remains supported. Built-in sources additionally validate bounded groups. Resolver batches run before normalization and again after all sources finish. Resource decisions are shared only within a normalization checkpoint. Authorization remains centralized in `ContextPolicy`, with no cross-request cache.

Successful responses expose `X-Context-Sql-Queries`, `X-Context-Provider-Requests`, `X-Context-Sources-Queried`, and `X-Context-Resolver-Ms`. SQL/time include resolver work and final audit, excluding authentication, permission preflight, and browser latency. The client separately measures HTTP duration. Counters retain no SQL text, bindings, questions, or URLs. Missing counters are unknown, not zero; consumers need not depend on these headers.

Sources still execute sequentially. Temporal GitHub retrieval depends on history; independent-source parallelism was not added speculatively. GitHub permits three repositories, twenty commits each, two pages, 512 KiB per response, five seconds per call, and a 25-second deadline checked before subsequent operations. An in-flight call can extend past that deadline. At most nine retrieval HTTP attempts occur before early failures/cooldown reduce the work. The client allows 35 seconds for Core, sixty for a model call, and one active external operation across sessions. Local SQL has no hard wall-clock cancellation.

Core limits bundles to 32 KiB, with 24 KiB of serialized fragments, twenty total fragments, ten per source, and 600 characters per excerpt. The client requests twelve total and five per source. Model responses are bounded to 64 KiB and 4,096 generated tokens including reasoning; incomplete output is an explicit error. Large lexical scans, remote SQL, and sequential HTTP remain likely latency costs. The measured database behavior is defensible, but live latency is unmeasured.

## Shared planner cleanup

| Assumption | Classification | Result |
|---|---|---|
| A source event time can constrain another source. | A: Generic deterministic planning. | Retained with declared source pairs, time metadata, and disclosure checks. |
| Federation requires a connection and selected resources. | A: Generic for current resource-scoped federation. | Retained without claiming universality. |
| Three repositories and seven-day temporal radius are global rules. | B: GitHub policy. | Moved to provider query constants advertised by the registry. |
| Every remote query needs commit-specific dates and an empty question. | B: Provider projection. | `GitHubQuery::prepare` owns date bounds and question removal. |
| Request validation must hardcode history-to-GitHub. | B: Supported capability data. | Registry metadata declares the pair; no other pair is enabled. |
| A model should choose arbitrary sources or plans. | C: Not justified. | No autonomous planner or broad extension framework was added. |

Shared resolution handles capabilities, authorized resources, constraints, and fragments. Provider-specific capability names remain in registry/policy declarations and the provider. The client still knows to supply GitHub dates and how to request the one temporal strategy. Response consumption is provider-neutral; request construction is not fully provider-neutral. No second provider was used to claim broader validation.

## Audit completeness and revocation

| Stage | Observation | Limit |
|---|---|---|
| Application authentication | OBSERVED BY OPENMEMORY: bearer hash, owner, expiry, and revocation are checked. | No separate successful-auth event is persisted. Invalid credentials have no verified context owner. |
| Permission inspection | OBSERVED BY OPENMEMORY: `context.permissions` records inspection. | A final validity failure can deny its response after the event. It does not prove receipt. |
| Source resolution | OBSERVED BY OPENMEMORY: final outcomes and fragment counts. | Internal stages are not separately durable; a crash can omit the final event. |
| GitHub disclosure | OBSERVED BY OPENMEMORY: correlated `context.provider_attempt` precedes each resolver HTTP call. | Intent does not prove provider receipt/completion. A crash after intent is ambiguous. |
| Bundle construction | OBSERVED BY OPENMEMORY: selected fragments and allowed/partial/denied outcome. | Construction does not prove application receipt. |
| Application receipt | OUTSIDE OPENMEMORY'S CONTROL: the client validates the response. | A report to Core would be CLAIMED BY APPLICATION. |
| Model invocation/response | OUTSIDE OPENMEMORY'S CONTROL: the reference process observes its own call. | No attestation endpoint claims Core verification. |
| Retention/deletion | OUTSIDE OPENMEMORY'S CONTROL after disclosure. | Core cannot know whether the application deleted or copied plaintext. |

Audit insertion failure before a provider call prevents that call. Events retain identities, source names, outcomes, counts, time, and correlation IDs, not query text, dates sent upstream, repository payloads, commits, or prompts. They remain sensitive access metadata. Connection/discovery outside resolution retain existing audit behavior; this is not a universal network write-ahead audit.

Tests interleave grant revocation, repository deselection, connection disconnection, Native Memory deletion after initial freshness, and application revocation before bundle construction. Fresh revision checks reject stale authority, changed resources discard evidence, and native lifecycle changes remove fragments with incomplete coverage. Permission inspection also rechecks the application before responding.

These are checkpoint guarantees, not serializable revocation across SQL and networks. Changes after the last check can race a request or response already being sent. Dates already disclosed to GitHub and plaintext received by applications cannot be recalled. Controlled SQLite interleavings do not establish simultaneous PostgreSQL behavior. The earlier raw-history deletion/import race remains unresolved and outside these resolver changes.

## Security findings

| Threat | Assessment | Evidence and residual boundary |
|---|---|---|
| Cross-owner or forged application access | MITIGATED | Bearer validation, owner scoping, and isolation tests remain intact. |
| Repository escalation | MITIGATED | Owner selection, resource grants, identity validation, and revision checks intersect server-side. |
| Undeclared history-to-GitHub disclosure | MITIGATED | Both directed permissions are required; projection removes the question. |
| Stale grants during execution | PARTIALLY MITIGATED | Fresh checkpoints catch tested interleavings; remote revocation is not atomic. |
| Application token theft | PARTIALLY MITIGATED | Core hashes tokens; expiry/revocation are enforced. A stolen live token still has its authority. |
| GitHub credential theft | PARTIALLY MITIGATED | Encryption/output suppression remain; the host operator and APP_KEY are trusted. |
| Credential/context logging | MITIGATED in implemented paths | Fixed error codes and metadata exports avoid payload logging. Operator tracing and process dumps remain outside the guarantee. |
| Malicious imported text or commits | PARTIALLY MITIGATED | Untrusted message placement, text rendering, and no tools preserve boundaries. Real-model obedience remains unvalidated. |
| Query-based accumulation by permitted clients | ACCEPTED LIMITATION | Repeated bounded reads can accumulate source-wide data. Onward intent is an application obligation. |
| Audit of receipt/deletion | ACCEPTED LIMITATION | Intent and selection are recorded honestly; client actions cannot be inferred. |
| Raw-history deletion/import concurrency | UNRESOLVED | No production concurrency validation or deletion redesign was performed. |
| Compromised local host | ACCEPTED LIMITATION | Host/Origin checks, CSRF, CSP, and transient storage reduce web attacks, not same-user operating-system access. |

MCP still cannot retrieve imported private history or Native Memory through this contract. Raw originals remain owner-route-only. No import acquires network operations, and this experiment sends no source code to models.

## Retention

| Category | Persistence and controller | Removal and limits |
|---|---|---|
| Native Memory | OPENMEMORY CORE retains intentional SQL records. | Owner lifecycle/deletion applies; USER/operator controls separate exports/backups. |
| Imported history/raw originals | OPENMEMORY CORE retains the existing corpus. | Existing deletion applies; ordinary SQL is not field-encrypted, and the documented deletion race remains. |
| Application registration/grants | OPENMEMORY CORE retains hash, expiry, revision, resources, and model intent. | Revocation stops authority but retains metadata. No model key is included. |
| GitHub connection | OPENMEMORY CORE retains encrypted credential and configuration. | Disconnect clears credential, consent, and retry state but retains identity/disconnection metadata. GITHUB controls upstream token revocation. |
| Repository selections | OPENMEMORY CORE retains resource identity/revisions. | Deselection removes the resource row; disconnection removes selections. Stale application UUID references confer no authority. |
| Access audit | OPENMEMORY CORE retains metadata and correlation. | Age-based scheduled pruning applies; an inactive scheduler leaves records retained. |
| ContextFragments/ContextBundle | Core and REFERENCE APPLICATION hold transient data; client retains the latest bundle/question per session in RAM. | Replacement, clear, expiry, or exit drops references. No persisted context cache exists. |
| Commit evidence | GITHUB controls authoritative content; Core/client hold transient excerpts. | No mirror or Native Memory conversion occurs. Disclosed copies can outlive upstream deletion. |
| Model request | REFERENCE APPLICATION holds the request during transmission; MODEL PROVIDER controls received data. | No local prompt file or conversation chain is retained. Provider policy applies with `store:false`. |
| Model response | REFERENCE APPLICATION returns it to the browser without an answer database. | Browser clearing removes displayed state; provider, diagnostics, swap, and USER copies are separate. |
| Measurement report | Client retains twenty metadata records per session; USER may download them. | Clear/expiry removes server references. User controls downloaded reports outside this repository. |
| Local secrets | USER controls ignored `.env`; Core encrypts GitHub credentials. | Git ignore is not encryption. Remove/revoke keys separately when required. |

Core audit pruning removes records older than thirty days and requires its scheduler or manual command. Model intent is authorization metadata, not Native Memory or part of native exports. Application clearing does not promise secure erasure from process memory, swap, dumps, or backups.

## Permission usability and developer experience

The owner still makes separate decisions about retrieval, receipt, selected repositories, application resource access, temporal disclosure, and model use. Labels explain consequences alongside capability names, but the decision count remains substantial. The application cannot grant itself authority or turn model configuration into permission.

A future presentation could ask for source/resource scope, whether the application may receive excerpts, whether history-derived dates may leave for GitHub, and whether the named model may receive selected evidence. Retrieval-only authority should remain an explicit advanced option. This could generate the same policy intersection with fewer exposed concepts; it has not been implemented or tested with a normal owner.

The essential integration is ordinary HTTP:

```javascript
const response = await fetch(coreUrl + '/api/app/context/resolve', {
  method: 'POST',
  headers: { Authorization: `Bearer ${applicationToken}`, 'Content-Type': 'application/json' },
  body: JSON.stringify({ version: 'context-request-v1', query: question,
    sources: ['native_memory', 'history'], limit: 12, per_source_limit: 5 }),
});
```

`openmemory.mjs` adds bounds, deadlines, version/trust checks, metrics, and projection. `model.mjs` contains preflight comparison, filtering, untrusted prompt construction, provider integration, and citation-ID checks. `server.mjs` adds local-browser security and transient state. These are documented local files, not an SDK hiding Core imports.

Authentication is simple; manual grant setup and once-shown credentials are awkward. Changes require re-resolution before model use. A 401 rejects invalid credentials, 403 indicates denied authority, and a successful bundle can still carry source failures. `no_matches` must be read with scope; incomplete retrieval is not proof of absence.

Provenance survives through labels and timestamps without GitHub-specific model parsing. Request construction still needs dates, source names, and the supported temporal plan. A natural-language question is a lexical query, not semantic retrieval. This mismatch may dominate real usefulness and must not be hidden by fixtures.

Raw HTTP suffices for one application. Repeated risks are bounded parsing, unknown versions/statuses, stale onward permissions, source filtering, and conflating receipt with model authority. A future small typed client could offer `resolve`, `permissions`, contract types, and explicit evidence projection, leaving registration/grants/model calls outside it. No SDK expansion is justified before live validation completes.

## Assessment

The code path now connects owner-granted identity to contextual model input through an independent inspectable client. SQL pathology is explained, and outbound audit intent no longer depends solely on the final audit. These are engineering results, not evidence that an actual answer improved.

Relevance, the first dated history anchor, short-excerpt sufficiency, private-repository behavior, permission comprehension, and citation accuracy remain unvalidated. Finish real owner/corpus setup here, configure secrets locally, and run the ten queries plus non-destructive lifecycle checks before reconsidering these conclusions.

| Dimension | Conclusion | Reason |
|---|---|---|
| Architecture | MODIFY | HTTP consumption works in tests, but request construction and real usefulness remain unvalidated. |
| Federation | NOT LIVE-VALIDATED | Only simulated HTTP and controlled lifecycle tests ran. |
| Developer experience | NEEDS WORK | Grants, temporal requests, and onward handling require careful client code. |
| Permission experience | NEEDS WORK | Honest model intent closes a missing primitive without proving usability. |
| Performance | ACCEPTABLE for the bounded synthetic workload | The 621-to-149 reduction is reproducible and retains checkpoints; live latency is unmeasured. |
| Product usefulness | NOT VALIDATED | Actual-data runs and human comparisons are pending. |
| Production readiness | NOT READY | Live operations, concurrency, retention operations, and model behavior are not validated. |

Complete this same milestone on the authorized local installation before selecting another. No additional provider, SDK product, or Phase 6 has begun.
