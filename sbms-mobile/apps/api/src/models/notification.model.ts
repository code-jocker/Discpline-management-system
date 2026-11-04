import { Prop, Schema, SchemaFactory } from '@nestjs/mongoose';
import { Document, Types } from 'mongoose';

export type NotificationDocument = Notification & Document;

@Schema({ timestamps: true })
export class Notification {
  @Prop({ type: Types.ObjectId, ref: 'User', required: true })
  recipientId: Types.ObjectId;

  @Prop()
  recipientName?: string;

  @Prop()
  recipientRole?: string;

  @Prop({ required: true })
  type: 'deduction' | 'risk' | 'achievement' | 'letter' | 'system';

  @Prop({ required: true })
  title: string;

  @Prop({ required: true })
  body: string;

  @Prop()
  relatedId?: Types.ObjectId;

  @Prop()
  relatedType?: 'deduction' | 'student';

  @Prop({ default: false })
  isRead: boolean;

  @Prop({ default: false })
  isPushed: boolean;

  @Prop({ default: 'normal' })
  priority: 'low' | 'normal' | 'high';

  @Prop()
  readAt?: Date;

  @Prop()
  pushedAt?: Date;
}

export const NotificationSchema = SchemaFactory.createForClass(Notification);
