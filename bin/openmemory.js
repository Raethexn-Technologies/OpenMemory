#!/usr/bin/env node

import { spawnSync } from 'node:child_process';
import { createHash } from 'node:crypto';
import {
  existsSync,
  mkdirSync,
  readdirSync,
  readFileSync,
  statSync,
  writeFileSync,
} from 'node:fs';
import { homedir } from 'node:os';
import {
  basename,
  dirname,
  extname,
  join,
  relative,
  resolve,
  sep,
} from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';

const VERSION = '0.1.0';
const CANDIDATE_SCHEMA = 'openmemory.import_candidate.v1';
const DEFAULT_MAX_BYTES = 256 * 1024;
const TEXT_EXTENSIONS = new Set([
  '.json',
  '.jsonl',
  '.md',
  '.markdown',
  '.txt',
  '.toml',
  '.yaml',
  '.yml',
]);
const SKIP_DIRS = new Set([
  '.dfx',
  '.git',
  '.next',
  '.venv',
  'build',
  'coverage',
  'dist',
  'node_modules',
  'storage',
  'vendor',
  'venv',
]);

const binDir = dirname(fileURLToPath(import.meta.url));
const repoRoot = resolve(binDir, '..');
const appDir = join(repoRoot, 'app');
const mcpDir = join(repoRoot, 'icp', 'mcp-server');
const defaultImportDir = join(appDir, 'storage', 'app', 'openmemory-imports');

export async function main(argv = process.argv.slice(2)) {
  const [command = 'help', ...args] = argv;

  switch (command) {
    case 'doctor':
    case 'status':
      return commandDoctor(args);
    case 'import':
      return commandImport(args);
    case 'setup-clients':
      return commandSetupClients(args);
    case 'help':
    case '--help':
    case '-h':
      printHelp();
      return 0;
    case 'version':
    case '--version':
    case '-v':
      console.log(VERSION);
      return 0;
    default:
      console.error(`Unknown command: ${command}`);
      console.error('');
      printHelp();
      return 1;
  }
}

function printHelp() {
  console.log(`OpenMemory local CLI

Usage:
  openmemory doctor [--app-url URL] [--json]
  openmemory setup-clients [mock|live] [setup options]
  openmemory import [all|codex|claude|gemini|project] [--dry-run] [--output PATH]

Examples:
  node bin/openmemory.js doctor
  node bin/openmemory.js setup-clients mock
  node bin/openmemory.js import all --dry-run
  node bin/openmemory.js import codex --output app/storage/app/openmemory-imports/codex.jsonl

Import options:
  --home PATH          Home directory to scan. Defaults to the current user home.
  --project PATH       Project directory to scan. Defaults to the current directory.
  --root PATH          Alias for --project.
  --output PATH        JSONL manifest path. Defaults under app/storage/app/openmemory-imports.
  --dry-run            Print a summary without writing a manifest.
  --include-content    Include redacted content in dry-run JSON output.
  --private            Mark all candidates private by default.
  --max-bytes N        Skip source files larger than N bytes. Default: ${DEFAULT_MAX_BYTES}.
`);
}

function parseArgs(args) {
  const flags = {};
  const positionals = [];

  for (let i = 0; i < args.length; i++) {
    const arg = args[i];

    if (!arg.startsWith('--')) {
      positionals.push(arg);
      continue;
    }

    const eqIndex = arg.indexOf('=');
    if (eqIndex >= 0) {
      flags[arg.slice(2, eqIndex)] = arg.slice(eqIndex + 1);
      continue;
    }

    const key = arg.slice(2);
    const next = args[i + 1];
    if (next && !next.startsWith('--')) {
      flags[key] = next;
      i++;
    } else {
      flags[key] = true;
    }
  }

  return { flags, positionals };
}

