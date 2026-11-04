import { Request, Response } from 'express';
import { AuthService } from '../services/auth.service';
import { RegisterSchema, LoginSchema } from '@sbms/shared';

export class AuthController {
  private authService = new AuthService();

  async register(req: Request, res: Response): Promise<void> {
    const data = RegisterSchema.parse(req.body);
    const result = await this.authService.register(data);
    res.status(201).json({ success: true, data: result });
  }

  async login(req: Request, res: Response): Promise<void> {
    const data = LoginSchema.parse(req.body);
    const result = await this.authService.login({
      ...data,
      ipAddress: req.ip,
      userAgent: req.get('User-Agent'),
    });
    res.json({ success: true, data: result });
  }

  async refresh(req: Request, res: Response): Promise<void> {
    const { refreshToken } = req.body;
    if (!refreshToken) {
      res.status(400).json({ success: false, error: { code: 'MISSING_TOKEN', message: 'refreshToken required' } });
      return;
    }
    const result = await this.authService.refresh(refreshToken);
    res.json({ success: true, data: result });
  }

  async logout(req: Request, res: Response): Promise<void> {
    await this.authService.logout();
    res.json({ success: true, data: { message: 'Logged out' } });
  }

  async me(req: Request, res: Response): Promise<void> {
    const user = (req as any).user;
    res.json({ success: true, data: user });
  }
}
