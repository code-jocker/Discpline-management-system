import { Router } from 'express';
import { AuthController } from '../controllers/auth.controller';
import { StudentController } from '../controllers/student.controller';
import { DisciplineController } from '../controllers/discipline.controller';
import { NotificationController } from '../controllers/notification.controller';
import { ClassController } from '../controllers/class.controller';
import { SettingsController } from '../controllers/settings.controller';
import { RiskController } from '../controllers/risk.controller';
import { authenticate, optionalAuth, requireRoles, requirePermission } from '../middleware/auth';
import { validate } from '../middleware/validate';
import { loginRateLimit, generalRateLimit } from '../middleware/rate-limit';
import { RegisterSchema, LoginSchema, CreateStudentSchema, CreateDeductionSchema } from '@sbms/shared';

const router = Router();

// Auth (public)
router.post('/auth/register', generalRateLimit, validate(RegisterSchema), (req, res) => new AuthController().register(req, res));
router.post('/auth/login', loginRateLimit, validate(LoginSchema), (req, res) => new AuthController().login(req, res));
router.post('/auth/refresh', (req, res) => new AuthController().refresh(req, res));
router.post('/auth/logout', authenticate, (req, res) => new AuthController().logout(req, res));
router.get('/auth/me', authenticate, (req, res) => new AuthController().me(req, res));

// Students
router.get('/students', authenticate, (req, res) => new StudentController().list(req, res));
router.get('/students/:id', authenticate, (req, res) => new StudentController().getById(req, res));
router.post('/students', authenticate, requirePermission('create_parent_contact'), validate(CreateStudentSchema), (req, res) => new StudentController().create(req, res));
router.patch('/students/:id', authenticate, (req, res) => new StudentController().update(req, res));
router.delete('/students/:id', authenticate, requireRoles('admin'), (req, res) => new StudentController().delete(req, res));

// Discipline
router.post('/deductions', authenticate, requirePermission('deduct_marks'), validate(CreateDeductionSchema), (req, res) => new DisciplineController().deduct(req, res));
router.get('/deductions', authenticate, (req, res) => new DisciplineController().list(req, res));
router.get('/deductions/:id', authenticate, (req, res) => new DisciplineController().getById(req, res));

// Notifications
router.get('/notifications', authenticate, (req, res) => new NotificationController().list(req, res));
router.patch('/notifications/:id/read', authenticate, (req, res) => new NotificationController().markRead(req, res));
router.patch('/notifications/read-all', authenticate, (req, res) => new NotificationController().markAllRead(req, res));

// Classes
router.get('/classes', authenticate, (req, res) => new ClassController().list(req, res));

// Settings
router.get('/settings', authenticate, (req, res) => new SettingsController().getAll(req, res));
router.get('/settings/:key', authenticate, (req, res) => new SettingsController().get(req, res));
router.put('/settings/:key', authenticate, requireRoles('admin'), (req, res) => new SettingsController().set(req, res));

// Risk
router.post('/risk/assess-all', authenticate, requireRoles('admin', 'headteacher'), (req, res) => new RiskController().assessAll(req, res));

export default router;
