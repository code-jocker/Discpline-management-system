import { Request, Response } from 'express';

export class ClassController {
  async list(req: Request, res: Response): Promise<void> {
    res.json({ success: true, data: { data: [], meta: { page: 1, limit: 20, total: 0, totalPages: 0 } } });
  }
}
