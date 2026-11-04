import { Request, Response } from 'express';
import { NotificationService } from '../services/notification.service';

export class NotificationController {
  private notificationService = new NotificationService();

  async list(req: Request, res: Response): Promise<void> {
    const user = (req as any).user;
    const page = parseInt(req.query.page as string) || 1;
    const limit = parseInt(req.query.limit as string) || 20;
    const result = await this.notificationService.listNotifications(user.id, page, limit);
    res.json({ success: true, data: result });
  }

  async markRead(req: Request, res: Response): Promise<void> {
    const user = (req as any).user;
    const result = await this.notificationService.markRead(req.params.id, user.id);
    res.json({ success: true, data: result });
  }

  async markAllRead(req: Request, res: Response): Promise<void> {
    const user = (req as any).user;
    await this.notificationService.markAllRead(user.id);
    res.json({ success: true, data: { message: 'All notifications marked read' } });
  }
}
