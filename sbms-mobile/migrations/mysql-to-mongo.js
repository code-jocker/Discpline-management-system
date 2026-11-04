/**
 * SBMS Legacy MySQL -> MongoDB migration script
 * Run: node migrations/mysql-to-mongo.js
 */
require('dotenv').config({ path: require('path').resolve(__dirname, '..', '.env') });

const { createClient } = require('mongodb');
const mysql = require('mysql2/promise');

async function main() {
  const mysqlConn = await mysql.createConnection({
    host: process.env.SBMS_DB_HOST || 'localhost',
    port: parseInt(process.env.SBMS_DB_PORT || '3306', 10),
    user: process.env.SBMS_DB_USER || 'root',
    password: process.env.SBMS_DB_PASS || '',
    database: process.env.SBMS_DB_NAME || 'sbms',
  });

  const mongo = await createClient(process.env.MONGO_URI || 'mongodb://localhost:27017/sbms');
  await mongo.connect();
  const db = mongo.db(process.env.MONGO_DB_NAME || 'sbms');

  const idMap = new Map(); // legacy table + id -> new mongo id

  // 1. Roles
  const [roles] = await mysqlConn.query('SELECT * FROM roles');
  const roleDocs = roles.map((r) => ({ name: r.name, permissions: r.permissions ? JSON.parse(r.permissions) : [], isPrivileged: !!r.is_privileged }));
  const roleRes = await db.collection('roles').insertMany(roleDocs);
  roles.forEach((r, i) => idMap.set(`roles:${r.id}`, roleRes.insertedIds[i].toString()));

  // 2. Users
  const [users] = await mysqlConn.query('SELECT u.*, r.name AS role_name FROM users u LEFT JOIN roles r ON r.id = u.role_id');
  const userDocs = users.map((u) => ({
    email: u.email,
    username: u.username,
    passwordHash: u.password_hash,
    fullName: u.full_name,
    profilePicture: u.profile_picture,
    roleId: idMap.get(`roles:${u.role_id}`),
    roleName: u.role_name,
    isActive: true,
  }));
  const userRes = await db.collection('users').insertMany(userDocs);
  users.forEach((u, i) => idMap.set(`users:${u.id}`, userRes.insertedIds[i].toString()));

  // 3. School Years
  const [years] = await mysqlConn.query('SELECT * FROM school_years');
  const yearDocs = years.map((y) => ({
    name: y.name, startYear: y.start_year, endYear: y.end_year,
    startMonth: y.start_month, endMonth: y.end_month,
    isActive: !!y.is_active, isArchived: !!y.is_archived, isLocked: !!y.is_locked,
  }));
  const yearRes = await db.collection('school_years').insertMany(yearDocs);
  years.forEach((y, i) => idMap.set(`school_years:${y.id}`, yearRes.insertedIds[i].toString()));

  // 4. Classes
  const [classes] = await mysqlConn.query('SELECT * FROM classes');
  const classDocs = classes.map((c) => ({
    name: c.name, categoryId: c.category_id ? idMap.get(`categories:${c.category_id}`) : undefined,
    categoryName: c.category_name,
    classTeacherUserId: c.class_teacher_user_id ? idMap.get(`users:${c.class_teacher_user_id}`) : undefined,
    schoolYearId: idMap.get(`school_years:${c.school_year_id}`),
    studentCount: c.student_count || 0,
  }));
  const classRes = await db.collection('classes').insertMany(classDocs);
  classes.forEach((c, i) => idMap.set(`classes:${c.id}`, classRes.insertedIds[i].toString()));

  // 5. Students
  const [students] = await mysqlConn.query('SELECT * FROM students');
  const studentDocs = students.map((s) => ({
    studentCode: s.student_code,
    fullName: s.full_name,
    classId: idMap.get(`classes:${s.class_id}`),
    className: s.class_name,
    parentContact: s.parent_contact,
    schoolYearId: idMap.get(`school_years:${s.school_year_id}`),
    isActive: !!s.is_active,
    isBlacklisted: !!s.is_blacklisted,
    isExpelled: !!s.is_expelled,
  }));
  const studentRes = await db.collection('students').insertMany(studentDocs);
  students.forEach((s, i) => idMap.set(`students:${s.id}`, studentRes.insertedIds[i].toString()));

  // 6. Settings
  const [settings] = await mysqlConn.query('SELECT * FROM settings');
  const settingDocs = settings.map((s) => ({ key: s.key_name, value: s.value, type: s.type || 'string' }));
  await db.collection('settings').insertMany(settingDocs);

  console.log('Migration complete.');
  console.log('Roles:', roleRes.insertedIds.length);
  console.log('Users:', userRes.insertedIds.length);
  console.log('School years:', yearRes.insertedIds.length);
  console.log('Classes:', classRes.insertedIds.length);
  console.log('Students:', studentRes.insertedIds.length);

  await mysqlConn.end();
  await mongo.close();
}

main().catch((err) => {
  console.error(err);
  process.exit(1);
});
