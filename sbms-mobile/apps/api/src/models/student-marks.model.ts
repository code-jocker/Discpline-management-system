import { Prop, Schema, SchemaFactory } from '@nestjs/mongoose';
import { Document, Types } from 'mongoose';

export type StudentMarksDocument = StudentMarks & Document;

@Schema({ timestamps: true })
export class StudentMarks {
  @Prop({ type: Types.ObjectId, ref: 'Student', required: true })
  studentId: Types.ObjectId;

  @Prop()
  studentCode?: string;

  @Prop({ type: Types.ObjectId, ref: 'Class' })
  classId?: Types.ObjectId;

  @Prop({ required: true, min: 1, max: 3 })
  term: number;

  @Prop({ type: Types.ObjectId, ref: 'SchoolYear', required: true })
  schoolYearId: Types.ObjectId;

  @Prop({ required: true, min: 0, max: 40, default: 40 })
  marks: number;

  @Prop({ default: 0 })
  totalDeducted: number;

  @Prop({ default: 0 })
  totalRewarded: number;

  @Prop({ default: 0 })
  incidentCount: number;
}

export const StudentMarksSchema = SchemaFactory.createForClass(StudentMarks);
