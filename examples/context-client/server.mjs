import http from 'node:http';
import { randomBytes } from 'node:crypto';
import { readFile } from 'node:fs/promises';
import { pathToFileURL } from 'node:url';
import { OpenMemoryClient, contextRequest, inspectBundle } from './openmemory.mjs';
import { answerQuestion, DESTINATION } from './model.mjs';
import { readBounded, SafeError } from './http.mjs';

const TTL = 10 * 60 * 1000;
const ANSWER_TTL = 5 * 60 * 1000;
const choice = value => ['yes', 'no', 'not_reviewed'].includes(value) ? value : 'not_reviewed';

export function createReferenceServer({ core, model, answer = answerQuestion }) {
  const sessions = new Map();
  let active = false;
  const cleanup = setInterval(() => {
    for (const [id, session] of sessions) if (Date.now() - session.touched > TTL) sessions.delete(id);
  }, 30000).unref();
  const server = http.createServer(async (request, response) => {
    const origin = `http://127.0.0.1:${server.address().port}`;
    const send = (status, value) => {
      response.writeHead(status, { 'Content-Type': 'application/json; charset=utf-8' });
      response.end(JSON.stringify(value));
    };
    response.setHeader('Cache-Control', 'no-store');
    response.setHeader('X-Content-Type-Options', 'nosniff');
    response.setHeader('Referrer-Policy', 'no-referrer');
    response.setHeader('Content-Security-Policy', "default-src 'none'; script-src 'self'; style-src 'self'; connect-src 'self'; base-uri 'none'; form-action 'self'; frame-ancestors 'none'");
    try {
      if (request.headers.host !== `127.0.0.1:${server.address().port}`
          || (request.headers.origin && request.headers.origin !== origin)
          || request.headers['sec-fetch-site'] === 'cross-site') throw new SafeError('local_origin_required', 403);
      if (request.method === 'GET' && ['/', '/ui.js', '/style.css'].includes(request.url)) {
        const file = { '/': ['index.html', 'text/html'], '/ui.js': ['ui.js', 'text/javascript'], '/style.css': ['style.css', 'text/css'] }[request.url];
        response.writeHead(200, { 'Content-Type': `${file[1]}; charset=utf-8` });
        response.end(await readFile(new URL(file[0], import.meta.url)));
        return;
      }
      let id = /(?:^|;\s*)om_reference=([a-f0-9]{64})(?:;|$)/.exec(request.headers.cookie ?? '')?.[1];
      let session = sessions.get(id);
      if (session && Date.now() - session.touched > TTL) { sessions.delete(id); session = null; }
      if (request.method === 'GET' && request.url === '/api/session') {
        if (!session) {
          if (sessions.size >= 4) throw new SafeError('too_many_local_sessions', 429);
          id = randomBytes(32).toString('hex');
          session = { csrf: randomBytes(32).toString('hex'), touched: Date.now(), current: null, records: [], generation: 0 };
          sessions.set(id, session);
          response.setHeader('Set-Cookie', `om_reference=${id}; HttpOnly; SameSite=Strict; Path=/; Max-Age=600`);
        }
        send(200, { csrf: session.csrf, destination: DESTINATION, model: model.model || null, reasoning: 'medium',
          model_configured: Boolean(model.key && model.model === 'gpt-5.4'), retention_minutes: 10 });
        return;
      }
      if (!session || request.headers['x-reference-csrf'] !== session.csrf || request.headers.origin !== origin
          || request.method !== 'POST' || request.headers['content-type'] !== 'application/json') throw new SafeError('local_session_required', 403);
      session.touched = Date.now();
      let input;
      try { input = JSON.parse(await readBounded(request, 8192)); } catch { throw new SafeError('invalid_request'); }
      if (!input || typeof input !== 'object' || Array.isArray(input)) throw new SafeError('invalid_request');
      if (request.url === '/api/clear') {
        session.generation++;
        session.current = null;
        session.records = [];
        send(200, { cleared: true });
        return;
      }
      if (request.url === '/api/evaluate') {
        if (!session.current || input.request_id !== session.current.bundle.request_id) throw new SafeError('resolve_context_first');
        session.current.record.human = { relevant_evidence: choice(input.relevant_evidence),
          irrelevant_dominated: choice(input.irrelevant_dominated), material_benefit: choice(input.material_benefit) };
        send(200, { recorded: true });
        return;
      }
      if (request.url === '/api/measurements') {
        send(200, { version: 'openmemory-validation-v1', observer: 'reference_application', records: session.records });
        return;
      }
      if (active) throw new SafeError('request_in_progress', 409);
      active = true;
      try {
        if (request.url === '/api/resolve') {
          session.current = null;
          const generation = session.generation;
          const query = contextRequest(input);
          const permissions = await core.permissions();
          const { bundle, metrics } = await core.resolve(query);
          if (session.generation !== generation) throw new SafeError('context_was_cleared', 409);
          const record = { query_id: /^Q(?:10|[1-9])$/.test(input.query_id ?? '') ? input.query_id : 'unlabelled',
            observed_at: new Date().toISOString(), request_id: bundle.request_id, requested_sources: query.sources,
            authorized_source_grants: Object.entries(permissions.sources).filter(([, value]) => value.retrieval && value.disclosure).map(([key]) => key),
            outcomes: Object.fromEntries(Object.entries(bundle.sources).map(([source, outcome]) => [source, outcome.status])),
            fragments_returned: bundle.fragments.length, incomplete: bundle.incomplete, truncated: bundle.truncated,
            ...metrics, human: { relevant_evidence: 'not_reviewed', irrelevant_dominated: 'not_reviewed', material_benefit: 'not_reviewed' } };
          session.records.push(record);
          if (session.records.length > 20) session.records.shift();
          session.current = { question: query.query, bundle, created: Date.now(), record };
          send(200, { bundle: inspectBundle(bundle), metrics: record });
        } else if (request.url === '/api/answer') {
          const current = session.current;
          if (!current || input.request_id !== current.bundle.request_id || input.consent !== true || typeof input.baseline !== 'boolean'
              || Date.now() - current.created > ANSWER_TTL) throw new SafeError('inspect_fresh_context_and_confirm');
          const permissions = await core.permissions();
          const result = await answer({ question: current.question, bundle: current.bundle, permissions, config: model, baseline: input.baseline });
          // Clearing the screen during a slow call prevents restoring its old payload.
          if (session.current !== current) throw new SafeError('context_was_cleared', 409);
          current.record[input.baseline ? 'baseline_model' : 'context_model'] = result.metrics;
          send(200, result);
        } else throw new SafeError('not_found', 404);
      } finally { active = false; }
    } catch (error) {
      send(error instanceof SafeError ? error.status : 500, { error: error instanceof SafeError ? error.code : 'operation_failed' });
    }
  });
  server.on('close', () => { clearInterval(cleanup); sessions.clear(); });
  server.requestTimeout = 10000;
  server.headersTimeout = 10000;
  return server;
}

if (process.argv[1] && import.meta.url === pathToFileURL(process.argv[1]).href) {
  try {
    const port = Number(process.env.REFERENCE_PORT || 8787);
    if (!Number.isInteger(port) || port < 1024 || port > 65535) throw new SafeError('invalid_port');
    const core = new OpenMemoryClient({ baseUrl: process.env.OPENMEMORY_URL || 'http://127.0.0.1:8000', token: process.env.OPENMEMORY_APPLICATION_TOKEN });
    const server = createReferenceServer({ core, model: { key: process.env.OPENAI_API_KEY, model: process.env.OPENAI_MODEL } });
    server.on('error', () => { process.stderr.write('Reference client could not start. Check the local port.\n'); process.exitCode = 1; });
    server.listen(port, '127.0.0.1', () => process.stdout.write(`Reference client: http://127.0.0.1:${port}\n`));
  } catch { process.stderr.write('Reference client configuration is incomplete or invalid. Check the local environment file.\n'); process.exitCode = 1; }
}
