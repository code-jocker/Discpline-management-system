import { Student } from '../models/student.model';
import { StudentMarks } from '../models/student-marks.model';
import { Deduction } from '../models/deduction.model';
import { RiskProfile } from '../models/risk-profile.model';
import { RiskEngineService } from './risk-engine.service';
import { NotificationService } from './notification.service';
import { AuditLog } from '../models/audit-log.model';
import { Settings } from '../models/settings.model';
import { SchoolYear } from '../models/school-year.model';

export class DisciplineService {
  async deductMarks(data: {
    studentId: string;
    marksDeducted: number;
    reason: string;
    performedBy: string;
    performedByName: string;
    incidentCategory?: string;
    evidenceFileId?: string;
    ipAddress?: string;
    userAgent?: string;
  }) {
    const schoolYear = await SchoolYear.findOne({ isActive: true, isArchived: false });
    if (!schoolYear) {
      throw Object.assign(new Error('No active school year'), { statusCode: 400 });
    }

    const term = await this.getCurrentTerm();

    const student = await Student.findById(data.studentId);
    if (!student) {
      throw Object.assign(new Error('Student not found'), { statusCode: 404 });
    }
    if (student.isExpelled) {
      throw Object.assign(new Error('Student is expelled'), { statusCode: 403 });
    }

    const marks = await StudentMarks.findOne({
      studentId: data.studentId,
      term,
      schoolYearId: schoolYear._id,
    });

    if (!marks) {
      await StudentMarks.create({
        studentId: data.studentId,
        studentCode: student.studentCode,
        classId: student.classId,
        term,
        schoolYearId: schoolYear._id,
        marks: 40,
      });
    }

    const previousMarks = marks ? marks.marks : 40;
    const newMarks = Math.max(0, Math.min(40, previousMarks - data.marksDeducted));

    const session = await StudentMarks.startSession();
    session.startTransaction();

    try {
      if (marks) {
        marks.marks = newMarks;
        marks.totalDeducted = (marks.totalDeducted ?? 0) + data.marksDeducted;
        marks.incidentCount = (marks.incidentCount ?? 0) + 1;
        await marks.save({ session });
      } else {
        await StudentMarks.create(
          [
            {
              studentId: data.studentId,
              studentCode: student.studentCode,
              classId: student.classId,
              term,
              schoolYearId: schoolYear._id,
              marks: newMarks,
              totalDeducted: data.marksDeducted,
              incidentCount: 1,
            },
          ],
          { session }
        );
      }

      const deduction = await Deduction.create(
        [
          {
            studentId: data.studentId,
            studentCode: student.studentCode,
            studentName: student.fullName,
            classId: student.classId,
            className: student.className,
            term,
            schoolYearId: schoolYear._id,
            marksDeducted: data.marksDeducted,
            reason: data.reason,
            incidentCategory: data.incidentCategory,
            performedBy: data.performedBy,
            performedByName: data.performedByName,
            evidenceFileId: data.evidenceFileId,
            previousMarks,
            newMarks,
            ipAddress: data.ipAddress,
            userAgent: data.userAgent,
          },
        ],
        { session }
      );

      await session.commitTransaction();

      const riskEngine = new RiskEngineService();
      const riskResult = await riskEngine.assessStudent(
        data.studentId,
        term,
        schoolYear._id.toString(),
        newMarks
      );

      const notificationService = new NotificationService();
      await notificationService.notifyDeduction({
        studentId: data.studentId,
        studentName: student.fullName,
        classId: student.classId,
        deductionId: deduction[0]._id.toString(),
        marksDeducted: data.marksDeducted,
        reason: data.reason,
        performedByName: data.performedByName,
        riskLevel: riskResult.level,
      });

      await AuditLog.create({
        userId: data.performedBy,
        userName: data.performedByName,
        action: 'discipline.deduct',
        entity: 'Deduction',
        entityId: deduction[0]._id.toString(),
        metadata: {
          studentId: data.studentId,
          marksDeducted: data.marksDeducted,
          previousMarks,
          newMarks,
          riskLevel: riskResult.level,
        },
        ipAddress: data.ipAddress,
      });

      return {
        deduction: deduction[0],
        studentMarks: { studentId: data.studentId, marks: newMarks },
        risk: riskResult,
      };
    } catch (error) {
      await session.abortTransaction();
      throw error;
    } finally {
      session.endSession();
    }
  }

  private async getCurrentTerm(): Promise<number> {
    const setting = await Settings.findOne({ key: 'current_term' });
    return setting ? parseInt(setting.value, 10) : 1;
  }
}
