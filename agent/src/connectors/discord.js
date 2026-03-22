/**
 * DiscordConnector — bridges Discord messages to the OpenMemory agent core.
 *
 * Only processes messages from the configured guild, channel, and allowed
 * user list. Presents selectable options using Discord button components
 * and waits up to 5 minutes for a user response before timing out.
 *
 * Required env vars:
 *   DISCORD_BOT_TOKEN        — bot token from the Discord developer portal
 *   DISCORD_GUILD_ID         — the server (guild) the bot should operate in
 *   DISCORD_CHANNEL_ID       — the specific channel to read and reply in
 *   DISCORD_ALLOWED_USER_IDS — comma-separated Discord user IDs that may issue commands
 */

import {
  Client,
  GatewayIntentBits,
  ActionRowBuilder,
  ButtonBuilder,
  ButtonStyle,
  EmbedBuilder,
} from 'discord.js';
import { BaseConnector } from './base.js';

const DISCORD_BOT_TOKEN      = process.env.DISCORD_BOT_TOKEN || '';
const DISCORD_CHANNEL_ID     = process.env.DISCORD_CHANNEL_ID || '';
const DISCORD_ALLOWED_USER_IDS = (process.env.DISCORD_ALLOWED_USER_IDS || '')
  .split(',')
  .map((id) => id.trim())
  .filter(Boolean);

// Discord has a 2000-character limit per message.
const DISCORD_MESSAGE_LIMIT = 2000;

// How long to wait for a button interaction before giving up (5 minutes).
const OPTION_TIMEOUT_MS = 5 * 60 * 1000;

/**
 * Split a long string into chunks that fit within Discord's message limit.
 *
 * @param {string} text
 * @returns {string[]}
 */
function chunkText(text) {
  const chunks = [];
  let remaining = text;
  while (remaining.length > DISCORD_MESSAGE_LIMIT) {
    chunks.push(remaining.slice(0, DISCORD_MESSAGE_LIMIT));
    remaining = remaining.slice(DISCORD_MESSAGE_LIMIT);
  }
  if (remaining.length > 0) {
    chunks.push(remaining);
  }
  return chunks;
}

export class DiscordConnector extends BaseConnector {
  constructor(agent) {
    super(agent);

    this.client = new Client({
      intents: [
        GatewayIntentBits.Guilds,
        GatewayIntentBits.GuildMessages,
        GatewayIntentBits.MessageContent,
      ],
    });

    // Pending option selections: sessionId -> { resolve, reject }
    this.pendingSessions = new Map();

    this._setupListeners();
  }

  _setupListeners() {
    this.client.on('messageCreate', async (message) => {
      // Ignore messages from bots (including ourselves).
      if (message.author.bot) return;

      // Only process messages from the configured channel.
      if (message.channelId !== DISCORD_CHANNEL_ID) return;

      // Only process messages from allowed users.
      if (!DISCORD_ALLOWED_USER_IDS.includes(message.author.id)) return;

      // Show a typing indicator while the agent is working.
      await message.channel.sendTyping().catch(() => {});

      const send = async (text) => {
        const chunks = chunkText(text);
        for (const chunk of chunks) {
          await message.channel.send(chunk);
        }
      };

      const showOptions = (prompt, options) =>
        this._showOptions(message, prompt, options);

      try {
        await this.agent.handleMessage({
          text: message.content,
          userId: message.author.id,
          send,
          showOptions,
        });
      } catch (err) {
        console.error('[Discord] handleMessage error:', err);
        await send(`An error occurred: ${err.message}`);
      }
    });

    this.client.on('interactionCreate', async (interaction) => {
      // Only handle button interactions.
      if (!interaction.isButton()) return;

      // Only accept interactions from allowed users.
      if (!DISCORD_ALLOWED_USER_IDS.includes(interaction.user.id)) {
        await interaction.reply({ content: 'You are not authorized to use this button.', ephemeral: true });
        return;
      }

      // customId format: opt_{sessionId}_{index}
      const match = interaction.customId.match(/^opt_([^_]+)_(\d+)$/);
      if (!match) return;

      const [, sessionId, indexStr] = match;
      const session = this.pendingSessions.get(sessionId);
      if (!session) {
        await interaction.reply({ content: 'This selection has already been handled or has expired.', ephemeral: true });
        return;
      }

      const selectedIndex = parseInt(indexStr, 10);

      // Disable all buttons on the original message to show the selection was made.
      try {
        const disabledRows = session.rows.map((row) => {
          const disabledRow = new ActionRowBuilder();
          disabledRow.addComponents(
            row.components.map((btn) =>
              ButtonBuilder.from(btn).setDisabled(true)
            )
          );
          return disabledRow;
        });

        await interaction.update({ components: disabledRows });
      } catch {
        await interaction.deferUpdate().catch(() => {});
      }

      this.pendingSessions.delete(sessionId);
      session.resolve(selectedIndex);
    });

    this.client.on('ready', () => {
      console.log(`[Discord] Logged in as ${this.client.user.tag}`);
    });
  }

  /**
   * Present a numbered list of options as Discord buttons and wait for the
   * user to click one. Resolves with the zero-based index of the selection.
   * Rejects if the user does not respond within OPTION_TIMEOUT_MS.
   *
   * @param {import('discord.js').Message} message
   * @param {string}   prompt
   * @param {Array<{label: string, description: string}>} options
   * @returns {Promise<number>}
   */
  _showOptions(message, prompt, options) {
    return new Promise(async (resolve, reject) => {
      const sessionId = crypto.randomUUID();

      const embed = new EmbedBuilder()
        .setTitle('Select an option')
        .setDescription(prompt)
        .addFields(
          options.map((opt, i) => ({
            name: `${i + 1}. ${opt.label}`,
            value: opt.description,
          }))
        );

      // Discord allows at most 5 buttons per ActionRow and 5 rows per message.
      // With up to 5 options, one row is sufficient.
      const rows = [];
      const BUTTONS_PER_ROW = 5;

      for (let i = 0; i < options.length; i += BUTTONS_PER_ROW) {
        const row = new ActionRowBuilder();
        const slice = options.slice(i, i + BUTTONS_PER_ROW);

        row.addComponents(
          slice.map((opt, j) => {
            const globalIndex = i + j;
            return new ButtonBuilder()
              .setCustomId(`opt_${sessionId}_${globalIndex}`)
              .setLabel(`${globalIndex + 1}. ${opt.label}`.slice(0, 80))
              .setStyle(ButtonStyle.Primary);
          })
        );

        rows.push(row);
      }

      const sentMessage = await message.channel.send({
        embeds: [embed],
        components: rows,
      });

      const timeout = setTimeout(async () => {
        this.pendingSessions.delete(sessionId);

        // Disable the buttons on timeout.
        try {
          const disabledRows = rows.map((row) => {
            const disabledRow = new ActionRowBuilder();
            disabledRow.addComponents(
              row.components.map((btn) =>
                ButtonBuilder.from(btn).setDisabled(true)
              )
            );
            return disabledRow;
          });
          await sentMessage.edit({ components: disabledRows });
        } catch {
          // Ignore edit failures on timeout.
        }

        reject(new Error('Option selection timed out after 5 minutes.'));
      }, OPTION_TIMEOUT_MS);

      this.pendingSessions.set(sessionId, {
        resolve: (index) => {
          clearTimeout(timeout);
          resolve(index);
        },
        rows,
      });
    });
  }

  async start() {
    await this.client.login(DISCORD_BOT_TOKEN);
  }

  async stop() {
    this.client.destroy();
  }
}
