import { Prop, Schema, SchemaFactory } from '@nestjs/mongoose';
import { Document, Types } from 'mongoose';

export type DeductionDocument = Deduction & Document;

@Schema({ timestamps: true })
export class Deduction {
  @Prop({ type: Types.ObjectId, ref: 'Student', required: true })
  studentId: Types.ObjectId;

  @Prop()
  studentCode?: string;

  @Prop()
  studentName?: string;

  @Prop({ type: Types.ObjectId, ref: 'Class' })
  classId?: Types.ObjectId;

  @Prop()
  className?: string;

  @Prop({ required: true, min: 1, max: 3 })
  term: number;

  @Prop({ type: Types.ObjectId, ref: 'SchoolYear', required: true })
  schoolYearId: Types.ObjectId;

  @Prop({ required: true, min: 1 })
  marksDeducted: number;

  @Prop({ required: true })
  reason: string;

  @Prop()
  incidentCategory?: string;

  @Prop({ type: Types.ObjectId, ref: 'User', required: true })
  performedBy: Types.ObjectId;

  @Prop()
  performedByName?: string;

  @Prop()
  evidenceFileId?: string;

  @Prop()
  evidenceUrl?: string;

  @Prop({ required: true, min: 0, max: 40 })
  previousMarks: number;

  @Prop({ required: true, min: 0, max: 40 })
  newMarks: number;

  @Prop()
  riskBefore?: string;

  @Prop()
  riskAfter?: string;

  @Prop({ default: false })
  notified: boolean;

  @Prop()
  ipAddress?: string;

  @Prop()
  userAgent?: string;
}

export const DeductionSchema = SchemaFactory.createForClass(Deduction);
