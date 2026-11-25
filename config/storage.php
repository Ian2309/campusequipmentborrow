<?php
// Supabase Storage helpers, this file is server-side only.
function storage_enabled(): bool {
    return sb_cfg('SB_URL') !== '' && sb_cfg('SB_SERVICE_KEY') !== '';
}
function storage_auth(string $key): array {
    return str_starts_with($key, 'sb_secret_') ? ['apikey: ' . $key] : ['Authorization: Bearer ' . $key, 'apikey: ' . $key];
}
function storage_bucket(): string { return sb_cfg('SB_BUCKET', 'equipment'); }
function storage_public_prefix(): string {
    return rtrim(sb_cfg('SB_URL'), '/') . '/storage/v1/object/public/' . storage_bucket() . '/';
}

function storage_upload(string $path, string $bytes, string $mime): ?string {
    $key = sb_cfg('SB_SERVICE_KEY');
    $ch = curl_init(rtrim(sb_cfg('SB_URL'), '/') . '/storage/v1/object/' . storage_bucket() . '/' . $path);
    curl_setopt_array($ch, [
        CURLOPT_POST => true, CURLOPT_POSTFIELDS => $bytes, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20,
        CURLOPT_HTTPHEADER => array_merge(storage_auth($key), ['Content-Type: ' . $mime, 'x-upsert: false']),
    ]);
    $res  = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    if ($res === false || $code < 200 || $code >= 300) {
        error_log("storage_upload failed (HTTP $code): " . ($err ?: (string)$res));
        return null;
    }
    return storage_public_prefix() . $path;
}

function storage_delete(string $url): void {
    $prefix = storage_public_prefix();
    if (!str_starts_with($url, $prefix)) return;
    $path = substr($url, strlen($prefix));
    if (!preg_match('#^[A-Za-z0-9_./-]+$#', $path) || str_contains($path, '..')) return;
    $key = sb_cfg('SB_SERVICE_KEY');
    $ch = curl_init(rtrim(sb_cfg('SB_URL'), '/') . '/storage/v1/object/' . storage_bucket() . '/' . $path);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => 'DELETE', CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15,
        CURLOPT_HTTPHEADER => storage_auth($key),
    ]);
    curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    if ($code < 200 || $code >= 300) error_log("storage_delete failed (HTTP $code) for $path");
}
