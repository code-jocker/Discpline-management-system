import { Request, Response } from 'express';
import { DisciplineService } from '../services/discipline.service';
import { CreateDeductionSchema } from '@sbms/shared';

export class DisciplineController {
  private disciplineService = new DisciplineService();

  async deduct(req: Request, res: Response): Promise<void> {
    const data = CreateDeductionSchema.parse(req.body);
    const user = (req as any).user;

    const result = await this.disciplineService.deductMarks({
      ...data,
      performedBy: user.id,
      performedByName: user.fullName,
      ipAddress: req.ip,
      userAgent: req.get('User-Agent'),
    });

    res.status(201).json({ success: true, data: result });
  }

  async list(req: Request, res: Response): Promise<void> {
    // placeholder - could use Deduction model directly
    res.json({ success: true, data: { data: [], meta: { page: 1, limit: 20, total: 0, totalPages: 0 } } });
  }

  async getById(req: Request, res: Response): Promise<void> {
    res.json({ success: true, data: { id: req.params.id } });
  }
}
