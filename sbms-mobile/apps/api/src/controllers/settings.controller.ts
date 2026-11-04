import { Request, Response } from 'express';
import { SettingsService } from '../services/settings.service';

export class SettingsController {
  private settingsService = new SettingsService();

  async get(req: Request, res: Response): Promise<void> {
    const value = await this.settingsService.get(req.params.key);
    res.json({ success: true, data: { key: req.params.key, value } });
  }

  async set(req: Request, res: Response): Promise<void> {
    const { value, type } = req.body;
    const doc = await this.settingsService.set(req.params.key, value, type);
    res.json({ success: true, data: doc });
  }

  async getAll(req: Request, res: Response): Promise<void> {
    const all = await this.settingsService.getAll();
    res.json({ success: true, data: all });
  }
}
