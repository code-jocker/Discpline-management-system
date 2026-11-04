import { Request, Response, NextFunction } from 'express';

export function errorHandler(err: any, req: Request, res: Response, next: NextFunction): void {
  // eslint-disable-next-line no-console
  console.error('API Error:', err);

  if (err.name === 'ValidationError') {
    res.status(400).json({ success: false, error: { code: 'VALIDATION_ERROR', message: err.message } });
    return;
  }

  if (err.name === 'CastError') {
    res.status(400).json({ success: false, error: { code: 'INVALID_ID', message: 'Invalid resource ID' } });
    return;
  }

  if (err.code === 11000) {
    res.status(409).json({ success: false, error: { code: 'DUPLICATE', message: 'Resource already exists' } });
    return;
  }

  const status = err.statusCode || 500;
  res.status(status).json({
    success: false,
    error: {
      code: err.code || 'INTERNAL_ERROR',
      message: process.env.NODE_ENV === 'production' ? 'Internal server error' : err.message,
    },
  });
}

export function notFound(req: Request, res: Response): void {
  res.status(404).json({ success: false, error: { code: 'NOT_FOUND', message: 'Route not found' } });
}
