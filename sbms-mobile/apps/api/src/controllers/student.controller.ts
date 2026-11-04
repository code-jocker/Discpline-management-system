import { Request, Response } from 'express';
import { StudentService } from '../services/student.service';
import { CreateStudentSchema } from '@sbms/shared';

export class StudentController {
  private studentService = new StudentService();

  async list(req: Request, res: Response): Promise<void> {
    const filters: any = {};
    if (req.query.classId) filters.classId = req.query.classId as string;
    if (req.query.schoolYearId) filters.schoolYearId = req.query.schoolYearId as string;
    if (req.query.search) filters.search = req.query.search as string;

    const page = parseInt(req.query.page as string) || 1;
    const limit = parseInt(req.query.limit as string) || 20;

    const result = await this.studentService.list(filters, page, limit);
    res.json({ success: true, data: result });
  }

  async getById(req: Request, res: Response): Promise<void> {
    const student = await this.studentService.getById(req.params.id);
    if (!student) {
      res.status(404).json({ success: false, error: { code: 'NOT_FOUND', message: 'Student not found' } });
      return;
    }
    res.json({ success: true, data: student });
  }

  async create(req: Request, res: Response): Promise<void> {
    const data = CreateStudentSchema.parse(req.body);
    const student = await this.studentService.create(data);
    res.status(201).json({ success: true, data: student });
  }

  async update(req: Request, res: Response): Promise<void> {
    const student = await this.studentService.update(req.params.id, req.body);
    res.json({ success: true, data: student });
  }

  async delete(req: Request, res: Response): Promise<void> {
    await this.studentService.delete(req.params.id);
    res.json({ success: true, data: { message: 'Student deactivated' } });
  }
}
