import { createApp } from './app';
import { connectDB, disconnectDB } from './config/db';
import { config } from './config/env';
import { SeedService } from './services/seed.service';

const app = createApp();

async function start() {
  validateConfig(config);
  await connectDB();
  await new SeedService().seed();

  const server = app.listen(config.port, () => {
    // eslint-disable-next-line no-console
    console.log(`[API] SBMS API running on http://localhost:${config.port}`);
  });

  const shutdown = async (signal: string) => {
    // eslint-disable-next-line no-console
    console.log(`[API] ${signal} received, shutting down...`);
    server.close(async () => {
      await disconnectDB();
      process.exit(0);
    });
  };

  process.on('SIGTERM', () => shutdown('SIGTERM'));
  process.on('SIGINT', () => shutdown('SIGINT'));
}

function validateConfig(c: typeof config) {
  if (!c.jwtAccessSecret || !c.jwtRefreshSecret) {
    // eslint-disable-next-line no-console
    console.error('[FATAL] JWT secrets not configured');
    process.exit(1);
  }
}

start().catch((err) => {
  // eslint-disable-next-line no-console
  console.error('[FATAL]', err);
  process.exit(1);
});
