# SBMS Vercel Deployment Guide

This document provides step-by-step instructions to deploy the School Business Management System (SBMS) to Vercel.

## Prerequisites

- GitHub or GitLab account with the SBMS repository
- Vercel account (free at https://vercel.com)
- MySQL/MariaDB database hosted on a service like:
  - PlanetScale (MySQL-compatible, free tier)
  - AWS RDS
  - DigitalOcean Managed Databases
  - Any other managed MySQL service

## Deployment Steps

### 1. Push Code to GitHub

```bash
git remote add origin https://github.com/YOUR_USERNAME/sbms.git
git branch -M main
git push -u origin main
```

### 2. Sign Up for Vercel

Visit https://vercel.com/signup and sign up with GitHub/GitLab

### 3. Create Project in Vercel

1. Go to https://vercel.com/dashboard
2. Click "Add New Project"
3. Select your GitHub/GitLab account
4. Find and import the `sbms` repository
5. Click "Import"

### 4. Configure Environment Variables

In Vercel Dashboard:

1. Go to Project Settings → Environment Variables
2. Add these variables:

```
DB_HOST          = your-database-host.mysql.database.azure.com
DB_PORT          = 3306
DB_USER          = your_db_user
DB_PASSWORD      = your_db_password
DB_NAME          = sbms

APP_ENV          = production
APP_DEBUG        = false
APP_TIMEZONE     = Africa/Kigali

MAIL_HOST        = smtp.your-email-provider.com
MAIL_PORT        = 587
MAIL_FROM        = noreply@yourdomain.com

TWILIO_ACCOUNT_SID        = ACxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx
TWILIO_AUTH_TOKEN         = axxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx
TWILIO_PHONE_NUMBER       = +1415xxxxxxx
TWILIO_WHATSAPP_NUMBER    = +1415xxxxxxx
```

**Note:** Mark sensitive variables (DB_PASSWORD, TWILIO_AUTH_TOKEN) as Sensitive.

### 5. Deploy

The deployment happens automatically when you push to the main branch. You can also:

1. Click "Deploy" button in Vercel Dashboard
2. Monitor deployment progress in the "Deployments" tab

### 6. Verify Deployment

After deployment completes:

1. Click the deployment URL
2. Test the health endpoint: `https://your-domain.vercel.app/api/health`
3. You should see: `{"status":"ok","message":"SBMS API is running"}`

## Database Setup

Before first deployment, set up your database:

### Option 1: PlanetScale (Recommended for MySQL)

1. Sign up at https://planetscale.com
2. Create a new database `sbms`
3. Get connection credentials from "Connect" button
4. Import database schema:
   ```bash
   mysql -h your-host.mysql.planetscale.com -u your_user -p sbms < database/schema.sql
   ```

### Option 2: AWS RDS

1. Create RDS MySQL instance
2. Get endpoint, username, password
3. Add security group rule to allow Vercel IPs
4. Import schema as shown above

### Option 3: DigitalOcean Managed Database

1. Create Managed MySQL database in DO console
2. Get connection info
3. Import schema

## Post-Deployment Tasks

### 1. SSL/HTTPS
- Vercel provides free SSL automatically ✅

### 2. Custom Domain
1. In Vercel → Project Settings → Domains
2. Add your domain
3. Update DNS records as instructed

### 3. Email Service
Set up SMTP provider:
- SendGrid (free tier available)
- Mailgun
- AWS SES
- Gmail (not recommended for production)

### 4. Twilio Setup
See [WHATSAPP_SETUP.md](WHATSAPP_SETUP.md) for WhatsApp integration instructions.

### 5. Monitoring
Set up monitoring in Vercel Dashboard to track:
- Build times
- Response times
- Error rates
- Function execution

## Troubleshooting

### Build Fails
- Check build logs in Vercel Dashboard
- Ensure `npm run build` works locally: `npm run build`
- Check for required dependencies in package.json

### 502 Bad Gateway
- Check if database is accessible
- Verify environment variables are set correctly
- Check Vercel function logs for PHP errors

### Database Connection Errors
- Verify connection string in environment variables
- Check database security group allows Vercel IPs
- Ensure database user has proper permissions

### CORS Issues
Set headers in `vercel.json` if needed:
```json
"headers": [
  {
    "source": "/api/(.*)",
    "headers": [
      { "key": "Access-Control-Allow-Origin", "value": "*" }
    ]
  }
]
```

## Performance Tips

1. **Enable caching** for static assets in vercel.json
2. **Use database connection pooling**
3. **Compress database backups**
4. **Monitor function duration** - optimize slow queries
5. **Use Vercel Analytics** to track performance

## Rollback

To rollback to a previous version:

1. Go to Vercel Dashboard → Deployments
2. Find the previous working deployment
3. Click the three-dots menu → "Promote to Production"

## Support & Resources

- Vercel Docs: https://vercel.com/docs
- PHP on Vercel: https://vercel.com/docs/concepts/functions/serverless-functions/runtimes/php
- Next Steps: Implement WebSocket support for real-time notifications

---

**Last Updated:** $(date)
**Deployment Status:** Ready for Vercel
