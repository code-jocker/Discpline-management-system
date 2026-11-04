# SBMS Vercel Deployment - Quick Start

## What We've Set Up

✅ **Vercel Configuration** (`vercel.json`)
- Build command and output directory configured
- Environment variables defined

✅ **Environment Files**
- `.env.production` - Production environment template
- `.env.example` - Development reference

✅ **Frontend Build**
- Vite successfully builds to `assets/dist/`
- Ready for Vercel deployment

✅ **Git Repository**
- Initial commit created
- `.gitignore` configured
- Ready to push to GitHub

✅ **API Setup**
- Basic PHP serverless function at `/api/index.php`
- Health check endpoint: `GET /api/health`

✅ **Documentation**
- Full deployment guide: `VERCEL_DEPLOYMENT.md`

---

## Next Steps to Deploy

### 1. **Push to GitHub**

```bash
# Add your GitHub repository
git remote add origin https://github.com/YOUR_USERNAME/sbms.git
git branch -M main
git push -u origin main
```

### 2. **Create Vercel Account**
- Visit https://vercel.com/signup
- Sign up with GitHub

### 3. **Import Project**
- Go to https://vercel.com/dashboard
- Click "Add New Project"
- Select your `sbms` repository from GitHub
- Click "Import"

### 4. **Add Environment Variables**
In Vercel Project Settings → Environment Variables, add:

```
DB_HOST          = your-database-host
DB_PORT          = 3306
DB_USER          = your_db_user
DB_PASSWORD      = your_db_password
DB_NAME          = sbms
APP_ENV          = production
```

See `VERCEL_DEPLOYMENT.md` for complete list.

### 5. **Deploy**
- Vercel auto-deploys when you push to `main` branch
- Monitor in Vercel Dashboard

### 6. **Test**
```bash
curl https://your-vercel-domain.vercel.app/api/health
# Should return: {"status":"ok","message":"SBMS API is running"}
```

---

## Database Setup

**Before deploying, set up a hosted MySQL database:**

### Quick Options:
1. **PlanetScale** (MySQL-compatible, free) - https://planetscale.com
2. **AWS RDS** - https://aws.amazon.com/rds
3. **DigitalOcean** - https://www.digitalocean.com/products/managed-databases
4. **Heroku Postgres** (with MySQL adapter)

See `VERCEL_DEPLOYMENT.md` for detailed setup instructions.

---

## Current File Structure

```
sbms/
├── vercel.json                 ✅ Vercel config
├── .env.production            ✅ Production template
├── .gitignore                 ✅ Git ignore rules
├── VERCEL_DEPLOYMENT.md       ✅ Full guide
├── api/
│   └── index.php             ✅ Serverless API entry
├── assets/dist/              ✅ Frontend build output
├── package.json              ✅ Updated
└── [other app files]
```

---

## Cost Estimate

- **Vercel**: Free tier includes 100GB bandwidth/month
- **Database** (PlanetScale): Free tier up to 3GB
- **Email**: Free tier via SendGrid/Mailgun
- **Twilio**: Pay-per-use (~$0.01 per SMS, ~$0.08 per WhatsApp)

**Total Monthly**: €0-5 for small deployments

---

## Support

- Vercel Docs: https://vercel.com/docs
- Troubleshooting: See `VERCEL_DEPLOYMENT.md`
- Need help? Check the database provider's docs

---

**Status**: ✅ Ready to Deploy  
**All files committed**: Ready to push to GitHub
