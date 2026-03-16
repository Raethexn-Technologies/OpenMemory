/**
 * setup-identity.js — Generate a portable Ed25519 identity for the MCP server.
 *
 * Run once from icp/mcp-server/ after npm install:
 *   node setup-identity.js
 *
 * Creates ~/.config/openmemory/identity.json with:
 *   { version: 1, created_at, principal, secret_key_hex }
 *
 * The file is written mode 0o600 (owner read/write only).
 * Subsequent runs exit without overwriting — the key is stable by design.
 * All CLI tools (Claude Code, Gemini, Codex) share this single identity file
 * through the MCP server so all writes carry the same principal.
 *
 * To regenerate: delete the file and run this script again.
 * Warning: regenerating changes your principal — existing canister records
 * will no longer be readable as "your" memories in live ICP mode.
 */

import { Ed25519KeyIdentity } from '@dfinity/identity';
import { randomBytes } from 'node:crypto';
import { existsSync, mkdirSync, writeFileSync } from 'node:fs';
import { homedir } from 'node:os';
import { join, dirname } from 'node:path';

const DEFAULT_PATH = join(homedir(), '.config', 'openmemory', 'identity.json');
const filePath = process.env.OMA_IDENTITY_FILE || DEFAULT_PATH;

if (existsSync(filePath)) {
  console.log(`Identity file already exists at: ${filePath}`);
  console.log('To regenerate, delete the file and run this script again.');
  process.exit(0);
}

// Generate a cryptographically random 32-byte seed.
const seed = randomBytes(32);
const identity = Ed25519KeyIdentity.generate(seed);
const principal = identity.getPrincipal().toText();

const record = {
  version:        1,
  created_at:     new Date().toISOString(),
  principal,
  secret_key_hex: seed.toString('hex'),
};

mkdirSync(dirname(filePath), { recursive: true });
writeFileSync(filePath, JSON.stringify(record, null, 2), { mode: 0o600, encoding: 'utf8' });

console.log(`Identity created at: ${filePath}`);
console.log(`Principal:          ${principal}`);
console.log('');
console.log('Keep this file private — it controls who owns your memories in the ICP canister.');
console.log('Back it up somewhere safe. If you lose it you cannot reclaim canister-signed records.');
