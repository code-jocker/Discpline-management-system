import { Request, Response } from 'express';
import { RiskEngineService } from '../services/risk-engine.service';

export class RiskController {
  private riskEngine = new RiskEngineService();

  async assessAll(req: Request, res: Response): Promise<void> {
    const { schoolYearId, term } = req.body;
    const results = await this.riskEngine.assessAllStudents(schoolYearId, parseInt(term as string, 10) || 1);
    res.json({ success: true, data: results });
  }
}
