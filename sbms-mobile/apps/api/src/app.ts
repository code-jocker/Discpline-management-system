import express from 'express';
import cors from 'cors';
import helmet from 'helmet';
import rateLimit from 'express-rate-limit';
import v1 from './routes/v1';
import { errorHandler, notFound } from './middleware';
import { config } from './config/env';

export function createApp() {
  const app = express();

  app.set('trust proxy', 1);

  app.use(helmet());
  app.use(cors({ origin: config.corsOrigin, credentials: true }));
  app.use(express.json({ limit: '1mb' }));
  app.use(express.urlencoded({ extended: true }));

  app.use(rateLimit({ windowMs: 60 * 1000, max: 100 }));

  app.get('/health', (_req, res) => {
    res.json({ status: 'ok', service: 'sbms-api', time: new Date().toISOString() });
  });

  app.use('/api/v1', v1);

  app.use(notFound);
  app.use(errorHandler);

  return app;
}
