# PDF Tool Suite

A freemium web application for PDF operations with client-side processing for maximum privacy.

## Features

- **7 PDF Tools**: Merge, split, compress, PDF to image, image to PDF, rotate, and reorder pages
- **100% Client-Side Processing**: All PDF operations happen in the browser - files never leave your device
- **Freemium Model**: Free tier (3 operations/day) or upgrade to Pro ($4.99/month) or Lifetime ($29)
- **OAuth Authentication**: Sign in with Google, GitHub, or email/password
- **SEO Optimized**: Each tool has dedicated landing page with schema markup

## Tech Stack

- **Backend**: Laravel 12 (Latest version with AI-aware development features)
- **Frontend**: Blade templates + Alpine.js + Tailwind CSS
- **PDF Processing**: pdf-lib (JavaScript, client-side)
- **Payments**: Laravel Cashier (Stripe)
- **Authentication**: Laravel Breeze + Laravel Socialite
- **Database**: SQLite
- **AI Features (Optional)**: Laravel Boost/MCP for enhanced development experience

## Installation

### Prerequisites

- PHP 8.2+ (8.3 recommended for Laravel 12)
- Composer
- Node.js & npm
- SQLite

### Setup

1. Clone the repository:
```bash
git clone https://github.com/yourusername/pdf-tools.git
cd pdf-tools
```

2. Install PHP dependencies:
```bash
composer install
```

3. Install frontend dependencies:
```bash
npm install
```

4. Copy environment file:
```bash
cp .env.example .env
```

5. Generate application key:
```bash
php artisan key:generate
```

6. Create SQLite database:
```bash
touch database/database.sqlite
```

7. Run migrations:
```bash
php artisan migrate
```

8. Build frontend assets:
```bash
npm run build
```

9. Start development server:
```bash
php artisan serve
```

Visit `http://localhost:8000` in your browser.

## Configuration

See `.env.example` for all required environment variables:

- Stripe keys (for payments)
- OAuth credentials (Google, GitHub)
- Database configuration

## Deployment

See [DEPLOY.md](DEPLOY.md) for detailed deployment instructions to Railway.app.

## License

MIT
