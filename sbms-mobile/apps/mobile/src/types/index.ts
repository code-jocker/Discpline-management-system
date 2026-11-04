export type User = {
  id: string;
  fullName: string;
  username: string;
  email: string;
  role: string;
  roleName: string;
  profilePicture?: string;
};

export type AuthState = {
  user: User | null;
  accessToken: string | null;
  refreshToken: string | null;
  isAuthenticated: boolean;
  isLoading: boolean;
};

export type Student = {
  id: string;
  studentCode: string;
  fullName: string;
  classId: string;
  className: string;
  categoryId?: string;
  categoryName?: string;
  parentContact?: string;
  schoolYearId: string;
  isActive: boolean;
  isBlacklisted: boolean;
  isExpelled: boolean;
};

export type Deduction = {
  id: string;
  studentId: string;
  studentCode: string;
  studentName: string;
  classId: string;
  className: string;
  term: number;
  schoolYearId: string;
  marksDeducted: number;
  reason: string;
  incidentCategory?: string;
  performedBy: string;
  performedByName: string;
  previousMarks: number;
  newMarks: number;
  riskBefore?: string;
  riskAfter?: string;
  createdAt: string;
};

export type RiskLevel = 'Excellent' | 'Warning' | 'Medium Risk' | 'High Risk' | 'Critical';

export type RiskProfile = {
  id: string;
  studentId: string;
  level: RiskLevel;
  score: number;
  incidentsThisTerm: number;
  totalDeducted: number;
  assessedAt: string;
};

export type NotificationItem = {
  id: string;
  recipientId: string;
  recipientName: string;
  type: 'deduction' | 'risk' | 'achievement' | 'letter' | 'system';
  title: string;
  body: string;
  isRead: boolean;
  priority: 'low' | 'normal' | 'high';
  createdAt: string;
};

export type ApiError = {
  code: string;
  message: string;
  field?: string;
};
