# OpenMemory Agent

This is a Node.js process that bridges external messaging platforms to an autonomous coding agent. Currently Discord is supported, with more connectors planned. The agent can read the project state, propose next steps, and implement selected tasks by editing files, running commands, and opening pull requests against the configured repository. It uses the OpenMemory memory graph to retain context about decisions made and tasks completed across sessions.

## Architecture

The system has two layers: connectors and the agent core.

Connectors are thin transport bridges. Each connector subscribes to messages from its platform, filters them to the configured channel and allowed users, and normalizes the incoming message into a standard format: a text string, a user ID, a `send` callback for posting plain text replies, and a `showOptions` callback for presenting a numbered list and waiting for a selection. The connector passes that normalized object to the agent core and does nothing else.

The agent core handles LLM reasoning and tool execution. When a message arrives, the core classifies the intent as either research (generate options for the user to choose from) or execution (implement a task directly). Research mode retrieves recent git history and memory context, then asks Claude to propose concrete next steps. Execution mode runs a standard agentic tool-use loop until the task is complete or the iteration limit is reached.

```
Connector (Discord / Telegram / ...)
        |
        v
   Agent Core
   - research: reads context, proposes options
   - execute: agentic tool-use loop with Claude
        |
        v
  Tools: file read/write, shell commands, git, GitHub API, OpenMemory API
```

The connector and core are deliberately decoupled. Adding a new transport requires no changes to the core.

## Setup

**Prerequisites:**

- Node.js 20 or later
- A running OpenMemory Laravel instance, or mock mode configured via `OMA_API_URL` pointing to `http://localhost:8000`
- A GitHub Personal Access Token with `repo` scope (read/write contents, pull requests, issues)

**Steps:**

1. Copy the environment template and open it in an editor.

   ```
   cd agent && cp .env.example .env
   ```

2. Fill in the required variables. The following are required for the agent to function at all: `ANTHROPIC_API_KEY` or `OPENROUTER_API_KEY`, `AGENT_MODEL`, `GITHUB_TOKEN`, `GITHUB_REPO_OWNER`, `GITHUB_REPO_NAME`, `OMA_API_URL`, `OMA_API_KEY`, and `OMA_USER_ID`. The connector variables are required only for the connectors you intend to use.

3. For Discord: create a bot application at discord.com/developers/applications. Under the bot's settings, enable the **Message Content Intent** under Privileged Gateway Intents. Generate a bot token and set it as `DISCORD_BOT_TOKEN`. Invite the bot to your server using the OAuth2 URL generator with the following permissions: Read Messages/View Channels, Send Messages, Use Slash Commands, and Add Reactions. Copy your server ID to `DISCORD_GUILD_ID`, the target channel ID to `DISCORD_CHANNEL_ID`, and your own Discord user ID to `DISCORD_ALLOWED_USER_IDS`. Multiple user IDs can be separated by commas.

4. Install dependencies.

   ```
   npm install
   ```

5. Start the agent.

   ```
   npm start
   ```

## Adding a connector

Each connector extends `BaseConnector` from `src/connectors/base.js`. The connector receives a normalized message object and calls `this.agent.handleMessage(...)`. The `send` callback posts a plain text reply to the user. The `showOptions` callback presents a list of options and resolves with the index the user selected.

```js
import { BaseConnector } from './base.js';

export class MyPlatformConnector extends BaseConnector {
  constructor(agent) {
    super(agent);
    // Initialize your platform client here.
  }

  async start() {
    // Connect to the platform. Subscribe to messages.
    // Call this.agent.handleMessage(...) for each incoming message from an allowed user.
    this.agent.handleMessage({
      text: incomingText,
      userId: incomingUserId,
      send: async (text) => { /* post reply to platform */ },
      showOptions: async (prompt, options) => {
        /* present numbered list, wait for selection, return chosen index */
      },
    });
  }

  async stop() {
    // Disconnect cleanly.
  }
}
```

Register the connector in `src/index.js` using the same pattern as the Discord connector: check for a required env var, instantiate, push to the `connectors` array.

## Tools available to the agent

| Tool | Description |
|---|---|
| read_file | Read any file within the repository |
| write_file | Write or overwrite a file within the repository |
| list_directory | List contents of a directory |
| run_command | Run a whitelisted shell command (git, npm, php, composer, node, npx) |
| github_list_issues | List open GitHub issues |
| github_create_pr | Create a pull request from the current branch |
| memory_retrieve | Fetch recent memories from the OpenMemory graph |
| memory_store | Store a new memory record |

## Security

The agent runs with access to the file system and shell. Read `SECURITY.md` in the repository root before deploying.

Key constraints enforced at runtime:

- All file paths passed to `read_file`, `write_file`, and `list_directory` are resolved against `REPO_PATH` and rejected if they escape that directory.
- Shell commands passed to `run_command` are split into an argument array and the first token is validated against an allowlist of safe executables: `git`, `npm`, `npx`, `node`, `php`, `composer`, and `pnpm`.
- Specific destructive argument flags are blocked regardless of the command: `--force`, `--hard`, `--no-verify`, and `--allow-empty-message`.
- Git operations initiated by the agent never use `--force`.
- Pull requests created by the agent always target the branch configured in `GITHUB_BASE_BRANCH` and are never auto-merged.
