import { Settings } from '../models/settings.model';

export class SettingsService {
  async get(key: string, defaultValue?: string) {
    const doc = await Settings.findOne({ key });
    return doc ? doc.value : defaultValue;
  }

  async set(key: string, value: string, type = 'string') {
    return Settings.findOneAndUpdate(
      { key },
      { key, value, type },
      { upsert: true, new: true }
    );
  }

  async getAll() {
    const docs = await Settings.find();
    const result: Record<string, string> = {};
    for (const d of docs) {
      result[d.key] = d.value;
    }
    return result;
  }
}
