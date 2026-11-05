'use strict';

/**
 * MySQL/MariaDB connection pool for the SBMS notification queue.
 *
 * All configuration comes from the environment so the same code runs against
 * the XAMPP dev database and the production database without edits.
 */

require('dotenv').config();

const mysql = require('mysql2/promise');

const pool = mysql.createPool({
  host: process.env.DB_HOST || '127.0.0.1',
  port: Number(process.env.DB_PORT) || 3306,
  user: process.env.DB_USER || 'root',
  // Empty string is a valid dev password (XAMPP root has none), so do not use ||.
  password: process.env.DB_PASSWORD || '',
  database: process.env.DB_NAME || 'sbms_legacy_run',
  waitForConnections: true,
  connectionLimit: 5,
  queueLimit: 0,
  // The worker builds SQL with `?` placeholders only; disabling named
  // placeholders keeps it from silently accepting a weaker pattern.
  namedPlaceholders: false,
  timezone: 'local',
  enableKeepAlive: true,
  keepAliveInitialDelay: 10000
});

module.exports = pool;
