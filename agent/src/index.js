import 'dotenv/config';
import { Agent } from './core/agent.js';
import { DiscordConnector } from './connectors/discord.js';

const agent = new Agent();
const connectors = [];

if (process.env.DISCORD_BOT_TOKEN) {
  connectors.push(new DiscordConnector(agent));
}

if (connectors.length === 0) {
  console.error('No connectors configured. Set at least one connector token in .env');
  process.exit(1);
}

for (const connector of connectors) {
  await connector.start();
}

console.log(`OpenMemory Agent running with ${connectors.length} connector(s)`);

process.on('SIGINT', async () => {
  for (const connector of connectors) {
    await connector.stop();
  }
  process.exit(0);
});
