# Security Policy

## Supported versions

| Version | Supported |
|---------|-----------|
| `main` branch | Yes |

Only the current `main` branch receives security fixes. Older tagged releases are not backported.

## Reporting a vulnerability

Do not open a public GitHub issue for security reports. Public disclosure before a fix is available puts all users of the project at risk.

Send an email to security@raethexn.com with the subject line "OpenMemory Security Report". Your report should include a clear description of the vulnerability, the steps needed to reproduce it, and an assessment of the potential impact. Attach any proof-of-concept code as a file attachment rather than pasting it inline.

You can expect an acknowledgement within 72 hours of sending your report. After triage, you will receive a message with a resolution timeline. If you do not hear back within 72 hours, send a follow-up to the same address.

## Secrets and credentials

The project never stores secrets in source code. All credentials, API keys, and tokens are configured through environment variables. Each component documents its required variables in a `.env.example` file:

- `app/.env.example` covers the Laravel application.
- `agent/.env.example` covers the autonomous agent.
- `icp/mcp-server/` relies on the same environment variable pattern.

Do not commit `.env` files. The root `.gitignore` and each component's local `.gitignore` exclude them by default.

## Scope

The following areas are in scope for security reports:

**ICP canister access control.** The Motoko canister enforces read access using `msg.caller`. Private and Sensitive records are only returned to the principal that stored them. A bypass of this check is a critical vulnerability.

**API key validation for `/mcp/store`.** The Laravel endpoint that accepts memory writes from the MCP server and the agent requires an `X-OMA-API-Key` header. Missing or incorrect validation of that header allows unauthenticated memory writes.

**Path traversal in agent file tools.** The `read_file`, `write_file`, and `list_directory` tools in the agent validate every path against `REPO_PATH` before performing any file system operation. A path that escapes `REPO_PATH` is rejected. Any bypass of this validation is in scope.

**Shell command whitelisting in the agent.** The `run_command` tool only accepts a fixed set of executables: `git`, `npm`, `npx`, `node`, `php`, `composer`, and `pnpm`. Arguments matching known destructive flags (`--force`, `--hard`, `--no-verify`, `--allow-empty-message`) are also rejected. A bypass that allows arbitrary command execution is in scope.