async function commandDoctor(args) {
  const { flags } = parseArgs(args);
  const checks = [];
  const envPath = join(appDir, '.env');
  const env = readDotEnv(envPath);
  const appUrl = trimTrailingSlash(flags['app-url'] || env.APP_URL || 'http://localhost:8080');

  addCommandCheck(checks, 'php available', 'php', ['--version'], true);
  addCommandCheck(checks, 'node available', 'node', ['--version'], true);
  addCommandCheck(checks, 'npm available', 'npm', ['--version'], true);
  addPathCheck(checks, 'Laravel app directory', appDir, true);
  addPathCheck(checks, 'Laravel .env', envPath, true);
  addPathCheck(checks, 'Composer dependencies', join(appDir, 'vendor', 'autoload.php'), true);
  addPathCheck(checks, 'Frontend dependencies', join(appDir, 'node_modules'), true);
  addPathCheck(checks, 'MCP server dependencies', join(mcpDir, 'node_modules'), true);
  addPathCheck(checks, 'MCP server entrypoint', join(mcpDir, 'server.js'), true);
  addPathCheck(checks, 'MCP setup helper', join(mcpDir, 'setup-clients.js'), true);

  if (envPath && existsSync(envPath)) {
    addEnvCheck(checks, env, 'APP_KEY', true, 'Run php artisan key:generate in app/.');
    addEnvCheck(checks, env, 'MCP_API_KEY', true, 'Generate one with openssl rand -hex 32 and use it as OMA_API_KEY in MCP clients.');
    addEnvCheck(checks, env, 'OPENROUTER_API_KEY', true, 'Memory summarization and graph extraction need an LLM key.');

    if ((env.ICP_MOCK_MODE || 'true').toLowerCase() === 'false') {
      addEnvCheck(checks, env, 'ICP_CANISTER_ID', true, 'Set this after dfx deploy or mainnet deployment.');
      addEnvCheck(checks, env, 'ICP_CANISTER_ENDPOINT', true, 'Set this to the local adapter or deployed endpoint.');
    }

    if ((env.DB_CONNECTION || '').toLowerCase() === 'sqlite') {
      addPathCheck(checks, 'SQLite database file', join(appDir, 'database', 'database.sqlite'), true);
    }
  }

  const identityPath = process.env.OMA_IDENTITY_FILE || join(homedir(), '.config', 'openmemory', 'identity.json');
  addPathCheck(checks, 'Optional live ICP identity', identityPath, false);

  await addAppStatusCheck(checks, appUrl);

  const summary = summarizeChecks(checks);
  if (flags.json) {
    console.log(JSON.stringify({ app_url: appUrl, summary, checks }, null, 2));
  } else {
    printDoctor(appUrl, summary, checks);
  }

  return summary.fail > 0 ? 1 : 0;
}

function commandSetupClients(args) {
  const scriptPath = join(mcpDir, 'setup-clients.js');
  if (!existsSync(scriptPath)) {
    console.error(`Missing MCP setup helper: ${scriptPath}`);
    return 1;
  }

  const child = spawnSync(process.execPath, [scriptPath, ...args], {
    cwd: mcpDir,
    stdio: 'inherit',
  });

  if (child.error) {
    console.error(`Failed to run setup-clients: ${child.error.message}`);
    return 1;
  }

  return child.status ?? 0;
}

function commandImport(args) {
  const { flags, positionals } = parseArgs(args);
  const requestedSource = positionals[0] || 'all';
  const sources = requestedSource === 'all'
    ? ['project', 'codex', 'claude', 'gemini']
    : [requestedSource];

  const invalidSources = sources.filter((source) => !['project', 'codex', 'claude', 'gemini'].includes(source));
  if (invalidSources.length > 0) {
    console.error(`Unknown import source: ${invalidSources.join(', ')}`);
    return 1;
  }

  const homeDir = resolve(String(flags.home || homedir()));
  const projectDir = resolve(String(flags.project || flags.root || process.cwd()));
  const maxBytes = parsePositiveInt(flags['max-bytes'], DEFAULT_MAX_BYTES);
  const includeContent = !flags['dry-run'] || Boolean(flags['include-content']);
  const recommendedSensitivity = flags.private ? 'private' : null;
  const { candidates, skipped } = discoverCandidates(sources, {
    homeDir,
    projectDir,
    maxBytes,
    includeContent,
    recommendedSensitivity,
  });

  if (flags.json) {
    console.log(JSON.stringify({
      sources,
      home_dir: homeDir,
      project_dir: projectDir,
      candidate_count: candidates.length,
      skipped,
      candidates,
    }, null, 2));
    return 0;
  }

  printImportSummary(sources, homeDir, projectDir, candidates, skipped);

  if (flags['dry-run']) {
    console.log('');
    console.log('Dry run. No manifest written.');
    return 0;
  }

  const outputPath = resolve(String(flags.output || defaultImportPath()));
  mkdirSync(dirname(outputPath), { recursive: true });
  writeFileSync(
    outputPath,
    candidates.map((candidate) => JSON.stringify(candidate)).join('\n') + (candidates.length > 0 ? '\n' : ''),
    'utf8',
  );

  console.log('');
  console.log(`Wrote ${candidates.length} import candidate(s) to ${outputPath}`);
  console.log('Review this manifest before building the direct ingest step.');
  return 0;
}

