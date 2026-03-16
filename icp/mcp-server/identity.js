/**
 * identity.js — Load the portable Ed25519 identity for MCP write calls.
 *
 * The identity file is a JSON object written by setup-identity.js:
 *   { version: 1, created_at: <ISO>, principal: <text>, secret_key_hex: <64-char hex> }
 *
 * secret_key_hex is the 32-byte private seed encoded as 64 hex characters.
 * Ed25519KeyIdentity.generate(seed) reconstructs the full keypair from the seed.
 *
 * Returns { identity, principal } or null when no identity file is found.
 */

import { Ed25519KeyIdentity } from '@dfinity/identity';
import { Principal } from '@dfinity/principal';
import { readFileSync, existsSync } from 'node:fs';
import { homedir } from 'node:os';
import { join } from 'node:path';

const DEFAULT_PATH = join(homedir(), '.config', 'openmemory', 'identity.json');

export function loadIdentity() {
  const filePath = process.env.OMA_IDENTITY_FILE || DEFAULT_PATH;

  if (!existsSync(filePath)) {
    return null;
  }

  let data;
  try {
    data = JSON.parse(readFileSync(filePath, 'utf8'));
  } catch (err) {
    console.error(`[OMA identity] Failed to parse identity file at ${filePath}: ${err.message}`);
    return null;
  }

  if (!data.secret_key_hex || typeof data.secret_key_hex !== 'string' || data.secret_key_hex.length !== 64) {
    console.error(`[OMA identity] identity.json is malformed — secret_key_hex must be a 64-character hex string`);
    return null;
  }

  const seed = Buffer.from(data.secret_key_hex, 'hex');
  if (seed.length !== 32) {
    console.error(`[OMA identity] Decoded seed is not 32 bytes`);
    return null;
  }

  const identity = Ed25519KeyIdentity.generate(seed);

  // Verify reconstructed principal matches stored principal (detects corruption).
  const reconstructed = identity.getPrincipal().toText();
  if (data.principal && reconstructed !== data.principal) {
    console.error(`[OMA identity] Principal mismatch — file may be corrupted. Stored: ${data.principal}, Reconstructed: ${reconstructed}`);
    return null;
  }

  return { identity, principal: reconstructed };
}
