# Tenant database baseline

This directory is the complete baseline for a new tenant database.

- Each PHP migration creates exactly one table containing its final columns,
  indexes, checks, and foreign keys.
- Numeric prefixes define the dependency-safe creation order.
- The historical incremental migrations were intentionally removed. Existing
  tenant databases must be dropped and recreated before using this baseline.
- `migrations` is Laravel's internal table and is therefore not represented by
  a migration file here.

After this baseline has been deployed to persistent environments, future schema
changes must use new incremental migrations. Do not edit an already-deployed
baseline migration.