export function discoverCandidates(sources, options = {}) {
  const importedAt = options.importedAt || new Date().toISOString();
  const homeDir = resolve(String(options.homeDir || homedir()));
  const projectDir = resolve(String(options.projectDir || process.cwd()));
  const maxBytes = Number.isFinite(options.maxBytes) ? options.maxBytes : DEFAULT_MAX_BYTES;
  const includeContent = options.includeContent ?? true;
  const candidates = [];
  const skipped = [];
  const seenHashes = new Set();

  for (const source of sources) {
    const files = discoverFilesForSource(source, { homeDir, projectDir, maxBytes, skipped });
    for (const file of files) {
      const candidate = candidateFromFile(source, file, {
        homeDir,
        projectDir,
        importedAt,
        includeContent,
        maxBytes,
        recommendedSensitivity: options.recommendedSensitivity,
      });

      if (!candidate) {
        continue;
      }

      if (seenHashes.has(candidate.content_hash)) {
        skipped.push({ path: file, reason: 'duplicate_content' });
        continue;
      }

      seenHashes.add(candidate.content_hash);
      candidates.push(candidate);
    }
  }

  return { candidates, skipped };
}

function discoverFilesForSource(source, options) {
  switch (source) {
    case 'project':
      return findProjectInstructionFiles(options.projectDir);
    case 'codex':
      return collectTextFiles(join(options.homeDir, '.codex', 'memories'), 6, options.maxBytes, options.skipped);
    case 'claude':
      return [
        ...collectExisting([
          join(options.homeDir, '.claude', 'CLAUDE.md'),
          join(options.homeDir, '.claude', 'memory.md'),
        ]),
        ...collectTextFiles(join(options.homeDir, '.claude', 'projects'), 6, options.maxBytes, options.skipped),
      ];
    case 'gemini':
      return collectExisting([
        join(options.homeDir, '.gemini', 'GEMINI.md'),
        join(options.homeDir, '.gemini', 'config', 'GEMINI.md'),
      ]);
    default:
      return [];
  }
}

function findProjectInstructionFiles(projectDir) {
  const names = ['AGENTS.md', 'CLAUDE.md', 'GEMINI.md'];
  const files = [];
  let current = resolve(projectDir);

  while (true) {
    for (const name of names) {
      const candidate = join(current, name);
      if (existsSync(candidate) && statSync(candidate).isFile()) {
        files.push(candidate);
      }
    }

    const parent = dirname(current);
    if (parent === current) {
      break;
    }
    current = parent;
  }

  return uniquePaths(files);
}

function collectExisting(paths) {
  return uniquePaths(paths.filter((path) => existsSync(path) && statSync(path).isFile()));
}

function collectTextFiles(root, maxDepth, maxBytes, skipped, depth = 0) {
  if (!existsSync(root)) {
    return [];
  }

  const stat = statSync(root);
  if (stat.isFile()) {
    return shouldIncludeTextFile(root, stat, maxBytes, skipped) ? [root] : [];
  }
  if (!stat.isDirectory() || depth > maxDepth) {
    return [];
  }

  const files = [];
  const entries = readdirSync(root, { withFileTypes: true })
    .sort((a, b) => a.name.localeCompare(b.name));

  for (const entry of entries) {
    const fullPath = join(root, entry.name);

    if (entry.isDirectory()) {
      if (!SKIP_DIRS.has(entry.name)) {
        files.push(...collectTextFiles(fullPath, maxDepth, maxBytes, skipped, depth + 1));
      }
      continue;
    }

    if (entry.isFile()) {
      const fileStat = statSync(fullPath);
      if (shouldIncludeTextFile(fullPath, fileStat, maxBytes, skipped)) {
        files.push(fullPath);
      }
    }
  }

  return uniquePaths(files);
}

function shouldIncludeTextFile(path, stat, maxBytes, skipped) {
  if (!TEXT_EXTENSIONS.has(extname(path).toLowerCase())) {
    return false;
  }

  if (stat.size > maxBytes) {
    skipped.push({ path, reason: `larger_than_${maxBytes}_bytes` });
    return false;
  }

  return stat.size > 0;
}

