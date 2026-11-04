import { Request, Response, NextFunction } from 'express';
import { z, ZodTypeAny } from 'zod';

export function validate(schema: ZodTypeAny, source: 'body' | 'query' | 'params' = 'body') {
  return (req: Request, res: Response, next: NextFunction): void => {
    const result = schema.safeParse(req[source]);

    if (!result.success) {
      res.status(400).json({
        success: false,
        error: {
          code: 'VALIDATION_ERROR',
          message: 'Invalid request data',
          field: result.error.issues[0]?.path.join('.'),
        },
      });
      return;
    }

    req[source] = result.data as any;
    next();
  };
}
