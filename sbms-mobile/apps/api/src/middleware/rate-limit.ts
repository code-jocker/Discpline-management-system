import { Request, Response, NextFunction } from 'express';

export function rateLimiter(windowMs: number, max: number) {
  const hits = new Map<string, { count: number; reset: number }>();

  return (req: Request, res: Response, next: NextFunction): void => {
    const key = req.ip ?? 'unknown';
    const now = Date.now();
    const record = hits.get(key);

    if (!record || now > record.reset) {
      hits.set(key, { count: 1, reset: now + windowMs });
      next();
      return;
    }

    if (record.count >= max) {
      res.status(429).json({
        success: false,
        error: {
          code: 'RATE_LIMITED',
          message: 'Too many requests, please try again later',
        },
      });
      return;
    }

    record.count++;
    next();
  };
}

export const loginRateLimit = rateLimiter(60 * 60 * 1000, 30); // 30 per hour
export const generalRateLimit = rateLimiter(60 * 1000, 60); // 60 per minute
