# Local PostgreSQL setup (Valet / Homebrew)

## Extension "vector" is not available

IdeaTub needs the [pgvector](https://github.com/pgvector/pgvector) extension. If migrations fail with:

```text
extension "vector" is not available ... Could not open extension control file ".../extension/vector.control": No such file or directory
```

install pgvector for your PostgreSQL version.

**Option 1 – Homebrew (if your Postgres is from Homebrew):**

```bash
brew install pgvector
```

If the formula installs into a different Postgres than the one you use (e.g. you use `postgresql@16`), use Option 2.

**Option 2 – Build from source (recommended for PostgreSQL 16):**

```bash
git clone --branch v0.8.1 https://github.com/pgvector/pgvector.git
cd pgvector
export PG_CONFIG=/opt/homebrew/opt/postgresql@16/bin/pg_config   # or your postgres version
make
make install
```

Restart PostgreSQL, then run migrations again:

```bash
brew services restart postgresql@16
php artisan migrate
```

## Permission denied for schema public

On PostgreSQL 15+, the `public` schema is not granted to the database owner by default, so Laravel migrations can fail with:

```text
SQLSTATE[42501]: Insufficient privilege: 7 ERROR: permission denied for schema public
```

**Fix:** Grant your app database user full access to the `public` schema. Connect as a superuser (e.g. `postgres`) and run:

```bash
psql -U postgres -d ideatub_dev
```

Then in `psql`:

```sql
-- Replace your_db_user with the value of DB_USERNAME in .env (or your macOS username if DB_USERNAME is blank)
GRANT ALL ON SCHEMA public TO your_db_user;
```

One-liner (replace `your_db_user`):

```bash
psql -U postgres -d ideatub_dev -c "GRANT ALL ON SCHEMA public TO your_db_user;"
```

Then run migrations again:

```bash
php artisan migrate
```

## Creating the database and user (Homebrew)

If you prefer a dedicated app user and database:

```bash
# Connect as superuser
psql -U postgres -d postgres

-- In psql:
CREATE USER ideatub WITH PASSWORD 'your_password';
CREATE DATABASE ideatub_dev OWNER ideatub;
\c ideatub_dev
GRANT ALL ON SCHEMA public TO ideatub;
-- If using pgvector:
CREATE EXTENSION IF NOT EXISTS vector;
```

Set in `.env`:

```env
DB_DATABASE=ideatub_dev
DB_USERNAME=ideatub
DB_PASSWORD=your_password
```
