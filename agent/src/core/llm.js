/**
 * LLM client for OpenMemory Agent.
 *
 * Supports the Anthropic SDK directly or proxied through OpenRouter.
 * If OPENROUTER_API_KEY is set, the Anthropic client is pointed at
 * https://openrouter.ai/api/v1 with the OpenRouter key. Otherwise
 * ANTHROPIC_API_KEY is used against the standard Anthropic endpoint.
 *
 * AGENT_MODEL controls the model name passed to the API.
 * Direct Anthropic example:  claude-sonnet-4-6
 * OpenRouter example:        anthropic/claude-sonnet-4-6
 */

import Anthropic from '@anthropic-ai/sdk';

const MODEL = process.env.AGENT_MODEL || 'claude-sonnet-4-6';

let client;

if (process.env.OPENROUTER_API_KEY) {
  client = new Anthropic({
    baseURL: 'https://openrouter.ai/api/v1',
    apiKey: process.env.OPENROUTER_API_KEY,
    defaultHeaders: {
      'HTTP-Referer': 'https://github.com/raethexn-technologies/openmemory',
    },
  });
} else {
  if (!process.env.ANTHROPIC_API_KEY) {
    throw new Error(
      'Set ANTHROPIC_API_KEY or OPENROUTER_API_KEY in your .env file before starting the agent.'
    );
  }
  client = new Anthropic({
    apiKey: process.env.ANTHROPIC_API_KEY,
  });
}

/**
 * Send a chat request to the configured LLM.
 *
 * @param {Array}  messages - Anthropic messages array ({ role, content })
 * @param {Array}  tools    - Anthropic tool definitions array (optional)
 * @param {string} system   - System prompt string (optional)
 * @returns {Promise<import('@anthropic-ai/sdk').Message>}
 */
export async function chat(messages, tools = [], system = '') {
  const params = {
    model: MODEL,
    max_tokens: 8096,
    messages,
  };

  if (system) {
    params.system = system;
  }

  if (tools.length > 0) {
    params.tools = tools;
  }

  return client.messages.create(params);
}
