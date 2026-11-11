<?php
// Copy this file to config/supabase.php and fill it in. Never commit supabase.php.

// Supabase Dashboard > Connect > "Session pooler" (port 5432). Use the pooler, not the direct db.<ref>.supabase.co host
// (the direct host is IPv6-only unless you buy the IPv4 add-on).
const SB_DB_HOST = 'aws-0-REGION.pooler.supabase.com';
const SB_DB_PORT = '5432';
const SB_DB_NAME = 'postgres';
const SB_DB_USER = 'postgres.YOUR_PROJECT_REF';
const SB_DB_PASS = 'YOUR_DATABASE_PASSWORD';

// Optional: equipment photos in Supabase Storage. Leave SB_URL empty to keep saving photos in uploads/equipment/.
// Dashboard > Settings > API. The service_role key is a SECRET: server-side only, never in JS or HTML.
const SB_URL         = 'https://YOUR_PROJECT_REF.supabase.co';
const SB_SERVICE_KEY = 'YOUR_SERVICE_ROLE_KEY';
const SB_BUCKET      = 'equipment';
