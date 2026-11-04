import { Prop, Schema, SchemaFactory } from '@nestjs/mongoose';
import { Document, Types } from 'mongoose';

export type SchoolYearDocument = SchoolYear & Document;

@Schema({ timestamps: true })
export class SchoolYear {
  @Prop({ required: true, unique: true })
  name: string;

  @Prop({ required: true })
  startYear: number;

  @Prop({ required: true })
  endYear: number;

  @Prop({ default: 9 })
  startMonth: number;

  @Prop({ default: 7 })
  endMonth: number;

  @Prop({ default: false })
  isActive: boolean;

  @Prop({ default: false })
  isArchived: boolean;

  @Prop({ default: false })
  isLocked: boolean;
}

export const SchoolYearSchema = SchemaFactory.createForClass(SchoolYear);
