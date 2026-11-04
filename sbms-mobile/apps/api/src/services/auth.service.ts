import { User } from '../models/user.model';
import { Role } from '../models/role.model';
import { AuditLog } from '../models/audit-log.model';
import { config } from '../config/env';
import jwt from 'jsonwebtoken';
import bcrypt from 'bcryptjs';

export interface TokenPair {
  accessToken: string;
  refreshToken: string;
}

export class AuthService {
  async register(data: {
    fullName: string;
    username: string;
    email: string;
    password: string;
    role: string;
  }) {
    const existing = await User.findOne({
      $or: [{ email: data.email.toLowerCase() }, { username: data.username }],
    });
    if (existing) {
      throw Object.assign(new Error('User already exists'), { statusCode: 409 });
    }

    const roleDoc = await Role.findOne({ name: data.role });
    if (!roleDoc) {
      throw Object.assign(new Error('Invalid role'), { statusCode: 400 });
    }

    const passwordHash = await bcrypt.hash(data.password, 12);

    const user = await User.create({
      email: data.email.toLowerCase(),
      username: data.username,
      passwordHash,
      fullName: data.fullName,
      roleId: roleDoc._id,
      roleName: data.role,
    });

    await AuditLog.create({
      userId: user._id,
      action: 'user.register',
      entity: 'User',
      entityId: user._id.toString(),
    });

    const tokens = this.generateTokens(user);

    return {
      user: this.sanitize(user),
      ...tokens,
    };
  }

  async login(data: { login: string; password: string; ipAddress?: string }) {
    const user = await User.findOne({
      $or: [
        { email: data.login.toLowerCase() },
        { username: data.login.toLowerCase() },
      ],
    }).populate('roleId');

    if (!user || !(await bcrypt.compare(data.password, user.passwordHash))) {
      throw Object.assign(new Error('Invalid credentials'), { statusCode: 401 });
    }

    if (!user.isActive) {
      throw Object.assign(new Error('Account deactivated'), { statusCode: 403 });
    }

    user.lastLoginAt = new Date();
    await user.save();

    await AuditLog.create({
      userId: user._id,
      action: 'user.login',
      entity: 'User',
      entityId: user._id.toString(),
      ipAddress: data.ipAddress,
    });

    const tokens = this.generateTokens(user);

    return {
      user: this.sanitize(user),
      ...tokens,
    };
  }

  async refresh(refreshToken: string) {
    let decoded: any;
    try {
      decoded = jwt.verify(refreshToken, config.jwtRefreshSecret);
    } catch {
      throw Object.assign(new Error('Invalid refresh token'), { statusCode: 401 });
    }

    const user = await User.findById(decoded.userId);
    if (!user || !user.isActive) {
      throw Object.assign(new Error('User not found'), { statusCode: 401 });
    }

    return this.generateTokens(user);
  }

  async logout() {
    // Stateless JWT: client discards tokens. Optionally maintain a blocklist in Redis.
    return { success: true };
  }

  private generateTokens(user: any): TokenPair {
    const payload = {
      userId: user._id,
      email: user.email,
      role: user.roleName,
    };

    const accessToken = jwt.sign(payload, config.jwtAccessSecret, {
      expiresIn: config.jwtAccessTtl,
    });

    const refreshToken = jwt.sign(payload, config.jwtRefreshSecret, {
      expiresIn: config.jwtRefreshTtl,
    });

    return { accessToken, refreshToken };
  }

  private sanitize(user: any) {
    return {
      id: user._id,
      fullName: user.fullName,
      username: user.username,
      email: user.email,
      role: user.roleName,
      roleName: user.roleName,
      profilePicture: user.profilePicture,
    };
  }
}
