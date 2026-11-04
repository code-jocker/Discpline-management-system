import { Prop, Schema, SchemaFactory } from '@nestjs/mongoose';
import { Document, Types } from 'mongoose';

export type ClassDocument = ClassModel & Document;

@Schema({ timestamps: true })
export class ClassModel {
  @Prop({ required: true })
  name: string;

  @Prop({ type: Types.ObjectId, ref: 'Category' })
  categoryId?: Types.ObjectId;

  @Prop()
  categoryName?: string;

  @Prop({ type: Types.ObjectId, ref: 'User' })
  classTeacherUserId?: Types.ObjectId;

  @Prop()
  classTeacherName?: string;

  @Prop({ type: Types.ObjectId, ref: 'SchoolYear', required: true })
  schoolYearId: Types.ObjectId;

  @Prop({ default: 0 })
  studentCount: number;
}

export const ClassSchema = SchemaFactory.createForClass(ClassModel);
