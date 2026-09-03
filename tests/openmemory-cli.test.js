import test from 'node:test';
import assert from 'node:assert/strict';
import { mkdtempSync, mkdirSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

import { discoverCandidates, redactForManifest } from '../bin/openmemory.js';

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
