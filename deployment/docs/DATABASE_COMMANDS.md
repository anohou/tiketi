# Database Management Commands

Quick reference for checking and managing the Tiketi database.

---

## 🚀 Quick Commands (Most Used)

```bash
# Database overview (connection, tables, size)
docker exec dc_tiketi_local php artisan db:show

# Check migration status
docker exec dc_tiketi_local php artisan migrate:status

# List all tenants
docker exec dc_tiketi_local php artisan tenants:list

# Show all tables
docker exec tiketi_mysql_local mysql -u tiketi_user -ptiketi_pass tiketi_dev -e "SHOW TABLES;"

# Show all databases (including tenant DBs)
docker exec tiketi_mysql_local mysql -u tiketi_user -ptiketi_pass -e "SHOW DATABASES;"
```

---

## 📊 Database Status & Information

### General Database Overview
```bash
# Complete database information
docker exec dc_tiketi_local php artisan db:show

# Output includes:
# - MySQL version
# - Connection name
# - Database name
# - Host and port
# - Username
# - Number of tables
# - Total database size
# - Size of each table
```

### Connection Test
```bash
# Quick connection test
docker exec dc_tiketi_local php artisan tinker --execute="DB::connection()->getPdo(); echo 'Connected!'"

# More detailed connection info
docker exec dc_tiketi_local php artisan db:show --database=mysql
```

---

## 🔄 Migration Management

### Check Migration Status
```bash
# List all migrations and their status
docker exec dc_tiketi_local php artisan migrate:status

# Shows:
# - Migration name
# - Batch number (when it was run)
# - Status (Ran/Pending)
```

### Run Migrations
```bash
# Run pending migrations
docker exec dc_tiketi_local php artisan migrate

# Run with force flag (no confirmation)
docker exec dc_tiketi_local php artisan migrate --force

# Rollback last batch
docker exec dc_tiketi_local php artisan migrate:rollback

# Fresh migration (drops all tables and re-runs)
docker exec dc_tiketi_local php artisan migrate:fresh --seed
```

---

## 🏢 Multi-Tenant Database Commands

### Tenant Management
```bash
# List all tenants
docker exec dc_tiketi_local php artisan tenants:list

# Create a new tenant
docker exec dc_tiketi_local php artisan tenants:create <tenant-id>

# Delete a tenant
docker exec dc_tiketi_local php artisan tenants:delete <tenant-id>

# Run migrations for a specific tenant
docker exec dc_tiketi_local php artisan tenants:migrate --tenants=<tenant-id>

# Run migrations for all tenants
docker exec dc_tiketi_local php artisan tenants:migrate
```

### Tenant Database Inspection
```bash
# Show all databases (including tenant databases)
docker exec tiketi_mysql_local mysql -u tiketi_user -ptiketi_pass -e "SHOW DATABASES;"

# Check tenant database structure
docker exec tiketi_mysql_local mysql -u tiketi_user -ptiketi_pass tenant<id> -e "SHOW TABLES;"

# Example: Check tenant with id 'alpha'
docker exec tiketi_mysql_local mysql -u tiketi_user -ptiketi_pass tenantalpha -e "SHOW TABLES;"
```

---

## 📋 Table Inspection

### List Tables
```bash
# Using Laravel Artisan
docker exec dc_tiketi_local php artisan db:show

# Using MySQL directly
docker exec tiketi_mysql_local mysql -u tiketi_user -ptiketi_pass tiketi_dev -e "SHOW TABLES;"

# Show table with sizes
docker exec tiketi_mysql_local mysql -u tiketi_user -ptiketi_pass tiketi_dev -e "SHOW TABLE STATUS;"
```

### View Table Structure
```bash
# Describe table structure (Artisan)
docker exec dc_tiketi_local php artisan db:table users

# Describe table structure (MySQL)
docker exec tiketi_mysql_local mysql -u tiketi_user -ptiketi_pass tiketi_dev -e "DESCRIBE users;"

# Show create statement
docker exec tiketi_mysql_local mysql -u tiketi_user -ptiketi_pass tiketi_dev -e "SHOW CREATE TABLE users\G"
```

### Query Table Data
```bash
# Count records
docker exec tiketi_mysql_local mysql -u tiketi_user -ptiketi_pass tiketi_dev -e "SELECT COUNT(*) FROM users;"

# View recent records
docker exec tiketi_mysql_local mysql -u tiketi_user -ptiketi_pass tiketi_dev -e "SELECT * FROM users LIMIT 10;"

# Check specific tenant
docker exec tiketi_mysql_local mysql -u tiketi_user -ptiketi_pass tiketi_dev -e "SELECT * FROM tenants;"
```

---

## 🔧 Interactive MySQL Shell

### Connect to MySQL
```bash
# Connect to central database
docker exec -it tiketi_mysql_local mysql -u tiketi_user -ptiketi_pass tiketi_dev

# Connect without database (for database-level commands)
docker exec -it tiketi_mysql_local mysql -u tiketi_user -ptiketi_pass
```

