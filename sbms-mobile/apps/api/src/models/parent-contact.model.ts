import { Prop, Schema, SchemaFactory } from '@nestjs/mongoose';
import { Document, Types } from 'mongoose';

export type ParentContactDocument = ParentContact & Document;

@Schema({ timestamps: true })
export class ParentContact {
  @Prop({ type: Types.ObjectId, ref: 'Student', required: true })
  studentId: Types.ObjectId;

  @Prop({ required: true })
  parentName: string;

  @Prop({ required: true })
  relationship: string;

  @Prop()
  email?: string;

  @Prop()
  phone?: string;

  @Prop()
  whatsapp?: string;

  @Prop({ default: 'email' })
  preference: 'email' | 'sms' | 'whatsapp' | 'none';

  @Prop({ default: false })
  isPrimary: boolean;
}

export const ParentContactSchema = SchemaFactory.createForClass(ParentContact);
