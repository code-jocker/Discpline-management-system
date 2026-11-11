# Authentication Security Changes

This document records the authentication hardening applied on the `security/harden-authentication` branch.

## Files changed

- `auth/AuthController.php`
- `auth/register.php`

## Changes

### 1. Registration authorization is enforced server-side

After the first account has been created, account creation now requires both:

- An authenticated session.
- The `manage_users` permission.

The controller checks this itself; it does not trust the boolean supplied by the route. The registration page also redirects unauthorized GET requests and returns HTTP 403 for unauthorized POST requests.

The one-time bootstrap path remains available while the users table is empty. It selects the configured privileged bootstrap role instead of trusting a submitted role ID.

### 2. Prevent crafted requests from assigning hidden privileged roles

For non-bootstrap registrations, the selected role must be among the role options exposed by `User::getRoleOptions()`. That method excludes the `admin` role, so a crafted POST cannot bypass the UI and assign that hidden role.

Review the role policy before deployment if your institution intentionally allows some user managers to assign additional privileged roles.

### 3. Removed legacy demo-password authentication

Login now accepts only passwords that verify against the stored password hash. The hard-coded demo-email/password bypass has been removed. Demo accounts must be migrated to normal password hashes and assigned unique credentials before deployment.

### 4. Password reset token is no longer returned or logged

The reset URL is sent only through the configured email path. It is no longer included in the JSON response and is not written to application logs if email delivery fails. The endpoint continues to return a generic response to avoid revealing whether an email address is registered.

## Existing authentication mechanisms retained

- PHP sessions with session ID regeneration after login.
- Password hashing via `password_hash()` and verification via `password_verify()`.
- Remember-me selector/validator tokens with hashed validators in the database.
- One-hour password reset token expiry and hashed token storage.
- CSRF checks on registration POST requests.
- Permission checks and audit logging.

## Deployment and verification checklist

1. Back up the database and application files.
2. Deploy this branch to a staging environment first.
3. Confirm the initial administrator bootstrap works only when the users table is empty.
4. Confirm anonymous users and ordinary staff receive HTTP 403 or a login redirect when opening or posting to registration.
5. Confirm a user with `manage_users` can create permitted staff roles.
6. Confirm a crafted POST with a hidden privileged role ID is rejected.
7. Confirm demo credentials no longer authenticate; reset those accounts to unique passwords before production.
8. Confirm password-reset responses never contain `reset_url` and logs never contain reset tokens or URLs.
9. Run the application's PHP linting and authentication regression tests.
10. Review the production HTTPS/proxy configuration and ensure secure cookies are enabled.

## Important follow-up

The bootstrap check is based on the user count. For environments where two first-time setup requests could run concurrently, protect bootstrap account creation with a database-level transaction/lock or a one-time setup secret. Also configure a reliable mail transport; a reset token must never be exposed as a browser response or log fallback.
