import { Prop, Schema, SchemaFactory } from '@nestjs/mongoose';
import { Document, Types } from 'mongoose';

export type RiskProfileDocument = RiskProfile & Document;

@Schema({ timestamps: true })
export class RiskProfile {
  @Prop({ type: Types.ObjectId, ref: 'Student', required: true })
  studentId: Types.ObjectId;

  @Prop({ required: true, min: 1, max: 3 })
  term: number;

  @Prop({ type: Types.ObjectId, ref: 'SchoolYear', required: true })
  schoolYearId: Types.ObjectId;

  @Prop({ required: true })
  level: string;

  @Prop({ required: true, min: 0, max: 40 })
  score: number;

  @Prop({ default: 0 })
  incidentsThisTerm: number;

  @Prop({ default: 0 })
  totalDeducted: number;

  @Prop({ default: 0 })
  avgDeductionsPerWeek: number;

  @Prop({ default: 40, min: 0, max: 40 })
  lowestMarksEver: number;

  @Prop()
  triggeredBy?: { rule: string; threshold: number };

  @Prop()
  assessedAt?: Date;

  @Prop({ type: Types.ObjectId, ref: 'User' })
  assessedBy?: Types.ObjectId;
}

export const RiskProfileSchema = SchemaFactory.createForClass(RiskProfile);