function candidateFromFile(source, filePath, options) {
  const raw = readFileSync(filePath, 'utf8');
  const normalized = normalizeContent(raw);
  if (!normalized) {
    return null;
  }

  const redaction = redactForManifest(normalized);
  const content = redaction.text;
  const hash = sha256(content);
  const recommendedSensitivity = options.recommendedSensitivity || defaultSensitivityFor(source);
  const candidate = {
    schema: CANDIDATE_SCHEMA,
    source,
    source_path: resolve(filePath),
    source_label: `${source}:${relativeLabel(baseForSource(source, options), filePath)}`,
    content_hash: hash,
    byte_length: Buffer.byteLength(normalized, 'utf8'),
    redacted_byte_length: Buffer.byteLength(content, 'utf8'),
    recommended_sensitivity: recommendedSensitivity,
    content_redacted: redaction.categories.length > 0,
    redaction_categories: redaction.categories,
    imported_at: options.importedAt,
  };

  if (options.includeContent) {
    candidate.content = content;
  }

  return candidate;
}

function baseForSource(source, options) {
  if (source === 'project') {
    return options.projectDir;
  }
  if (source === 'codex') {
    return join(options.homeDir, '.codex', 'memories');
  }
  if (source === 'claude') {
    return join(options.homeDir, '.claude');
  }
  if (source === 'gemini') {
    return join(options.homeDir, '.gemini');
  }
  return options.homeDir;
}

function defaultSensitivityFor(source) {
  return source === 'project' ? 'public' : 'private';
}

function relativeLabel(base, filePath) {
  const resolvedBase = resolve(base);
  const resolvedFile = resolve(filePath);
  const rel = relative(resolvedBase, resolvedFile) || basename(resolvedFile);
  return rel.split(sep).join('/');
}

function uniquePaths(paths) {
  return Array.from(new Set(paths.map((path) => resolve(path))));
}

function normalizeContent(raw) {
  return String(raw)
    .replace(/\r\n/g, '\n')
    .replace(/\r/g, '\n')
    .trim();
}

