# SBMS Mobile App

Student Behavior Management System — React Native (TypeScript) + Node.js + MongoDB.

## Structure
- `apps/api` — Node.js/Express REST API + Mongoose models
- `apps/mobile` — React Native + Expo Router + TypeScript
- `packages/shared` — Shared Zod schemas + TypeScript types (API contract)
- `migrations/` — MySQL → MongoDB ETL script

## Prerequisites
- Node 20+
- MongoDB 7
- Redis 7
- Expo CLI (for mobile)

## Quick Start

### 1. Install dependencies
```bash
cd sbms-mobile
npm install
```

### 2. Start infrastructure
```bash
docker-compose up -d mongodb redis
```

### 3. Seed the API
```bash
npm run dev:api
```
This auto-seeds roles, default school year (2026-2027), settings, and an admin user:
- Email: `admin@sbms.local`
- Password: `Admin123!`

### 4. Start the mobile app
```bash
npm run dev:mobile
```

## Default Admin
- `admin@sbms.local` / `Admin123!`

## API Base
`http://localhost:4000/api/v1`

## Migration from legacy PHP/MySQL
```bash
node migrations/mysql-to-mongo.js
```
Set `SBMS_DB_*` and `MONGO_URI` env vars first.
