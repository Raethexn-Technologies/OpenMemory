/**
 * OpenMemory API client.
 *
 * Reads from and writes to the Laravel OpenMemory instance configured
 * by OMA_API_URL, OMA_API_KEY, and OMA_USER_ID. Uses the native fetch
 * API available in Node.js 20 and later.
 */

const OMA_API_URL = (process.env.OMA_API_URL || 'http://localhost:8000').replace(/\/$/, '');
const OMA_API_KEY = process.env.OMA_API_KEY || '';
const OMA_USER_ID = process.env.OMA_USER_ID || '';

/**
 * Retrieve recent memories for the configured user.
 *
 * Calls GET /memory/refresh with the API key header. Returns the parsed
 * JSON array of memory records, or an empty array if the response is not
 * a JSON array.
 *
 * @returns {Promise<Array>}
 */
export async function retrieve() {
  const res = await fetch(`${OMA_API_URL}/memory/refresh`, {
    method: 'GET',
    headers: {
      'X-OMA-API-Key': OMA_API_KEY,
      'Accept': 'application/json',
    },
  });

  if (!res.ok) {
    const body = await res.text().catch(() => '');
    throw new Error(`Memory retrieve failed (HTTP ${res.status}): ${body}`);
  }

  const data = await res.json();
  return Array.isArray(data) ? data : [];
}

/**
 * Store a new memory record.
 *
 * Calls POST /mcp/store with the API key header and a JSON body containing
 * the content, sensitivity, and user_id. Returns the parsed response JSON.
 *
 * @param {string} content     - The memory text to store.
 * @param {string} sensitivity - 'public', 'private', or 'sensitive'. Defaults to 'public'.
 * @returns {Promise<object>}
 */
export async function store(content, sensitivity = 'public') {
  if (!OMA_USER_ID) {
    throw new Error('OMA_USER_ID is not set. Configure it in .env before storing memories.');
  }

  const res = await fetch(`${OMA_API_URL}/mcp/store`, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'X-OMA-API-Key': OMA_API_KEY,
      'Accept': 'application/json',
    },
    body: JSON.stringify({
      content,
      sensitivity,
      user_id: OMA_USER_ID,
    }),
  });

  if (!res.ok) {
    const body = await res.text().catch(() => '');
    throw new Error(`Memory store failed (HTTP ${res.status}): ${body}`);
  }

  return res.json();
}