export function redactForManifest(input) {
  let text = String(input);
  const categories = new Set();
  const replace = (category, regex, replacement) => {
    const next = text.replace(regex, replacement);
    if (next !== text) {
      categories.add(category);
      text = next;
    }
  };

  replace(
    'private_key',
    /-----BEGIN [A-Z ]*PRIVATE KEY-----[\s\S]*?-----END [A-Z ]*PRIVATE KEY-----/g,
    '[PRIVATE_KEY_REDACTED]',
  );
  replace(
    'jwt',
    /\beyJ[A-Za-z0-9_-]{10,}\.[A-Za-z0-9_-]{10,}\.[A-Za-z0-9_-]{10,}\b/g,
    '[JWT_REDACTED]',
  );
  replace(
    'secret_assignment',
    /(^|[\s"'])([A-Z0-9_]*(?:API[_-]?KEY|TOKEN|SECRET|PASSWORD|PASSWD|PWD|ACCESS[_-]?TOKEN|REFRESH[_-]?TOKEN))\s*[:=]\s*["']?[^"'\s]{8,}["']?/gim,
    '$1$2=[SECRET_REDACTED]',
  );
  replace(
    'email',
    /\b[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}\b/gi,
    '[EMAIL_REDACTED]',
  );

  return { text, categories: Array.from(categories).sort() };
}

function parsePositiveInt(value, fallback) {
  if (value === undefined || value === true) {
    return fallback;
  }

  const parsed = Number.parseInt(String(value), 10);
  return Number.isFinite(parsed) && parsed > 0 ? parsed : fallback;
}

function defaultImportPath() {
  const stamp = new Date().toISOString().replace(/[:.]/g, '-');
  return join(defaultImportDir, `candidates-${stamp}.jsonl`);
}

function readDotEnv(path) {
  if (!existsSync(path)) {
    return {};
  }

  const env = {};
  const lines = readFileSync(path, 'utf8').split(/\r?\n/);
  for (const line of lines) {
    const trimmed = line.trim();
    if (!trimmed || trimmed.startsWith('#')) {
      continue;
    }

    const eqIndex = trimmed.indexOf('=');
    if (eqIndex <= 0) {
      continue;
    }

    const key = trimmed.slice(0, eqIndex).trim();
    let value = trimmed.slice(eqIndex + 1).trim();
    if (
      (value.startsWith('"') && value.endsWith('"'))
      || (value.startsWith("'") && value.endsWith("'"))
    ) {
      value = value.slice(1, -1);
    }
    env[key] = value;
  }

  return env;
}

function addCommandCheck(checks, name, command, args, required) {
  const result = spawnSync(command, args, {
    encoding: 'utf8',
    shell: process.platform === 'win32',
  });

  if (result.error || result.status !== 0) {
    checks.push({
      name,
      status: required ? 'fail' : 'warn',
      detail: result.error ? result.error.message : (result.stderr || 'command returned non-zero status').trim(),
    });
    return;
  }

  checks.push({
    name,
    status: 'pass',
    detail: firstLine(result.stdout || result.stderr),
  });
}

function addPathCheck(checks, name, path, required) {
  if (existsSync(path)) {
    checks.push({ name, status: 'pass', detail: path });
    return;
  }

  checks.push({
    name,
    status: required ? 'fail' : 'warn',
    detail: `${path} not found`,
  });
}

function addEnvCheck(checks, env, key, required, fix) {
  if (env[key]) {
    checks.push({ name: `${key} configured`, status: 'pass', detail: 'set' });
    return;
  }

  checks.push({
    name: `${key} configured`,
    status: required ? 'fail' : 'warn',
    detail: fix,
  });
}

async function addAppStatusCheck(checks, appUrl) {
  if (typeof fetch !== 'function') {
    checks.push({
      name: 'Laravel status endpoint',
      status: 'warn',
      detail: 'fetch is unavailable in this Node runtime',
    });
    return;
  }

  const controller = new AbortController();
  const timer = setTimeout(() => controller.abort(), 1500);

  try {
    const response = await fetch(`${appUrl}/api/status`, { signal: controller.signal });
    if (!response.ok) {
      checks.push({
        name: 'Laravel status endpoint',
        status: 'warn',
        detail: `HTTP ${response.status} from ${appUrl}/api/status`,
      });
      return;
    }

    const data = await response.json();
    checks.push({
      name: 'Laravel status endpoint',
      status: 'pass',
      detail: `${data.mode || 'unknown'} mode at ${appUrl}`,
    });
  } catch (error) {
    checks.push({
      name: 'Laravel status endpoint',
      status: 'warn',
      detail: `not reachable at ${appUrl}/api/status (${error.message})`,
    });
  } finally {
    clearTimeout(timer);
  }
}

function summarizeChecks(checks) {
  return checks.reduce(
    (summary, check) => {
      summary[check.status] += 1;
      return summary;
    },
    { pass: 0, warn: 0, fail: 0 },
  );
}

function printDoctor(appUrl, summary, checks) {
  console.log('OpenMemory doctor');
  console.log(`App URL: ${appUrl}`);
  console.log('');

  for (const check of checks) {
    console.log(`${check.status.toUpperCase().padEnd(4)} ${check.name}`);
    if (check.detail) {
      console.log(`     ${check.detail}`);
    }
  }

  console.log('');
  console.log(`Summary: ${summary.pass} pass, ${summary.warn} warn, ${summary.fail} fail`);
}

function printImportSummary(sources, homeDir, projectDir, candidates, skipped) {
  console.log('OpenMemory import candidates');
  console.log(`Sources:     ${sources.join(', ')}`);
  console.log(`Home dir:    ${homeDir}`);
  console.log(`Project dir: ${projectDir}`);
  console.log('');
  console.log(`Candidates:  ${candidates.length}`);
  console.log(`Skipped:     ${skipped.length}`);

  for (const candidate of candidates) {
    const redacted = candidate.content_redacted ? ' redacted' : '';
    console.log(`- ${candidate.source_label} (${candidate.recommended_sensitivity}${redacted})`);
  }

  if (skipped.length > 0) {
    console.log('');
    console.log('Skipped files:');
    for (const item of skipped.slice(0, 20)) {
      console.log(`- ${item.path}: ${item.reason}`);
    }
    if (skipped.length > 20) {
      console.log(`- ... ${skipped.length - 20} more`);
    }
  }
}

function trimTrailingSlash(value) {
  return String(value || '').replace(/\/$/, '');
}

function firstLine(text) {
  return String(text || '').split(/\r?\n/).find(Boolean) || 'ok';
}

function sha256(value) {
  return createHash('sha256').update(value).digest('hex');
}

if (process.argv[1] && import.meta.url === pathToFileURL(resolve(process.argv[1])).href) {
  main().then((code) => {
    process.exitCode = code;
  }).catch((error) => {
    console.error(error.message);
    process.exitCode = 1;
  });
}
