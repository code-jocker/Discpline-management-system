import mongoose from 'mongoose';
import { config } from './env';

let isConnected = false;

export async function connectDB(): Promise<void> {
  if (isConnected) {
    return;
  }

  try {
    await mongoose.connect(config.mongoUri, {
      maxPoolSize: 10,
      serverSelectionTimeoutMS: 5000,
    });
    isConnected = true;
    // eslint-disable-next-line no-console
    console.log('[DB] Connected to MongoDB');
  } catch (error) {
    // eslint-disable-next-line no-console
    console.error('[DB ERROR]', error);
    process.exit(1);
  }
}

export function getDB() {
  return mongoose.connection;
}

export async function disconnectDB(): Promise<void> {
  if (isConnected) {
    await mongoose.disconnect();
    isConnected = false;
  }
}
