# SBMS Mobile - Agent Instructions

## Project Structure
- `apps/api` - Node.js + Express + TypeScript + MongoDB (Mongoose)
- `apps/mobile` - React Native + TypeScript + Expo Router
- `packages/shared` - Shared TypeScript types, Zod schemas, constants

## Commands
- `npm run dev:api` - Start API in watch mode
- `npm run dev:mobile` - Start Expo dev server
- `npm run build:api` - Build API TypeScript
- `npm run lint` - Lint all workspaces
- `npm test` - Run all tests

## Environment
Copy `.env.example` to `.env` before running services.

## Stack
- Backend: Node 20, Express, Mongoose, Redis, BullMQ, Socket.io, JWT
- Mobile: React Native, Expo, TypeScript, Zustand, React Query, SecureStore
- Database: MongoDB 7
