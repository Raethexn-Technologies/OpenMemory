/**
 * Tool definitions and execution for the OpenMemory agent.
 *
 * Safety constraints enforced here:
 *   - All file paths are resolved against REPO_PATH; anything outside is rejected.
 *   - Shell commands are split into an argument array; only the first token
 *     is accepted if it appears in ALLOWED_COMMANDS.
 *   - Specific destructive flags are blocked regardless of command.
 *   - Processes time out after 60 seconds.
 *   - Git operations never use --force.
 */

import path from 'path';
import fs from 'fs/promises';
import { spawn } from 'child_process';
import { fileURLToPath } from 'url';
import { Octokit } from '@octokit/rest';
import { retrieve, store } from './memory.js';

// ─── Path Safety ─────────────────────────────────────────────────────────────

const REPO_PATH = path.resolve(
  process.env.REPO_PATH ??
    path.join(path.dirname(fileURLToPath(import.meta.url)), '../../..')
);

/**
 * Resolve a relative or absolute path and confirm it sits inside REPO_PATH.
 * Throws if the resolved path escapes the repository root.
 *
 * @param {string} rel - Path provided by the LLM.
 * @returns {string}   - Absolute, safe path.
 */
function safePath(rel) {
  const resolved = path.resolve(REPO_PATH, rel);
  if (!resolved.startsWith(REPO_PATH + path.sep) && resolved !== REPO_PATH) {
    throw new Error(`Path outside repository: ${rel}`);
  }
  return resolved;
}

// ─── Shell Safety ─────────────────────────────────────────────────────────────

const ALLOWED_COMMANDS = new Set(['git', 'npm', 'npx', 'node', 'php', 'composer', 'pnpm']);

const BLOCKED_ARGS = ['--force', '--hard', '--no-verify', '--allow-empty-message'];

/**
 * Run a shell command and return its stdout as a string.
 * Uses spawn (not exec) to avoid shell injection via argument array parsing.
 * Times out after 60 seconds.
 *
 * @param {string}  command - Full command string, e.g. "git status".
 * @param {string}  [cwd]   - Working directory. Defaults to REPO_PATH.
 * @returns {Promise<string>}
 */
function runCommand(command, cwd) {
  return new Promise((resolve, reject) => {
    const parts = command.trim().split(/\s+/);
    const [bin, ...args] = parts;

    if (!ALLOWED_COMMANDS.has(bin)) {
      return reject(
        new Error(
          `Command "${bin}" is not in the allowed list. Permitted: ${[...ALLOWED_COMMANDS].join(', ')}.`
        )
      );
    }

    const blocked = args.find((arg) => BLOCKED_ARGS.includes(arg));
    if (blocked) {
      return reject(new Error(`Argument "${blocked}" is not permitted.`));
    }

    const workdir = cwd ? safePath(cwd) : REPO_PATH;

    const proc = spawn(bin, args, {
      cwd: workdir,
      stdio: ['ignore', 'pipe', 'pipe'],
    });

    let stdout = '';
    let stderr = '';

    proc.stdout.on('data', (chunk) => { stdout += chunk.toString(); });
    proc.stderr.on('data', (chunk) => { stderr += chunk.toString(); });

    const timer = setTimeout(() => {
      proc.kill();
      reject(new Error(`Command timed out after 60 seconds: ${command}`));
    }, 60_000);

    proc.on('close', (code) => {
      clearTimeout(timer);
      if (code !== 0) {
        reject(new Error(`Command exited with code ${code}.\nstderr: ${stderr.trim()}`));
      } else {
        resolve(stdout);
      }
    });

    proc.on('error', (err) => {
      clearTimeout(timer);
      reject(new Error(`Failed to spawn "${bin}": ${err.message}`));
    });
  });
}

// ─── GitHub Client ────────────────────────────────────────────────────────────

const GITHUB_TOKEN     = process.env.GITHUB_TOKEN || '';
const GITHUB_OWNER     = process.env.GITHUB_REPO_OWNER || '';
const GITHUB_REPO      = process.env.GITHUB_REPO_NAME || '';
const GITHUB_BASE      = process.env.GITHUB_BASE_BRANCH || 'main';

const octokit = new Octokit({ auth: GITHUB_TOKEN });

// ─── Tool Definitions (Anthropic SDK format) ──────────────────────────────────

