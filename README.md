# CampusMate / RTU Equipment Hub

## Setup (XAMPP)
1. Copy this folder to `C:\xampp\htdocs\campusequipmentborrow`. Start Apache + MySQL.
2. In phpMyAdmin: drop the old `rtu_equipment_hub` database (export it first if needed), then Import `database/schema.sql`.
3. Create the first PEMU admin (run once):
   `C:\xampp\php\php.exe database\create_admin.php pemu "YourLongPassphrase" "PEMU Office"`
4. Open http://localhost/campusequipmentborrow/
5. Before real use: set a MySQL password / dedicated user in `config/database.php`, and serve over HTTPS.

## Restore a backup
phpMyAdmin > Import the `.sql` file (it drops and recreates the tables), or
`C:\xampp\mysql\bin\mysql.exe -u root rtu_equipment_hub < backups\campusmate_YYYYMMDD_HHMMSS.sql`

## Notes
- `update_requests.php` was removed (it read a `borrowings` table that doesn't exist); students use `my_requests.php`.
- Pages poll every 5 seconds, so new equipment, requests and decisions appear without refreshing.
