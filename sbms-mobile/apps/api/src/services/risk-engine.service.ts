import { RiskProfile } from '../models/risk-profile.model';
import { StudentMarks } from '../models/student-marks.model';
import { Settings } from '../models/settings.model';

const LOW_MARKS_THRESHOLD_DEFAULT = 20;

export class RiskEngineService {
  async assessStudent(
    studentId: string,
    term: number,
    schoolYearId: string,
    currentMarks: number
  ) {
    const marksDoc = await StudentMarks.findOne({ studentId, term, schoolYearId });
    const totalDeducted = marksDoc?.totalDeducted ?? 0;
    const incidentCount = marksDoc?.incidentCount ?? 0;

    const setting = await Settings.findOne({ key: 'low_marks_threshold' });
    const threshold = setting ? parseInt(setting.value, 10) : LOW_MARKS_THRESHOLD_DEFAULT;

    let level: string = 'Excellent';
    let triggeredBy: { rule: string; threshold: number } | undefined;

    // Critical: incidents >= 8 OR deductions >= 40
    if (incidentCount >= 8 || totalDeducted >= 40) {
      level = 'Critical';
      triggeredBy = { rule: 'incidents_or_deductions', threshold: 8 };
    }
    // High Risk: score <= 19
    else if (currentMarks <= 19) {
      level = 'High Risk';
      triggeredBy = { rule: 'score', threshold: 19 };
    }
    // Medium Risk: score <= 29
    else if (currentMarks <= 29) {
      level = 'Medium Risk';
      triggeredBy = { rule: 'score', threshold: 29 };
    }
    // Warning: incidents >= 3 OR deductions >= 15
    else if (incidentCount >= 3 || totalDeducted >= 15) {
      level = 'Warning';
      triggeredBy = { rule: 'incidents_or_deductions', threshold: 3 };
    }

    const riskProfile = await RiskProfile.findOneAndUpdate(
      { studentId, term, schoolYearId },
      {
        studentId,
        term,
        schoolYearId,
        level,
        score: currentMarks,
        incidentsThisTerm: incidentCount,
        totalDeducted,
        avgDeductionsPerWeek: totalDeducted / 12,
        lowestMarksEver: Math.min(currentMarks, marksDoc?.marks ?? 40),
        triggeredBy,
        assessedAt: new Date(),
        assessedBy: 'system',
      },
      { upsert: true, new: true }
    );

    return {
      level,
      score: currentMarks,
      incidentsThisTerm: incidentCount,
      totalDeducted,
      triggeredBy,
      profileId: riskProfile._id,
    };
  }

  async assessAllStudents(schoolYearId: string, term: number) {
    const marks = await StudentMarks.find({ term, schoolYearId });
    const results = [];

    for (const m of marks) {
      const result = await this.assessStudent(
        m.studentId.toString(),
        term,
        schoolYearId,
        m.marks
      );
      results.push(result);
    }

    return results;
  }
}
