# Tenant database baseline

This directory is the complete baseline for a new tenant database.

- Each PHP migration creates exactly one table containing its final columns,
  indexes, checks, and foreign keys.
- Numeric prefixes define the dependency-safe creation order.
- The historical incremental migrations were intentionally removed. Existing
  tenant databases must pass `tenants:baseline:check` and then adopt this
  baseline with `tenants:baseline:adopt --force`. Adoption writes only the
  consolidated migration names to the migration repository after strict schema
  verification; it never recreates tenant tables or deletes tenant data.
- `migrations` is Laravel's internal table and is therefore not represented by
  a migration file here.

After this baseline has been deployed to persistent environments, future schema
changes must use new incremental migrations. Do not edit an already-deployed
baseline migration.
