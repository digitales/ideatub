# Deployment Guide - PDF Tool Suite (Laravel 12)

This guide walks you through deploying the PDF Tool Suite to Railway.app using Laravel 12.

## Prerequisites

- GitHub account
- Railway account (sign in with GitHub at https://railway.app)
- Stripe account (https://dashboard.stripe.com/register)
- Google Cloud Console account (for OAuth)
- Domain name (optional, Railway provides one)

## Step 1: Set Up OAuth Providers

### Google OAuth Setup

1. Go to [Google Cloud Console](https://console.cloud.google.com)
2. Create a new project or select existing one
3. Navigate to **APIs & Services** → **Credentials**
4. Click **Create Credentials** → **OAuth 2.0 Client ID**
5. Configure consent screen if prompted
6. Application type: **Web application**
7. Add authorized redirect URI: `https://your-app.up.railway.app/auth/google/callback`
8. Copy **Client ID** and **Client Secret**

### GitHub OAuth Setup

1. Go to GitHub → **Settings** → **Developer settings** → **OAuth Apps**
2. Click **New OAuth App**
3. Fill in:
   - Application name: "PDF Tools" (or your choice)
   - Homepage URL: `https://your-app.up.railway.app`
   - Authorization callback URL: `https://your-app.up.railway.app/auth/github/callback`
4. Click **Register application**
5. Copy **Client ID** and generate **Client Secret**

## Step 2: Set Up Stripe

1. Go to [Stripe Dashboard](https://dashboard.stripe.com)
2. Get API keys:
   - Navigate to **Developers** → **API keys**
   - Copy **Publishable key** (starts with `pk_`)
   - Copy **Secret key** (starts with `sk_`)

3. Create products:
   - Go to **Products** → **Add product**
   - **Pro Monthly**:
     - Name: "Pro Monthly"
     - Price: $4.99/month (recurring)
     - Copy **Price ID** (starts with `price_`)
   - **Lifetime**:
     - Name: "Lifetime Access"
     - Price: $29.00 (one-time)
     - Copy **Price ID** (starts with `price_`)

4. Set up webhook (after deployment):
   - Go to **Developers** → **Webhooks**
   - Click **Add endpoint**
   - URL: `https://your-app.up.railway.app/stripe/webhook`
   - Events to send:
     - `checkout.session.completed`
     - `customer.subscription.deleted`
   - Copy **Signing secret** (starts with `whsec_`)

## Step 3: Push Code to GitHub

```bash
cd /path/to/pdf-tools
git init
git add .
git commit -m "Initial commit"
gh repo create pdf-tools --private --push
```

Or use GitHub web interface to create a repository and push.

## Step 4: Deploy to Railway

1. Go to [Railway](https://railway.app/new)
2. Click **Deploy from GitHub repo**
3. Select your `pdf-tools` repository
4. Railway will detect the Dockerfile and start building

## Step 5: Configure Environment Variables

In Railway dashboard, go to your project → **Variables** and add:

```
APP_NAME="PDF Tool Suite"
APP_ENV=production
APP_KEY=base64:YOUR_GENERATED_KEY_HERE
APP_DEBUG=false
APP_URL=https://your-app.up.railway.app

DB_CONNECTION=sqlite
DB_DATABASE=/var/www/html/database/database.sqlite

STRIPE_KEY=pk_live_...
STRIPE_SECRET=sk_live_...
STRIPE_WEBHOOK_SECRET=whsec_...
STRIPE_PRICE_MONTHLY=price_...
STRIPE_PRICE_LIFETIME=price_...

GOOGLE_CLIENT_ID=your_google_client_id
GOOGLE_CLIENT_SECRET=your_google_client_secret
GOOGLE_REDIRECT_URI=https://your-app.up.railway.app/auth/google/callback

GITHUB_CLIENT_ID=your_github_client_id
GITHUB_CLIENT_SECRET=your_github_client_secret
GITHUB_REDIRECT_URI=https://your-app.up.railway.app/auth/github/callback
```

**Important:** Generate `APP_KEY` by running locally:
```bash
php artisan key:generate --show
```

**Note:** Laravel 12 requires PHP 8.2+ and includes enhanced optimization commands. Ensure your Railway environment uses PHP 8.2 or higher.

## Step 6: Run Migrations

After deployment, run migrations:

1. In Railway dashboard, go to your project
2. Click on the service → **Deploy Logs**
3. Open **Shell** or use Railway CLI:
```bash
railway run php artisan migrate --force
```

## Step 7: Update Webhook URL in Stripe

1. Get your Railway URL (e.g., `https://pdf-tools.up.railway.app`)
2. Update Stripe webhook endpoint URL to match
3. Copy the webhook signing secret and add to Railway env vars

## Step 8: Update Sitemap

Edit `public/sitemap.xml` and replace `https://your-domain.com` with your actual Railway URL.

## Step 9: Verify Deployment

1. Visit your Railway URL
2. Test homepage loads
3. Test tool pages
4. Test authentication (email + OAuth)
5. Test Stripe checkout (use test mode first)
6. Verify webhook receives events

## Troubleshooting

### Database Issues
- Ensure SQLite file has write permissions
- Check storage directory permissions

### OAuth Not Working
- Verify redirect URIs match exactly
- Check environment variables are set correctly
- Ensure HTTPS is enabled (Railway provides this)

### Stripe Webhook Failing
- Verify webhook secret is correct
- Check webhook URL is accessible
- Review Railway logs for errors

### Assets Not Loading
- Run `npm run build` locally and commit
- Or add build step in Railway: `npm install && npm run build`

## Cost Estimate

- Railway: ~$5/month (first $5 free credit)
- Stripe: 2.9% + $0.30 per transaction
- Total: ~$5/month + transaction fees

## Next Steps

1. Set up custom domain (optional)
2. Configure email service for password resets
3. Set up monitoring/analytics
4. Submit sitemap to Google Search Console
5. Start SEO optimization