export const TOOL_DEFINITIONS = [
  {
    name: 'read_file',
    description: 'Read the contents of a file within the repository. Returns the file contents as a string.',
    input_schema: {
      type: 'object',
      properties: {
        path: {
          type: 'string',
          description: 'Path to the file, relative to the repository root.',
        },
      },
      required: ['path'],
    },
  },
  {
    name: 'write_file',
    description: 'Write or overwrite a file within the repository. Creates parent directories if they do not exist.',
    input_schema: {
      type: 'object',
      properties: {
        path: {
          type: 'string',
          description: 'Path to the file, relative to the repository root.',
        },
        content: {
          type: 'string',
          description: 'The full content to write to the file.',
        },
      },
      required: ['path', 'content'],
    },
  },
  {
    name: 'list_directory',
    description: 'List the names of files and directories inside a directory within the repository.',
    input_schema: {
      type: 'object',
      properties: {
        path: {
          type: 'string',
          description: 'Path to the directory, relative to the repository root.',
        },
      },
      required: ['path'],
    },
  },
  {
    name: 'run_command',
    description:
      'Run a shell command within the repository. Only whitelisted executables are permitted: git, npm, npx, node, php, composer, pnpm. Destructive flags such as --force and --hard are blocked.',
    input_schema: {
      type: 'object',
      properties: {
        command: {
          type: 'string',
          description: 'The full command to run, e.g. "git status" or "npm test".',
        },
        cwd: {
          type: 'string',
          description: 'Optional working directory relative to the repository root. Defaults to the repository root.',
        },
      },
      required: ['command'],
    },
  },
  {
    name: 'github_list_issues',
    description: 'List open issues on the configured GitHub repository.',
    input_schema: {
      type: 'object',
      properties: {},
      required: [],
    },
  },
  {
    name: 'github_create_pr',
    description: 'Create a pull request from a branch to the configured base branch.',
    input_schema: {
      type: 'object',
      properties: {
        title: {
          type: 'string',
          description: 'Title of the pull request.',
        },
        body: {
          type: 'string',
          description: 'Body text of the pull request in markdown.',
        },
        head: {
          type: 'string',
          description: 'The branch name to merge from.',
        },
      },
      required: ['title', 'body', 'head'],
    },
  },
  {
    name: 'memory_retrieve',
    description: 'Fetch recent memory records from the OpenMemory graph for the configured user.',
    input_schema: {
      type: 'object',
      properties: {},
      required: [],
    },
  },
  {
    name: 'memory_store',
    description: 'Store a new memory record in the OpenMemory graph.',
    input_schema: {
      type: 'object',
      properties: {
        content: {
          type: 'string',
          description: 'The memory text to store. Write as a clear, self-contained sentence.',
        },
        sensitivity: {
          type: 'string',
          enum: ['public', 'private', 'sensitive'],
          description: 'Access level for the memory. Defaults to "public".',
        },
      },
      required: ['content'],
    },
  },
];

// ─── Tool Implementations ─────────────────────────────────────────────────────

const toolImplementations = {
  async read_file({ path: filePath }) {
    const abs = safePath(filePath);
    return fs.readFile(abs, 'utf-8');
  },

  async write_file({ path: filePath, content }) {
    const abs = safePath(filePath);
    await fs.mkdir(path.dirname(abs), { recursive: true });
    await fs.writeFile(abs, content, 'utf-8');
    return `File written: ${filePath}`;
  },

  async list_directory({ path: dirPath }) {
    const abs = safePath(dirPath);
    const entries = await fs.readdir(abs, { withFileTypes: true });
    return entries.map((e) => (e.isDirectory() ? `${e.name}/` : e.name));
  },

  async run_command({ command, cwd }) {
    return runCommand(command, cwd);
  },

  async github_list_issues() {
    const { data } = await octokit.issues.listForRepo({
      owner: GITHUB_OWNER,
      repo: GITHUB_REPO,
      state: 'open',
      per_page: 50,
    });

    return data.map((issue) => ({
      number: issue.number,
      title: issue.title,
      body: issue.body,
      labels: issue.labels.map((l) => (typeof l === 'string' ? l : l.name)),
    }));
  },

  async github_create_pr({ title, body, head }) {
    const { data } = await octokit.pulls.create({
      owner: GITHUB_OWNER,
      repo: GITHUB_REPO,
      title,
      body,
      head,
      base: GITHUB_BASE,
    });

    return data.html_url;
  },

  async memory_retrieve() {
    return retrieve();
  },

  async memory_store({ content, sensitivity = 'public' }) {
    return store(content, sensitivity);
  },
};

// ─── Dispatcher ───────────────────────────────────────────────────────────────

/**
 * Execute a named tool with the given input object.
 * Returns the result as a string or plain value. On error, returns an
 * "Error: ..." string so the agent loop can report it to Claude and continue.
 *
 * @param {string} name  - Tool name matching a key in TOOL_DEFINITIONS.
 * @param {object} input - Input object parsed from the tool_use block.
 * @returns {Promise<string | object | Array>}
 */
export async function executeTool(name, input) {
  const impl = toolImplementations[name];

  if (!impl) {
    return `Error: Unknown tool "${name}".`;
  }

  try {
    const result = await impl(input);
    // Stringify objects and arrays so Claude receives readable text.
    if (typeof result === 'string') return result;
    return JSON.stringify(result, null, 2);
  } catch (err) {
    return `Error: ${err.message}`;
  }
}