### Common MySQL Commands (once connected)
```sql
-- Show all databases
SHOW DATABASES;

-- Switch database
USE tiketi_dev;

-- Show tables
SHOW TABLES;

-- Describe table
DESCRIBE users;

-- Count records
SELECT COUNT(*) FROM users;

-- Show table creation
SHOW CREATE TABLE tenants;

-- Exit
EXIT;
```

---

## 🧹 Database Maintenance

### Clear Caches
```bash
# Clear application cache
docker exec dc_tiketi_local php artisan cache:clear

# Clear config cache
docker exec dc_tiketi_local php artisan config:clear

# Clear route cache
docker exec dc_tiketi_local php artisan route:clear

# Clear view cache
docker exec dc_tiketi_local php artisan view:clear

# Clear all caches
docker exec dc_tiketi_local php artisan optimize:clear
```

### Seed Database
```bash
# Run all seeders
docker exec dc_tiketi_local php artisan db:seed

# Run specific seeder
docker exec dc_tiketi_local php artisan db:seed --class=UserSeeder

# Fresh migration with seeding
docker exec dc_tiketi_local php artisan migrate:fresh --seed
```

---

## 🔍 Debugging & Troubleshooting

### Connection Issues
```bash
# Test database connection
docker exec dc_tiketi_local php -r "new PDO('mysql:host=db;port=3306;dbname=tiketi_dev', 'tiketi_user', 'tiketi_pass');"

# Check .env database configuration
docker exec dc_tiketi_local cat .env | grep DB_

# Verify MySQL is running
docker ps | grep mysql

# Check MySQL logs
docker logs tiketi_mysql_local --tail 50
```

### Performance Monitoring
```bash
# Show running queries
docker exec tiketi_mysql_local mysql -u tiketi_user -ptiketi_pass -e "SHOW PROCESSLIST;"

# Show database size
docker exec tiketi_mysql_local mysql -u tiketi_user -ptiketi_pass -e "SELECT table_schema AS 'Database', ROUND(SUM(data_length + index_length) / 1024 / 1024, 2) AS 'Size (MB)' FROM information_schema.TABLES GROUP BY table_schema;"
```

---

## 📦 Backup & Restore

### Backup Database
```bash
# Backup central database
docker exec tiketi_mysql_local mysqldump -u tiketi_user -ptiketi_pass tiketi_dev > backup_central.sql

# Backup specific tenant database
docker exec tiketi_mysql_local mysqldump -u tiketi_user -ptiketi_pass tenantalpha > backup_tenant_alpha.sql

# Backup all databases
docker exec tiketi_mysql_local mysqldump -u tiketi_user -ptiketi_pass --all-databases > backup_all.sql
```

### Restore Database
```bash
# Restore central database
docker exec -i tiketi_mysql_local mysql -u tiketi_user -ptiketi_pass tiketi_dev < backup_central.sql

# Restore tenant database
docker exec -i tiketi_mysql_local mysql -u tiketi_user -ptiketi_pass tenantalpha < backup_tenant_alpha.sql
```

---

## 🎯 Common Scenarios

### Scenario: Check if database is ready
```bash
docker exec dc_tiketi_local php artisan db:show && echo "✅ Database is ready"
```

### Scenario: Reset local database completely
```bash
# WARNING: This deletes all data!
docker exec dc_tiketi_local php artisan migrate:fresh --seed
```

### Scenario: Count all tenants
```bash
docker exec tiketi_mysql_local mysql -u tiketi_user -ptiketi_pass tiketi_dev -e "SELECT COUNT(*) as tenant_count FROM tenants;"
```

### Scenario: Check tenant domains
```bash
docker exec tiketi_mysql_local mysql -u tiketi_user -ptiketi_pass tiketi_dev -e "SELECT d.domain, t.id as tenant_id FROM domains d JOIN tenants t ON d.tenant_id = t.id;"
```

---

## 🔐 Credentials Reference

### Local Environment
- **Host**: `db` (from container) / `localhost` (from host)
- **Port**: `3307` (from host) / `3306` (from container)
- **Database**: `tiketi_dev`
- **Username**: `tiketi_user`
- **Password**: `tiketi_pass`

### Connection Strings
```bash
# From host machine
mysql -h 127.0.0.1 -P 3307 -u tiketi_user -ptiketi_pass tiketi_dev

# From container
mysql -h db -P 3306 -u tiketi_user -ptiketi_pass tiketi_dev
```

---

## 📚 Additional Resources

- [Laravel Database Documentation](https://laravel.com/docs/database)
- [Stancl Tenancy Documentation](https://tenancyforlaravel.com/docs)
- [MySQL Command Reference](https://dev.mysql.com/doc/refman/8.0/en/sql-statements.html)

---

## 💡 Pro Tips

1. **Always use `php artisan db:show` first** - It gives you a complete overview
2. **Check migration status regularly** - Ensures database schema is up to date
3. **Use `tenants:list` before operations** - Know what tenants exist
4. **Backup before destructive operations** - Always create backups before `migrate:fresh`
5. **Monitor database size** - Keep track of growth, especially with multi-tenancy
