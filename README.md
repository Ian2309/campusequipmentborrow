# CampusMate / RTU Equipment Hub (Render + Supabase)

PHP app in a Docker container on Render, with Supabase for the database (Postgres) and equipment photos (Storage).

## Environment variables (Render > your web service > Environment)
SB_DB_HOST, SB_DB_PORT (5432), SB_DB_NAME (postgres), SB_DB_USER (postgres.<project-ref>), SB_DB_PASS,
SB_URL (https://<project-ref>.supabase.co), SB_SERVICE_KEY (Supabase secret key, sb_secret_...), SB_BUCKET (equipment)

## Setup
1. Supabase: create a project, run database/schema.sql in the SQL Editor.
2. Create the first admin with a SQL INSERT (see the chat instructions).
3. GitHub: push this folder (config/supabase.php is git-ignored; never commit keys).
4. Render: New > Web Service > connect the repo > Language: Docker > add the environment variables > Deploy.

Free Render services sleep when idle and lose files on restart, which is why photos go to Supabase Storage.
Local development still works: copy config/supabase.example.php to config/supabase.php.
