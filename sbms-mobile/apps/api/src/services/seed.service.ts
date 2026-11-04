import { Settings } from '../models/settings.model';

export class SeedService {
  async seed() {
    // Default roles
    const roles = [
      { name: 'admin', permissions: ['*'], isPrivileged: true },
      { name: 'headteacher', permissions: ['*'], isPrivileged: true },
      { name: 'it', permissions: ['*'], isPrivileged: true },
      { name: 'teacher', permissions: ['deduct_marks', 'view_students', 'view_discipline_history', 'create_parent_contact'], isPrivileged: false },
      { name: 'deputy_headteacher_discipline', permissions: ['deduct_marks', 'view_students', 'view_discipline_history', 'manage_blacklist'], isPrivileged: true },
    ];

    for (const r of roles) {
      await require('../models/role.model').Role.findOneAndUpdate(
        { name: r.name },
        r,
        { upsert: true }
      );
    }

    // Default settings
    const settings = [
      { key: 'current_term', value: '1', type: 'string' },
      { key: 'max_marks_per_term', value: '40', type: 'string' },
      { key: 'low_marks_threshold', value: '20', type: 'string' },
    ];

    for (const s of settings) {
      await Settings.findOneAndUpdate({ key: s.key }, s, { upsert: true });
    }

    // Default school year
    const sy = await require('../models/school-year.model').SchoolYear.findOneAndUpdate(
      { name: '2026-2027' },
      {
        name: '2026-2027',
        startYear: 2026,
        endYear: 2027,
        startMonth: 9,
        endMonth: 7,
        isActive: true,
      },
      { upsert: true }
    );

    // Admin user
    const RoleModel = require('../models/role.model').Role;
    const UserModel = require('../models/user.model').User;
    const bcrypt = require('bcryptjs');

    const adminRole = await RoleModel.findOne({ name: 'admin' });
    if (adminRole && !(await UserModel.findOne({ email: 'admin@sbms.local' }))) {
      const passwordHash = await bcrypt.hash('Admin123!', 12);
      await UserModel.create({
        email: 'admin@sbms.local',
        username: 'admin',
        passwordHash,
        fullName: 'System Administrator',
        roleId: adminRole._id,
        roleName: 'admin',
      });
    }

    console.log('[Seed] Database seeded successfully');
  }
}
