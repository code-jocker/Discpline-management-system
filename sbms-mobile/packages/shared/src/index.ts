import { z } from 'zod';

// ---------- Role & Permission ----------
export const UserRoleSchema = z.enum([
  'admin',
  'headteacher',
  'teacher',
  'deputy_headteacher_discipline',
  'it',
]);
export type UserRole = z.infer<typeof UserRoleSchema>;

export const RoleSchema = z.object({
  _id: z.string().optional(),
  name: UserRoleSchema,
  permissions: z.array(z.string()),
  isPrivileged: z.boolean().default(false),
  createdAt: z.date().optional(),
});

// ---------- Pagination ----------
export const PaginationQuerySchema = z.object({
  page: z.coerce.number().min(1).default(1),
  limit: z.coerce.number().min(1).max(100).default(20),
  sortBy: z.string().optional(),
  sortOrder: z.enum(['asc', 'desc']).default('desc'),
});
export type PaginationQuery = z.infer<typeof PaginationQuerySchema>;

export const PaginatedResponseSchema = <T extends z.ZodTypeAny>(itemSchema: T) =>
  z.object({
    data: z.array(itemSchema),
    meta: z.object({
      page: z.number(),
      limit: z.number(),
      total: z.number(),
      totalPages: z.number(),
    }),
  });

// ---------- Auth ----------
export const RegisterSchema = z.object({
  fullName: z.string().min(2, 'Full name is required'),
  username: z.string().min(3, 'Username is required').max(30),
  email: z.string().email('Invalid email'),
  password: z.string().min(8, 'Password must be at least 8 characters'),
  role: UserRoleSchema,
});
export type RegisterInput = z.infer<typeof RegisterSchema>;

export const LoginSchema = z.object({
  login: z.string().min(1, 'Email or username required'),
  password: z.string().min(1, 'Password required'),
});
export type LoginInput = z.infer<typeof LoginSchema>;

export const AuthResponseSchema = z.object({
  user: z.object({
    id: z.string(),
    fullName: z.string(),
    username: z.string(),
    email: z.string(),
    role: UserRoleSchema,
    roleName: z.string(),
    profilePicture: z.string().optional(),
  }),
  accessToken: z.string(),
  refreshToken: z.string(),
});

// ---------- School Year ----------
export const SchoolYearSchema = z.object({
  _id: z.string().optional(),
  name: z.string(),
  startYear: z.number().int(),
  endYear: z.number().int(),
  startMonth: z.number().int().default(9),
  endMonth: z.number().int().default(7),
  isActive: z.boolean().default(false),
  isArchived: z.boolean().default(false),
  isLocked: z.boolean().default(false),
  createdAt: z.date().optional(),
  updatedAt: z.date().optional(),
});
export type SchoolYear = z.infer<typeof SchoolYearSchema>;

// ---------- Class ----------
export const ClassSchema = z.object({
  _id: z.string().optional(),
  name: z.string().min(1),
  categoryId: z.string().optional(),
  categoryName: z.string().optional(),
  classTeacherUserId: z.string().optional(),
  classTeacherName: z.string().optional(),
  schoolYearId: z.string(),
  studentCount: z.number().int().default(0),
  createdAt: z.date().optional(),
  updatedAt: z.date().optional(),
});
export type Class = z.infer<typeof ClassSchema>;

export const CreateClassSchema = z.object({
  name: z.string().min(1),
  categoryId: z.string().optional(),
  classTeacherUserId: z.string().optional(),
  schoolYearId: z.string(),
});

// ---------- Student ----------
export const StudentSchema = z.object({
  _id: z.string().optional(),
  studentCode: z.string(),
  fullName: z.string().min(2),
  classId: z.string(),
  className: z.string(),
  categoryId: z.string().optional(),
  categoryName: z.string().optional(),
  parentContact: z.string().optional(),
  schoolYearId: z.string(),
  isActive: z.boolean().default(true),
  isBlacklisted: z.boolean().default(false),
  isExpelled: z.boolean().default(false),
  createdAt: z.date().optional(),
  updatedAt: z.date().optional(),
});
export type Student = z.infer<typeof StudentSchema>;

export const CreateStudentSchema = z.object({
  fullName: z.string().min(2),
  classId: z.string(),
  categoryId: z.string().optional(),
  parentContact: z.string().optional(),
  schoolYearId: z.string(),
});

// ---------- Marks ----------
export const StudentMarksSchema = z.object({
  _id: z.string().optional(),
  studentId: z.string(),
  studentCode: z.string(),
  classId: z.string(),
  term: z.number().int().min(1).max(3),
  schoolYearId: z.string(),
  marks: z.number().int().min(0).max(40).default(40),
  totalDeducted: z.number().int().default(0),
  totalRewarded: z.number().int().default(0),
  incidentCount: z.number().int().default(0),
  updatedAt: z.date().optional(),
});
export type StudentMarks = z.infer<typeof StudentMarksSchema>;

// ---------- Deduction / Incident ----------
export const RiskLevelSchema = z.enum([
  'Excellent',
  'Warning',
  'Medium Risk',
  'High Risk',
  'Critical',
]);
export type RiskLevel = z.infer<typeof RiskLevelSchema>;

