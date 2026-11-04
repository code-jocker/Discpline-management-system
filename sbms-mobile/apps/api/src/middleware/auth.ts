import { Request, Response, NextFunction } from 'express';
import jwt from 'jsonwebtoken';
import { config } from '../config/env';
import { User } from '../models';

export interface AuthRequest extends Request {
  user?: {
    id: string;
    email: string;
    username: string;
    fullName: string;
    role: string;
    roleName: string;
    roleId: string;
  };
}

export function authenticate(
  req: AuthRequest,
  res: Response,
  next: NextFunction
): void {
  const authHeader = req.headers.authorization;

  if (!authHeader || !authHeader.startsWith('Bearer ')) {
    res.status(401).json({
      success: false,
      error: { code: 'UNAUTHORIZED', message: 'Missing or invalid token' },
    });
    return;
  }

  const token = authHeader.substring(7);

  try {
    const decoded = jwt.verify(token, config.jwtAccessSecret) as {
      userId: string;
      email: string;
      role: string;
    };

    req.user = {
      id: decoded.userId,
      email: decoded.email,
      username: '',
      fullName: '',
      role: decoded.role,
      roleName: '',
      roleId: '',
    };

    next();
  } catch {
    res.status(401).json({
      success: false,
      error: { code: 'UNAUTHORIZED', message: 'Token expired or invalid' },
    });
  }
}

export function optionalAuth(
  req: AuthRequest,
  res: Response,
  next: NextFunction
): void {
  const authHeader = req.headers.authorization;
  if (!authHeader || !authHeader.startsWith('Bearer ')) {
    next();
    return;
  }

  const token = authHeader.substring(7);
  try {
    const decoded = jwt.verify(token, config.jwtAccessSecret) as {
      userId: string;
      email: string;
      role: string;
    };
    req.user = {
      id: decoded.userId,
      email: decoded.email,
      username: '',
      fullName: '',
      role: decoded.role,
      roleName: '',
      roleId: '',
    };
  } catch {
    // ignore invalid token for optional auth
  }
  next();
}

export function requireRoles(...allowedRoles: string[]) {
  return (req: AuthRequest, res: Response, next: NextFunction): void => {
    if (!req.user) {
      res.status(401).json({
        success: false,
        error: { code: 'UNAUTHORIZED', message: 'Authentication required' },
      });
      return;
    }

    if (!allowedRoles.includes(req.user.role)) {
      res.status(403).json({
        success: false,
        error: {
          code: 'FORBIDDEN',
          message: 'Insufficient role permissions',
        },
      });
      return;
    }

    next();
  };
}

export function requirePermission(permission: string) {
  return (req: AuthRequest, res: Response, next: NextFunction): void => {
    if (!req.user) {
      res.status(401).json({
        success: false,
        error: { code: 'UNAUTHORIZED', message: 'Authentication required' },
      });
      return;
    }

    const privileged = [
      'admin',
      'headteacher',
      'it',
      'deputy_headteacher_discipline',
    ];

    if (privileged.includes(req.user.role)) {
      next();
      return;
    }

    // Teacher and below: check permission against role (simplified)
    // In production, fetch role permissions from DB
    const teacherPermissions = [
      'deduct_marks',
      'view_students',
      'view_discipline_history',
      'create_parent_contact',
    ];

    if (!teacherPermissions.includes(permission)) {
      res.status(403).json({
        success: false,
        error: { code: 'FORBIDDEN', message: 'Permission denied' },
      });
      return;
    }

    next();
  };
}

export function errorHandler(
  err: any,
  req: Request,
  res: Response,
  next: NextFunction
): void {
  // eslint-disable-next-line no-console
  console.error('API Error:', err);

  if (err.name === 'ValidationError') {
    res.status(400).json({
      success: false,
      error: {
        code: 'VALIDATION_ERROR',
        message: err.message,
      },
    });
    return;
  }

  if (err.name === 'CastError') {
    res.status(400).json({
      success: false,
      error: { code: 'INVALID_ID', message: 'Invalid resource ID' },
    });
    return;
  }

  if (err.code === 11000) {
    res.status(409).json({
      success: false,
      error: { code: 'DUPLICATE', message: 'Resource already exists' },
    });
    return;
  }

  const status = err.statusCode || 500;
  res.status(status).json({
    success: false,
    error: {
      code: err.code || 'INTERNAL_ERROR',
      message:
        process.env.NODE_ENV === 'production'
          ? 'Internal server error'
          : err.message,
    },
  });
}
