import test from 'node:test';
import assert from 'node:assert/strict';
import { mkdtempSync, mkdirSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

import { discoverCandidates, redactForManifest, main } from '../bin/openmemory.js';

const NEWLINE = '\n';

const captureStderr = async (fn) => {
  const original = console.error;
  const lines = [];
  console.error = (...args) => lines.push(args.join(' '));

  try {
    return { code: await fn(), stderr: lines.join(NEWLINE) };
  } finally {
    console.error = original;
  }
};

test('redactForManifest removes obvious secrets and emails', () => {
  const input = [
    'Contact owner@example.com for access.',
    'OPENROUTER_API_KEY=sk-openmemorytestsecret',
    'token: abcdefghijklmnopqrstuvwxyz',
  ].join('\n');

  const result = redactForManifest(input);

  assert.equal(result.text.includes('owner@example.com'), false);
  assert.equal(result.text.includes('sk-openmemorytestsecret'), false);
  assert.equal(result.text.includes('abcdefghijklmnopqrstuvwxyz'), false);
  assert.deepEqual(result.categories, ['email', 'secret_assignment']);
});

test('discoverCandidates finds project and provider memory files', () => {
  const root = mkdtempSync(join(tmpdir(), 'openmemory-cli-'));
  const home = join(root, 'home');
  const project = join(root, 'project');

  try {
    mkdirSync(join(home, '.codex', 'memories'), { recursive: true });
    mkdirSync(join(home, '.claude', 'projects', 'repo'), { recursive: true });
    mkdirSync(join(home, '.gemini'), { recursive: true });
    mkdirSync(project, { recursive: true });

    writeFileSync(join(project, 'AGENTS.md'), 'Project rule: keep changes narrow.\n');
    writeFileSync(join(home, '.codex', 'memories', 'summary.md'), 'User prefers query_lexical retrieval.\n');
    writeFileSync(join(home, '.claude', 'projects', 'repo', 'memory.md'), 'User prefers query_lexical retrieval.\n');
    writeFileSync(join(home, '.gemini', 'GEMINI.md'), 'Gemini should check OpenMemory before answering.\n');

    const { candidates, skipped } = discoverCandidates(['project', 'codex', 'claude', 'gemini'], {
      homeDir: home,
      projectDir: project,
      includeContent: true,
      importedAt: '2026-09-03T00:00:00.000Z',
    });

    assert.equal(candidates.length, 3);
    assert.equal(skipped.some((item) => item.reason === 'duplicate_content'), true);
    assert.equal(candidates.find((item) => item.source === 'project').recommended_sensitivity, 'public');
    assert.equal(candidates.find((item) => item.source === 'codex').recommended_sensitivity, 'private');
    assert.equal(candidates.some((item) => item.source_label === 'gemini:GEMINI.md'), true);
  } finally {
    rmSync(root, { recursive: true, force: true });
  }
});

test('import-archive without a path explains what a path should be', async () => {
  const { code, stderr } = await captureStderr(() => main(['import-archive']));

  assert.equal(code, 1);
  assert.match(stderr, /import-archive PATH/);
  assert.match(stderr, /export ZIP, an extracted folder, or a single conversations\.json/);
});

test('an unknown command prints help and fails', async () => {
  const original = console.log;
  const out = [];
  console.log = (...args) => out.push(args.join(' '));

  try {
    const { code } = await captureStderr(() => main(['not-a-command']));

    assert.equal(code, 1);
    assert.match(out.join(NEWLINE), /import-archive/);
  } finally {
    console.log = original;
  }
});
