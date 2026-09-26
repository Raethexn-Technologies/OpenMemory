import { requestJson, SafeError } from './http.mjs';

export const SOURCES = ['native_memory', 'history', 'github'];

export function contextRequest(input) {
  if (typeof input.question !== 'string' || !input.question.trim() || input.question.length > 500
      || !Array.isArray(input.sources) || input.sources.length < 1 || input.sources.length > 3
      || new Set(input.sources).size !== input.sources.length || input.sources.some(s => !SOURCES.includes(s))) {
    throw new SafeError('invalid_question_or_sources');
  }
  const request = { version: 'context-request-v1', query: input.question, sources: input.sources, limit: 12, per_source_limit: 5 };
  if (input.temporal === true) {
    if (!input.sources.includes('history') || !input.sources.includes('github') || input.from || input.to) throw new SafeError('invalid_temporal_plan');
    // This is the one explicit strategy supported by the current public API.
    request.temporal = { from_source: 'history', to_source: 'github', days: 2 };
  } else if (input.from || input.to) {
    if (!/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/.test(input.from ?? '')
        || !/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/.test(input.to ?? '')) throw new SafeError('use_explicit_utc_dates');
    request.from = input.from;
    request.to = input.to;
  }
  return request;
}

export function validateBundle(bundle) {
  if (bundle?.version !== 'context-bundle-v1' || !Array.isArray(bundle.fragments) || bundle.fragments.length > 20
      || !bundle.sources || typeof bundle.sources !== 'object' || Array.isArray(bundle.sources)
      || typeof bundle.incomplete !== 'boolean' || typeof bundle.truncated !== 'boolean'
      || bundle.disclosure?.audience !== 'application') throw new SafeError('unsupported_context_bundle', 502);
  for (const fragment of bundle.fragments) {
    if (!SOURCES.includes(fragment.source) || typeof fragment.content !== 'string'
        || [...fragment.content].length > 600 || fragment.trust !== 'untrusted_data'
        || fragment.classification !== 'private' || typeof fragment.resource_id !== 'string'
        || !fragment.provenance || typeof fragment.provenance !== 'object') throw new SafeError('invalid_context_fragment', 502);
  }
  return bundle;
}

export class OpenMemoryClient {
  constructor({ baseUrl, token, fetchImpl = fetch }) {
    const url = new URL(baseUrl);
    if (!['127.0.0.1', 'localhost', '[::1]'].includes(url.hostname) || !['http:', 'https:'].includes(url.protocol)
        || url.username || url.password || url.search || url.hash || url.pathname !== '/') throw new SafeError('core_must_be_local');
    if (!/^omctx_[a-f0-9]{64}$/.test(token ?? '')) throw new SafeError('configure_application_token');
    this.base = url.origin;
    this.token = token;
    this.fetchImpl = fetchImpl;
  }

  async call(path, body) {
    return requestJson(this.base + path, {
      method: body ? 'POST' : 'GET',
      headers: { Authorization: `Bearer ${this.token}`, Accept: 'application/json', ...(body ? { 'Content-Type': 'application/json' } : {}) },
      ...(body ? { body: JSON.stringify(body) } : {}),
    }, { fetchImpl: this.fetchImpl });
  }

  async permissions() {
    const { data } = await this.call('/api/app/context/permissions');
    if (data?.version !== 'context-permissions-v1' || !Number.isInteger(data.grant_revision) || !data.sources) throw new SafeError('unsupported_permissions', 502);
    return data;
  }

  async resolve(request) {
    const started = performance.now();
    const response = await this.call('/api/app/context/resolve', request);
    const number = name => response.headers.has(name) ? Number(response.headers.get(name)) : null;
    return { bundle: validateBundle(response.data), metrics: {
      resolver_http_ms: Math.round((performance.now() - started) * 100) / 100,
      resolver_ms: number('X-Context-Resolver-Ms'), sql_queries: number('X-Context-Sql-Queries'),
      external_api_requests: number('X-Context-Provider-Requests'), bundle_bytes: response.bytes,
      sources_actually_queried: response.headers.has('X-Context-Sources-Queried')
        ? response.headers.get('X-Context-Sources-Queried').split(',').filter(Boolean) : null,
    } };
  }
}

const INTERNAL_PROVENANCE = new Set(['connection_id', 'source_resource_id', 'external_account_id', 'external_account_login']);
export function evidenceView(bundle) {
  return bundle.fragments.map((fragment, index) => ({ id: `E${index + 1}`, source: fragment.source,
    resource_id: fragment.resource_id, content: fragment.content, retrieval: fragment.retrieval,
    excerpt_truncated: fragment.excerpt_truncated, redacted: fragment.redacted,
    provenance: Object.fromEntries(Object.entries(fragment.provenance).filter(([key]) => !INTERNAL_PROVENANCE.has(key))) }));
}

export function inspectBundle(bundle) {
  return { version: bundle.version, request_id: bundle.request_id, resolved_at: bundle.resolved_at,
    incomplete: bundle.incomplete, truncated: bundle.truncated, trust: bundle.trust,
    fragments: evidenceView(bundle), sources: Object.fromEntries(Object.entries(bundle.sources).map(([source, outcome]) => {
      const coverage = outcome.coverage ? { ...outcome.coverage } : null;
      if (coverage?.resources) coverage.resources = Object.values(coverage.resources);
      return [source, { ...outcome, coverage }];
    })), model_permission: bundle.disclosure.model ?? null };
}