export const DeductionSchema = z.object({
  _id: z.string().optional(),
  studentId: z.string(),
  studentCode: z.string(),
  studentName: z.string(),
  classId: z.string(),
  className: z.string(),
  term: z.number().int().min(1).max(3),
  schoolYearId: z.string(),
  marksDeducted: z.number().int().positive(),
  reason: z.string().min(1),
  incidentCategory: z.string().optional(),
  performedBy: z.string(),
  performedByName: z.string(),
  evidenceFileId: z.string().optional(),
  evidenceUrl: z.string().optional(),
  previousMarks: z.number().int().min(0).max(40),
  newMarks: z.number().int().min(0).max(40),
  riskBefore: RiskLevelSchema.optional(),
  riskAfter: RiskLevelSchema.optional(),
  notified: z.boolean().default(false),
  ipAddress: z.string().optional(),
  userAgent: z.string().optional(),
  createdAt: z.date().optional(),
});
export type Deduction = z.infer<typeof DeductionSchema>;

export const CreateDeductionSchema = z.object({
  studentId: z.string(),
  marksDeducted: z.number().int().positive().max(40),
  reason: z.string().min(1).max(500),
  incidentCategory: z.string().optional(),
  evidenceFileId: z.string().optional(),
});

// ---------- Risk Profile ----------
export const RiskProfileSchema = z.object({
  _id: z.string().optional(),
  studentId: z.string(),
  term: z.number().int().min(1).max(3),
  schoolYearId: z.string(),
  level: RiskLevelSchema,
  score: z.number().int().min(0).max(40),
  incidentsThisTerm: z.number().int().default(0),
  totalDeducted: z.number().int().default(0),
  avgDeductionsPerWeek: z.number().default(0),
  lowestMarksEver: z.number().int().min(0).max(40).default(40),
  triggeredBy: z.object({
    rule: z.string(),
    threshold: z.number(),
  }).optional(),
  assessedAt: z.date().optional(),
  assessedBy: z.string().optional(),
});
export type RiskProfile = z.infer<typeof RiskProfileSchema>;

// ---------- Notification ----------
export const NotificationSchema = z.object({
  _id: z.string().optional(),
  recipientId: z.string(),
  recipientName: z.string(),
  recipientRole: z.string(),
  type: z.enum(['deduction', 'risk', 'achievement', 'letter', 'system']),
  title: z.string(),
  body: z.string(),
  relatedId: z.string().optional(),
  relatedType: z.enum(['deduction', 'student']).optional(),
  isRead: z.boolean().default(false),
  isPushed: z.boolean().default(false),
  priority: z.enum(['low', 'normal', 'high']).default('normal'),
  readAt: z.date().optional(),
  pushedAt: z.date().optional(),
  createdAt: z.date().optional(),
});
export type Notification = z.infer<typeof NotificationSchema>;

// ---------- Parent Contact ----------
export const ParentContactSchema = z.object({
  _id: z.string().optional(),
  studentId: z.string(),
  parentName: z.string().min(2),
  relationship: z.string().min(1),
  email: z.string().email().optional(),
  phone: z.string().optional(),
  whatsapp: z.string().optional(),
  preference: z.enum(['email', 'sms', 'whatsapp', 'none']).default('email'),
  isPrimary: z.boolean().default(false),
  createdAt: z.date().optional(),
  updatedAt: z.date().optional(),
});
export type ParentContact = z.infer<typeof ParentContactSchema>;

export const CreateParentContactSchema = z.object({
  studentId: z.string(),
  parentName: z.string().min(2),
  relationship: z.string().min(1),
  email: z.string().email().optional(),
  phone: z.string().optional(),
  whatsapp: z.string().optional(),
  preference: z.enum(['email', 'sms', 'whatsapp', 'none']).default('email'),
  isPrimary: z.boolean().default(false),
});

// ---------- Settings ----------
export const SettingsSchema = z.object({
  _id: z.string().optional(),
  key: z.string(),
  value: z.string(),
  type: z.enum(['string', 'number', 'boolean']).default('string'),
  updatedAt: z.date().optional(),
});
export type Settings = z.infer<typeof SettingsSchema>;

// ---------- API Envelopes ----------
export const ApiErrorSchema = z.object({
  success: z.literal(false),
  error: z.object({
    code: z.string(),
    message: z.string(),
    field: z.string().optional(),
  }),
});

export const ApiSuccessSchema = <T extends z.ZodTypeAny>(dataSchema: T) =>
  z.object({
    success: z.literal(true),
    data: dataSchema,
  });

// ---------- Constants ----------
export const MAX_MARKS_PER_TERM = 40;
export const DEFAULT_MARKS = 40;
export const LOW_MARKS_THRESHOLD_DEFAULT = 20;
export const TERMS = [1, 2, 3] as const;
export type Term = (typeof TERMS)[number];

export const RISK_RULES = [
  { level: 'Critical' as const, incidents: 8, deductions: 40 },
  { level: 'High Risk' as const, score: 19 },
  { level: 'Medium Risk' as const, score: 29 },
  { level: 'Warning' as const, incidents: 3, deductions: 15 },
  { level: 'Excellent' as const },
] as const;

export { z };
