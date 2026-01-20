# PDF Tool Suite - AI Development Guidelines

This document provides context for AI agents working on the PDF Tool Suite application.

## Project Overview

PDF Tool Suite is a freemium Laravel 12 web application that provides 7 PDF manipulation tools. All PDF processing happens client-side using pdf-lib JavaScript library for maximum privacy.

## Architecture Principles

1. **Client-Side Processing**: All PDF operations (merge, split, compress, etc.) happen in the browser using pdf-lib. Files never leave the user's device.
2. **Freemium Model**: Free tier allows 3 operations per day. Pro ($4.99/month) and Lifetime ($29) provide unlimited access.
3. **Privacy First**: No server-side PDF processing - this is a key selling point.

## Key Technologies

- **Framework**: Laravel 12
- **Frontend**: Blade templates + Alpine.js + Tailwind CSS
- **PDF Library**: pdf-lib (JavaScript, runs in browser)
- **Payments**: Laravel Cashier (Stripe)
- **Authentication**: Laravel Breeze + Laravel Socialite
- **Database**: SQLite

## Directory Structure

```
app/
├── Http/Controllers/
│   ├── HomeController.php          # Homepage
│   ├── ToolController.php          # Tool pages and operation tracking
│   ├── PricingController.php       # Pricing and Stripe checkout
│   ├── WebhookController.php       # Stripe webhook handlers
│   ├── SocialAuthController.php    # OAuth (Google/GitHub)
│   ├── DashboardController.php     # User dashboard
│   └── Auth/                       # Authentication controllers
├── Models/
│   ├── User.php                    # User model with Billable trait
│   └── Operation.php               # Operation tracking model
└── Http/Middleware/
    └── CheckOperationLimit.php    # Enforces 3 operations/day limit

resources/
├── views/
│   ├── layouts/app.blade.php       # Main layout
│   ├── home.blade.php              # Homepage
│   ├── tools/                      # 7 tool pages
│   ├── pricing.blade.php
│   ├── dashboard.blade.php
│   └── auth/                       # Login, register, forgot password
├── js/
│   ├── app.js                      # Alpine.js setup
│   └── pdf-tools.js                # All PDF operations (pdf-lib)
└── css/
    └── app.css                     # Tailwind imports
```

## PDF Processing Logic

All PDF operations are implemented in `resources/js/pdf-tools.js` using pdf-lib:

- `mergePdfs()` - Combines multiple PDFs
- `splitPdf()` - Extracts specific pages
- `splitPdfIntoChunks()` - Splits into N-page chunks
- `compressPdf()` - Re-encodes PDF for compression
- `pdfToImage()` - Converts PDF pages to images (requires pdf.js)
- `imageToPdf()` - Converts images to PDF
- `rotatePdf()` - Rotates pages by 90/180/270 degrees
- `reorderPdf()` - Reorders pages based on array

**Important**: PDF to image conversion currently throws an error as it requires pdf.js library. This should be implemented separately if needed.

## Operation Tracking

- Free users: Limited to 3 operations per day
- Pro/Lifetime users: Unlimited operations
- Operations are tracked in `operations` table with `user_id`, `operation_type`, and `created_at`
- Daily limit is enforced via `CheckOperationLimit` middleware and `User::canPerformOperation()` method

## Stripe Integration

- Pro subscription: Recurring $4.99/month
- Lifetime: One-time $29 payment
- Webhook handles `checkout.session.completed` and `customer.subscription.deleted`
- Lifetime purchases set `lifetime_access` flag on user
- Subscription status checked via `User::hasUnlimitedAccess()` method

## SEO Strategy

Each tool page includes:
- Unique title/description/keywords meta tags
- Schema.org WebApplication JSON-LD markup
- Targeted keywords for SEO (e.g., "merge pdf online free")
- Sitemap.xml and robots.txt in public directory

## Code Style

- Follow Laravel conventions
- Use Blade templates for views
- Alpine.js for client-side interactivity
- Tailwind CSS for styling
- Keep PDF processing logic in JavaScript (client-side)

## Testing Considerations

When adding new features:
1. Ensure client-side processing remains client-side
2. Verify operation tracking works correctly
3. Test Stripe webhook handlers
4. Check SEO meta tags are unique per tool
5. Verify mobile responsiveness

## Common Patterns

- Controllers are thin - delegate to models/services
- User subscription status checked via `hasUnlimitedAccess()` method
- Operation limits checked via `canPerformOperation()` method
- All PDF operations return blob URLs for download
