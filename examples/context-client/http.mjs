export class SafeError extends Error {
  constructor(code, status = 400) { super(code); this.code = code; this.status = status; }
}

export async function readBounded(stream, limit) {
  const parts = [];
  let bytes = 0;
  for await (const part of stream) {
    const chunk = Buffer.from(part);
    bytes += chunk.length;
    if (bytes > limit) throw new SafeError('response_too_large', 502);
    parts.push(chunk);
  }
  return Buffer.concat(parts).toString('utf8');
}

export async function requestJson(url, options, { fetchImpl = fetch, limit = 32768, timeout = 35000 } = {}) {
  try {
    const response = await fetchImpl(url, { ...options, redirect: 'error', signal: AbortSignal.timeout(timeout) });
    if (!response.ok) {
      await response.body?.cancel();
      throw new SafeError(`upstream_http_${response.status}`, response.status === 401 || response.status === 403 ? response.status : 502);
    }
    const raw = await readBounded(response.body, limit);
    return { data: JSON.parse(raw), bytes: Buffer.byteLength(raw), headers: response.headers };
  } catch (error) {
    if (error instanceof SafeError) throw error;
    throw new SafeError('upstream_unavailable_or_invalid', 502);
  }
}
