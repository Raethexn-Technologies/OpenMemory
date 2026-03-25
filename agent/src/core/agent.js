/**
 * Agent — the main orchestrator for the OpenMemory autonomous coding agent.
 *
 * Handles two modes:
 *   research  — retrieves context, asks Claude for concrete next-step options,
 *               presents them to the user, then executes the selected one.
 *   execution — runs a direct agentic tool-use loop until the task is complete.
 *
 * The handleMessage method classifies intent from the incoming text and
 * routes to the appropriate mode.
 */

import { chat } from './llm.js';
import { TOOL_DEFINITIONS, executeTool } from './tools.js';
import { retrieve, store } from './memory.js';

const SYSTEM_PROMPT = `You are the OpenMemory coding agent. You have access to tools that let you read and write repository files, run shell commands, and interact with GitHub. The repository you are working on is at the path configured in REPO_PATH.

Work methodically. Read relevant files before writing code. After making changes, verify them. Always create a new branch before committing. Commit incrementally with descriptive messages. Open a pull request when the task is complete.

Never commit directly to main. Never use --force on any git command. Never delete files without reading them first. Never merge pull requests; leave that to the repository maintainer.

When proposing options to the user, return exactly this JSON structure and nothing else:
{"options": [{"label": "...", "description": "..."}, ...]}

When a task is complete, end your response with a summary of what was done.`;

// Maximum tool-use iterations in a single execute() call.
const MAX_ITERATIONS = 40;

// Words that suggest the user wants research/planning rather than direct execution.
const RESEARCH_KEYWORDS = [
  'what', 'next', 'suggest', 'plan', 'research', 'ideas', 'options',
  'propose', 'recommend', 'think', 'should', 'could', 'might',
];

export class Agent {
  /**
   * Retrieve git history and memory context, then ask Claude to propose
   * 2-5 concrete next steps as a JSON options array.
   *
   * @param {string} context - The user's research question or topic.
   * @returns {Promise<Array<{label: string, description: string}>>}
   */
  async research(context) {
    let gitLog = '';
    try {
      gitLog = await executeTool('run_command', { command: 'git log --oneline -20' });
    } catch {
      gitLog = '(git log unavailable)';
    }

    let memories = [];
    try {
      memories = await retrieve();
    } catch {
      memories = [];
    }

    const memoryText = memories.length > 0
      ? memories.map((m) => `- ${m.content ?? JSON.stringify(m)}`).join('\n')
      : '(no memories stored yet)';

    const prompt = [
      'Recent git history:',
      gitLog || '(no commits yet)',
      '',
      'Stored memory context:',
      memoryText,
      '',
      `User context: ${context}`,
      '',
      'Propose 2 to 5 concrete next steps for this project. Each step should be specific enough to implement in a single focused session.',
      'Return only the JSON structure specified in the system prompt.',
    ].join('\n');

    const response = await chat(
      [{ role: 'user', content: prompt }],
      [],
      SYSTEM_PROMPT
    );

    const text = response.content
      .filter((block) => block.type === 'text')
      .map((block) => block.text)
      .join('');

    // Extract JSON from the response. Claude may wrap it in a code fence.
    const jsonMatch = text.match(/\{[\s\S]*\}/);
    if (!jsonMatch) {
      throw new Error(`Research response did not contain valid JSON. Response: ${text}`);
    }

    const parsed = JSON.parse(jsonMatch[0]);

    if (!Array.isArray(parsed.options) || parsed.options.length === 0) {
      throw new Error('Research response contained no options array.');
    }

    return parsed.options;
  }

  /**
   * Run the agentic tool-use loop for a given task.
   * Iterates until Claude returns stop_reason "end_turn" or MAX_ITERATIONS is reached.
   *
   * @param {string}   task       - The task description to pass to Claude.
   * @param {Function} onProgress - Called with the tool name each time a tool is used.
   * @returns {Promise<string>}   - The final text response from Claude.
   */
  async execute(task, onProgress) {
    const messages = [{ role: 'user', content: task }];

    for (let iteration = 0; iteration < MAX_ITERATIONS; iteration++) {
      const response = await chat(messages, TOOL_DEFINITIONS, SYSTEM_PROMPT);

      // Append the assistant turn.
      messages.push({ role: 'assistant', content: response.content });

      if (response.stop_reason === 'end_turn') {
        const text = response.content
          .filter((block) => block.type === 'text')
          .map((block) => block.text)
          .join('');
        return text || 'Task completed.';
      }

      // Collect all tool_use blocks and execute them.
      const toolUseBlocks = response.content.filter((block) => block.type === 'tool_use');

      if (toolUseBlocks.length === 0) {
        // No tools and not end_turn — treat as done to avoid an infinite loop.
        const text = response.content
          .filter((block) => block.type === 'text')
          .map((block) => block.text)
          .join('');
        return text || 'Task completed.';
      }

      const toolResultContent = [];

      for (const toolUse of toolUseBlocks) {
        if (typeof onProgress === 'function') {
          onProgress(toolUse.name);
        }

        const result = await executeTool(toolUse.name, toolUse.input);

        toolResultContent.push({
          type: 'tool_result',
          tool_use_id: toolUse.id,
          content: typeof result === 'string' ? result : JSON.stringify(result, null, 2),
        });
      }

      // Append the tool results as a user turn.
      messages.push({ role: 'user', content: toolResultContent });
    }

    return 'Task completed. (Iteration limit reached — the agent stopped after 40 tool-use cycles.)';
  }

  /**
   * Classify intent and route to research or execution mode.
   *
   * @param {object} options
   * @param {string}   options.text        - The incoming message text.
   * @param {string}   options.userId      - The platform user ID.
   * @param {Function} options.send        - Async function to send a reply.
   * @param {Function} options.showOptions - Async function to present selectable options.
   * @returns {Promise<void>}
   */
  async handleMessage({ text, userId, send, showOptions }) {
    try {
      const lower = text.toLowerCase();
      const isResearch = RESEARCH_KEYWORDS.some((kw) => lower.includes(kw));

      if (isResearch) {
        await send('Researching the project state and generating options...');

        let options;
        try {
          options = await this.research(text);
        } catch (err) {
          await send(`Failed to generate options: ${err.message}`);
          return;
        }

        const prompt = 'Select a task to implement:';

        let selectedIndex;
        try {
          selectedIndex = await showOptions(prompt, options);
        } catch (err) {
          await send(`Option selection failed or timed out: ${err.message}`);
          return;
        }

        const selected = options[selectedIndex];
        if (!selected) {
          await send('Invalid selection. No task was started.');
          return;
        }

        await send(`Starting task: ${selected.label}`);

        const result = await this.execute(
          selected.description,
          (toolName) => send(`Using tool: ${toolName}...`)
        );

        await send(result);

        // Store a summary of what was done in the memory graph.
        try {
          await store(
            `Agent completed task: ${selected.label}. ${result.slice(0, 400)}`,
            'public'
          );
        } catch {
          // Memory store failure is non-fatal.
        }
      } else {
        // Execution mode: implement the task directly.
        await send('Working on it...');

        const result = await this.execute(
          text,
          (toolName) => send(`Using tool: ${toolName}...`)
        );

        await send(result);
      }
    } catch (err) {
      console.error('[Agent] handleMessage error:', err);
      await send(`An error occurred: ${err.message}`);
    }
  }
}
