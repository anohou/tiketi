Migration and Seeding Fix Plan
Problem Description
php artisan migrate:fresh --seed fails with Table 'transport.users' doesn't exist in 
InitialSetupSeeder
. The user wants to properly set up the Landlord dashboard and a first test tenant, while keeping other tenants minimal (only admin user).

User Review Required
Confirmation on which database (landlord or tenant) the users table should belong to.
Specifics on "Landlord dashboard" creation (is it just a super admin user in landlord DB?).
Proposed Changes
System Configuration
[MODIFY] 
AppServiceProvider.php
In 
boot()
 method, add $this->loadMigrationsFrom(__DIR__ . '/../../database/landlord_migrations'); to ensure migrate:fresh picks up landlord tables.
Database Seeders
[NEW] 
TenantSeeder.php
Create this file (copy content from 
InitialSetupSeeder.php
).
This will contain the full seeding logic for the test tenant (Stations, Vehicles, etc.).
[MODIFY] 
DatabaseSeeder.php
Landlord Setup: Create the Landlord Admin user (User::create in central context).
Test Tenant:
Create Tenant id='test'.
Create Domain test.localhost.
Run TenantSeeder for this tenant.
Other Tenant:
Create Tenant id='demo'.
Create Domain demo.localhost.
Create only Admin user for this tenant.
[DELETE/REFACTOR] 
InitialSetupSeeder.php
Content moved to TenantSeeder.php. We can keep it if we want, but 
DatabaseSeeder
 will control the flow. I will likely rename or copy it.
Migrations
Verify database/landlord_migrations are correct.
Verify database/migrations/tenant are correct.
Verification Plan
Automated Tests
Run php artisan migrate:fresh --seed and ensure it completes without error.
Check database content (Landlord and Tenant DBs) to verify tables and users are created as expected.