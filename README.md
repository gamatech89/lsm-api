# LSM Platform Backend API

Laravel backend API for the Landeseiten Maintenance Platform.

## Requirements

- PHP 8.2+
- MySQL 8.0+
- Composer

## Installation

```bash
# Install dependencies
composer install

# Configure environment
cp .env.example .env
# Edit .env with your database credentials

# Generate application key
php artisan key:generate

# Run migrations
php artisan migrate

# Create admin user
php artisan db:seed --class=AdminUserSeeder

# Cache configuration
php artisan config:cache
php artisan route:cache
```

## API Endpoints

- `GET /health` - Health check
- `POST /api/v1/login` - Authentication
- `GET /api/v1/dashboard` - Dashboard stats
- `GET /api/v1/projects` - Projects list

Full API documentation available in `/routes/api.php`.

## Deployment

See deployment documentation for Hostinger setup instructions.
