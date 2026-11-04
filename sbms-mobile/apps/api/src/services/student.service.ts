import { Student } from '../models/student.model';
import { StudentMarks } from '../models/student-marks.model';

export class StudentService {
  async list(filters: any, page: number, limit: number) {
    const skip = (page - 1) * limit;
    const query: any = { isActive: true };

    if (filters.classId) query.classId = filters.classId;
    if (filters.schoolYearId) query.schoolYearId = filters.schoolYearId;
    if (filters.search) {
      query.fullName = { $regex: filters.search, $options: 'i' };
    }

    const [data, total] = await Promise.all([
      Student.find(query).sort({ createdAt: -1 }).skip(skip).limit(limit),
      Student.countDocuments(query),
    ]);

    return { data, meta: { page, limit, total, totalPages: Math.ceil(total / limit) } };
  }

  async getById(studentId: string) {
    return Student.findById(studentId);
  }

  async create(data: any) {
    const schoolYear = await this.getActiveSchoolYear();
    const student = await Student.create({
      ...data,
      schoolYearId: schoolYear._id,
    });

    await StudentMarks.create({
      studentId: student._id,
      studentCode: student.studentCode,
      classId: student.classId,
      term: 1,
      schoolYearId: schoolYear._id,
      marks: 40,
    });

    return student;
  }

  async update(studentId: string, data: any) {
    return Student.findByIdAndUpdate(studentId, data, { new: true });
  }

  async delete(studentId: string) {
    return Student.findByIdAndUpdate(studentId, { isActive: false });
  }

  private async getActiveSchoolYear() {
    return (await this.getSchoolYearModel()).findOne({ isActive: true, isArchived: false });
  }

  private getSchoolYearModel() {
    // lazy require to avoid circular import
    return require('../models/school-year.model').SchoolYear;
  }
}
