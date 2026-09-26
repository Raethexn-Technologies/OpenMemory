# Local context validation client

This independent Node application calls OpenMemory over HTTP with an application bearer credential. It has no Core imports, database access, owner session, or GitHub credential. It can inspect context without a model and optionally send explicitly permitted excerpts to OpenAI GPT-5.4. The [validation report](../../docs/architecture/END_TO_END_VALIDATION.md) distinguishes implemented checks from pending live results.

## Prepare the existing installation

Use Node 22 or later and the authorized checkout. Preserve any existing owner, corpus, and `APP_KEY`. Apply pending Core migrations using its normal configuration, then build its frontend. The new milestone migration adds nullable model permission and audit correlation fields; existing applications receive no model permission automatically.

For this validation, the owner confirmed `A:\Projects\OpenMemoryAgent` as the installation and authorized initial owner/corpus setup after inspection found no users or imported history. Its existing migrations are now applied, debug output is disabled, and Core is configured at `http://127.0.0.1:8000`. No sample owner or fabricated history was seeded.

Create the real owner in a local terminal so the password remains outside chat and command arguments:

```powershell
Set-Location A:\Projects\OpenMemoryAgent\app
$validationEmail = Read-Host 'Local login email'
$validationName = Read-Host 'Display name'
php artisan openmemory:user:create $validationEmail --name=$validationName
```

The command prompts twice with hidden password entry and creates a unique corpus binding. Use its generated owner key for the explicitly authorized local history import. Keep the archive outside the repository; do not search unrelated archives or choose an owner key by convention. Import only the supplied path, locally, through `memory:import-archive`. Native Memory should contain the owner's actual saved statements, not invented validation content.

History import is paused until the owner supplies a specific export path. The current parsers accept these formats:

| Provider | Required data |
|---|---|
| ChatGPT | A `conversations.json` top-level array with conversation `mapping` trees. |
| Claude | A `conversations.json` top-level array with conversation `chat_messages` arrays. |
| Gemini | Google Takeout JSON activity records, normally `Takeout/My Activity/Gemini Apps/MyActivity.json`. A standalone `MyActivity.json` must contain recognizable Gemini records. These are individual activity records, not reconstructed conversation threads. |

Supply the original ZIP, an extracted export directory, or the relevant standalone JSON file with its original filename. HTML-only exports, PDFs, screenshots, copied transcripts, and arbitrary JSON are not supported by these history adapters. Preserve the provider structure; do not rename unrelated JSON to make it appear compatible.

There is no required staging directory or browser upload field. Keep the export in a private local directory outside `A:\Projects\OpenMemoryAgent`, or leave it at its existing download location. Supply only the exact full path when ready. The importer reads that path locally; the archive is not sent to a model or copied into source fixtures. No filesystem search is part of setup.

If Core needs restarting, run `php artisan serve --host=127.0.0.1 --port=8000 --no-reload` from `app`. An existing listener must be checked before starting a second process.

Sign into Core with the real owner. Check its bound history in `/history` and its saved statements in `/native-memory`. At `/sources/github`, connect an expiring fine-grained token with read-only Contents and Metadata access, limited to `Raethexn-Technologies/OpenMemory`. Verify the displayed GitHub identity. Select that repository explicitly. The [GitHub guide](../../docs/architecture/GITHUB_PROVIDER.md) explains organization approval and token limitations.

At `/applications`, register a local validation application. Select only the capabilities needed for the intended questions:

| Purpose | Required grants |
|---|---|
| Resolve context | `context.resolve` |
| Receive saved statements | `memory.read`, `memory.disclose` |
| Receive imported excerpts | `history.search`, `history.disclose` |
| Receive selected commit evidence | `github.commits.read`, `github.disclose`, plus the selected repository's resource grant |
| Use history-derived dates with GitHub | `history.query_disclose.github`, plus history query-disclosure consent on the GitHub connection |

For optional model use, separately authorize destination `https://api.openai.com`, model `gpt-5.4`, and the intended sources. These sources also require their ordinary retrieval and disclosure grants. This expresses owner permission to the application; Core cannot prevent an application from copying plaintext after delivery. The reference client checks the current permission before every model call.

Copy the once-shown application credential directly into the local client environment file. Keep credentials out of chat, terminal command arguments, screenshots, and exported reports.

## Start locally

In PowerShell:

```powershell
Set-Location examples/context-client
Copy-Item .env.example .env
```

Edit `.env` locally. Set `OPENMEMORY_URL` to the existing installation's loopback HTTP endpoint and `OPENMEMORY_APPLICATION_TOKEN` to the application credential. Set `OPENAI_API_KEY` only if model testing is wanted. Keep `OPENAI_MODEL=gpt-5.4`. The file is ignored by git and holds plaintext secrets under the local user's filesystem permissions. No dependency installation is needed.

```powershell
npm start
```

Open `http://127.0.0.1:8787`. Use this exact hostname; the server rejects other Host/Origin values. Core must be reachable on loopback. A remote Core deployment would require a separately secured local tunnel rather than weakening this client's URL restriction.

Enter a question and select sources. GitHub needs an explicit UTC interval of at most 31 days, or the history-to-GitHub option, which derives a two-day radius around the first dated history match. Dates use `YYYY-MM-DDTHH:mm:ssZ`. Relative words such as "last week" are not a date parser. A date interval also constrains local retrieval; omit it for Native Memory plus History questions that should search across time.

Inspect the bundle projection, coverage, timestamps, and metrics. Internal authorization identifiers are omitted from the browser projection; the server validates the original bundle. A failed source does not turn into a claim that nothing exists. Resolve again after changing grants or after five minutes if you want a model answer.

Model invocation requires the unchecked confirmation and a separate button. It uses medium reasoning, no web/search, no tools, no conversation state, and `store:false`. It sends the entered question and the permitted evidence, so inspect the question as well as excerpts. The model provider's retention policy still applies; `store:false` is not a zero-retention promise. See the [API data controls](https://developers.openai.com/api/docs/guides/your-data).

An optional baseline button sends the same question with no evidence or context-derived coverage. Compare answers manually and record the three human judgments. Citations such as `[E1]` identify supplied fragments; the client reports nonexistent identifiers and does not claim to verify semantic support.

Choose Q1 through Q10 to correlate the questions in the validation report. Download measurements before clearing the session. Downloads contain source names, outcomes, counts, timings, request IDs, model token counts, and human choices, but no questions, excerpts, repository names, answers, or credentials. Store reports outside this repository. Record partial and failed runs without substituting fabricated results.

## Retention and checks

The server keeps one question/bundle per browser session in RAM, with at most four sessions and twenty measurement records each. There is no context file, browser local storage, model response database, or conversation thread. Clearing, expiry, or process exit drops the server references; the browser also clears after ten minutes of inactivity. This is application-level deletion, not secure erasure of process memory, swap, crash dumps, or browser diagnostics. The model provider separately controls received request/response retention.

The client does not log requests, headers, questions, context, or answers. Disable payload capture in any local proxy or debugging tool. Stop the client with Ctrl+C, clear the page, and revoke the experiment's application registration when finished. Disconnect GitHub in Core if the connection is no longer wanted; revoke its token separately at GitHub if it should become unusable there too.

```powershell
npm test
```

Tests use fabricated evidence and simulated providers. They do not establish usefulness on the existing corpus or successful access to a live GitHub account.
