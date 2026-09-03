#!/usr/bin/env node

import { existsSync, readFileSync } from 'node:fs';
import { homedir } from 'node:os';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const scriptDir = dirname(fileURLToPath(import.meta.url));
const serverPath = resolve(scriptDir, 'server.js');
const defaultIdentityPath = join(homedir(), '.config', 'openmemory', 'identity.json');

function valueFor(name) {
  const withEquals = process.argv.find((arg) => arg.startsWith(`${name}=`));
  if (withEquals) {
    return withEquals.slice(name.length + 1);
  }

  const index = process.argv.indexOf(name);
  if (index >= 0 && process.argv[index + 1] && !process.argv[index + 1].startsWith('--')) {
    return process.argv[index + 1];
  }

  return null;
}

function hasFlag(name) {
  return process.argv.includes(name);
}

function trimTrailingSlash(value) {
  return String(value || '').replace(/\/$/, '');
}

function readIdentityPrincipal(filePath) {
  if (!existsSync(filePath)) {
    return null;
  }

  try {
    const data = JSON.parse(readFileSync(filePath, 'utf8'));
    return typeof data.principal === 'string' && data.principal.length > 0 ? data.principal : null;
  } catch {
    return null;
  }
}

function jsonString(value) {
  return JSON.stringify(String(value));
}

function tomlEnv(env) {
  return Object.entries(env)
    .map(([key, value]) => `${key} = ${jsonString(value)}`)
    .join(', ');
}

function shellQuote(value) {
  return jsonString(value);
}

function printHelp() {
  console.log(`OpenMemory MCP client setup

Usage:
  npm run setup-clients -- --mode mock
  npm run setup-clients -- --mode live --canister-id <id> --app-url <url>

Options:
  --mode mock|live       Configuration mode. Default: mock.
  --app-url <url>        Laravel/OpenMemory app URL.
  --api-key <key>        MCP API key. Defaults to OMA_API_KEY or placeholder.
  --user-id <id>         Mock-mode user id. Defaults to OMA_USER_ID or placeholder.
  --write-scope <scope>  public, public,private, or none. Default: public.
  --canister-id <id>     Live ICP canister id.
  --icp-host <url>       Live ICP host. Default: https://ic0.app.
  --identity-file <path> Live identity JSON path.
`);
}

if (hasFlag('--help') || hasFlag('-h')) {
  printHelp();
  process.exit(0);
}

const positionalMode = process.argv.slice(2).find((arg) => ['mock', 'live'].includes(arg));
const mode = valueFor('--mode') || positionalMode || process.env.OMA_SETUP_MODE || 'mock';
if (!['mock', 'live'].includes(mode)) {
  console.error(`Unsupported --mode ${mode}. Use mock or live.`);
  process.exit(1);
}

const writeScope = valueFor('--write-scope') || process.env.WRITE_SCOPE || 'public';
const apiKey = valueFor('--api-key') || process.env.OMA_API_KEY || '<set MCP_API_KEY from app/.env>';
const identityFile = valueFor('--identity-file') || process.env.OMA_IDENTITY_FILE || defaultIdentityPath;
const principal = readIdentityPrincipal(identityFile) || '<your-principal>';

const env = mode === 'mock'
  ? {
      OMA_MOCK_URL: trimTrailingSlash(valueFor('--app-url') || process.env.OMA_MOCK_URL || 'http://localhost:8080'),
      OMA_API_KEY: apiKey,
      OMA_USER_ID: valueFor('--user-id') || process.env.OMA_USER_ID || '<stable-user-id>',
      WRITE_SCOPE: writeScope,
    }
  : {
      ICP_CANISTER_ID: valueFor('--canister-id') || process.env.ICP_CANISTER_ID || '<canister-id>',
      ICP_HOST: trimTrailingSlash(valueFor('--icp-host') || process.env.ICP_HOST || 'https://ic0.app'),
      USER_PRINCIPAL: principal,
      OMA_API_URL: trimTrailingSlash(valueFor('--app-url') || process.env.OMA_API_URL || '<openmemory-app-url>'),
      OMA_API_KEY: apiKey,
      OMA_IDENTITY_FILE: identityFile,
      WRITE_SCOPE: writeScope,
    };

const mcpJson = {
  mcpServers: {
    openMemory: {
      command: 'node',
      args: [serverPath],
      env,
    },
  },
};

const envFlags = Object.entries(env)
  .map(([key, value]) => `--env ${key}=${shellQuote(value)}`)
  .join(' ');

console.log('OpenMemory MCP client setup');
console.log('');
console.log(`Mode:        ${mode}`);
console.log(`Server:      ${serverPath}`);
console.log(`Write scope: ${writeScope}`);
if (mode === 'mock') {
  console.log(`App URL:     ${env.OMA_MOCK_URL}`);
  console.log(`User ID:     ${env.OMA_USER_ID}`);
} else {
  console.log(`App URL:     ${env.OMA_API_URL}`);
  console.log(`Canister:    ${env.ICP_CANISTER_ID}`);
  console.log(`Principal:   ${env.USER_PRINCIPAL}`);
}
console.log('');
console.log('Codex config.toml');
console.log('');
console.log(`[mcp_servers.openMemory]
command = "node"
args = [${jsonString(serverPath)}]
env = { ${tomlEnv(env)} }`);
console.log('');
console.log('Claude Code CLI');
console.log('');
console.log(`claude mcp add --scope user --transport stdio ${envFlags} openMemory -- node ${shellQuote(serverPath)}`);
console.log('');
console.log('Claude Desktop JSON');
console.log('');
console.log(JSON.stringify(mcpJson, null, 2));
console.log('');
console.log('Gemini settings.json');
console.log('');
console.log(JSON.stringify(mcpJson, null, 2));
console.log('');
console.log('Checklist');
console.log('1. Set MCP_API_KEY in app/.env to the same value as OMA_API_KEY above.');
console.log('2. Start the Laravel app before using mock mode.');
console.log('3. Restart each MCP client after changing its config.');
console.log('4. Ask the client to call search_memories before answering questions about prior work.');