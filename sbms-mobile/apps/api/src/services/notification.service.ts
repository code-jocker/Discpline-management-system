import { Notification } from '../models/notification.model';
import { User } from '../models/user.model';

export class NotificationService {
  async notifyDeduction(data: {
    studentId: string;
    studentName: string;
    classId: string;
    deductionId: string;
    marksDeducted: number;
    reason: string;
    performedByName: string;
    riskLevel: string;
  }) {
    const recipients = await User.find({
      $or: [
        { roleName: 'admin' },
        { roleName: 'headteacher' },
        { roleName: 'it' },
        { roleName: 'deputy_headteacher_discipline' },
        { _id: data.classId }, // class teacher heuristic
      ],
    });

    const notifications = recipients.map((u) => ({
      recipientId: u._id,
      recipientName: u.fullName,
      recipientRole: u.roleName,
      type: 'deduction' as const,
      title: `Marks deducted for ${data.studentName}`,
      body: `${data.performedByName} deducted ${data.marksDeducted} mark(s). Reason: ${data.reason}`,
      relatedId: data.deductionId,
      relatedType: 'deduction',
      priority: data.riskLevel === 'High Risk' || data.riskLevel === 'Critical' ? 'high' : 'normal',
    }));

    if (notifications.length > 0) {
      await Notification.insertMany(notifications);
    }

    return notifications.length;
  }

  async notifyRisk(data: {
    studentId: string;
    studentName: string;
    level: string;
    score: number;
    userId: string;
  }) {
    const admins = await User.find({
      $or: [
        { roleName: 'admin' },
        { roleName: 'headteacher' },
        { roleName: 'it' },
      ],
    });

    const notifications = admins.map((u) => ({
      recipientId: u._id,
      recipientName: u.fullName,
      recipientRole: u.roleName,
      type: 'risk' as const,
      title: `Student ${data.studentName} is ${data.level}`,
      body: `Score: ${data.score}/40. Immediate attention may be required.`,
      relatedId: data.studentId,
      relatedType: 'student',
      priority: 'high',
    }));

    if (notifications.length > 0) {
      await Notification.insertMany(notifications);
    }

    return notifications.length;
  }

  async listNotifications(userId: string, page = 1, limit = 20) {
    const skip = (page - 1) * limit;
    const [data, total] = await Promise.all([
      Notification.find({ recipientId: userId })
        .sort({ createdAt: -1 })
        .skip(skip)
        .limit(limit),
      Notification.countDocuments({ recipientId: userId }),
    ]);

    return {
      data,
      meta: {
        page,
        limit,
        total,
        totalPages: Math.ceil(total / limit),
      },
    };
  }

  async markRead(notificationId: string, userId: string) {
    return Notification.findOneAndUpdate(
      { _id: notificationId, recipientId: userId },
      { isRead: true, readAt: new Date() },
      { new: true }
    );
  }

  async markAllRead(userId: string) {
    return Notification.updateMany(
      { recipientId: userId, isRead: false },
      { isRead: true, readAt: new Date() }
    );
  }
}
