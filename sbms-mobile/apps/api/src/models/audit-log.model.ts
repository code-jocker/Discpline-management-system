import { Prop, Schema, SchemaFactory } from '@nestjs/mongoose';
import { Document, Types } from 'mongoose';

export type AuditLogDocument = AuditLog & Document;

@Schema({ timestamps: true })
export class AuditLog {
  @Prop({ type: Types.ObjectId, ref: 'User' })
  userId?: Types.ObjectId;

  @Prop()
  userName?: string;

  @Prop({ required: true })
  action: string;

  @Prop({ required: true })
  entity: string;

  @Prop()
  entityId?: string;

  @Prop({ type: Object })
  metadata?: Record<string, unknown>;

  @Prop()
  ipAddress?: string;
}

export const AuditLogSchema = SchemaFactory.createForClass(AuditLog);
