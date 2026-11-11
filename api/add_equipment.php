<?php
// Admin: add equipment. Every field is required, including a photo (sent as a base64 data URL, already resized by the browser).
require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../config/storage.php';
require_admin();
$in = post();

$name  = trim((string)($in['name'] ?? ''));
$cat   = trim((string)($in['category'] ?? ''));
$total = (int)($in['total'] ?? 0);
$desc  = trim((string)($in['desc'] ?? ''));
$photo = (string)($in['photo'] ?? '');

if ($name === '' || mb_strlen($name) > 100) fail('Please enter an equipment name (up to 100 characters).');
if (!in_array($cat, ['AV', 'Computing', 'Laboratory', 'Sports', 'Tools', 'Other'], true)) fail('Please choose a category.');
if ($total < 1 || $total > 1000) fail('Total units must be between 1 and 1000.');
if ($desc === '') fail('Please enter a description.');
if (mb_strlen($desc) > 500) fail('Description is too long (500 characters max).');
if ($photo === '') fail('Please upload a photo of the equipment.');

// Decode and verify the photo
if (!preg_match('#^data:image/(?:jpeg|png|webp);base64,#', substr($photo, 0, 40), $m)) fail('The photo must be a JPG, PNG or WebP image.');
$bytes = base64_decode(substr($photo, strlen($m[0])), true);
if ($bytes === false || $bytes === '' || strlen($bytes) > 3 * 1024 * 1024) fail('The photo is invalid or larger than 3 MB.');
$info = @getimagesizefromstring($bytes);
$types = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp'];
if (!$info || !isset($types[$info[2]])) fail('That file is not a valid image.');
if ($info[0] > 4000 || $info[1] > 4000) fail('The photo dimensions are too large.');

// Re-encode through GD when available so only pixels are stored; otherwise keep the verified bytes.
$file = 'eq_' . bin2hex(random_bytes(8)) . '.';
if (function_exists('imagecreatefromstring') && ($img = @imagecreatefromstring($bytes))) {
    ob_start();
    imagejpeg($img, null, 85);
    $data = (string)ob_get_clean();
    imagedestroy($img);
    $file .= 'jpg';
    $mime = 'image/jpeg';
} else {
    $data = $bytes;
    $file .= $types[$info[2]];
    $mime = image_type_to_mime_type($info[2]);
}
if ($data === '') fail('Could not process the photo.', 500);

// Supabase Storage when configured; otherwise a local uploads/equipment folder (handy for offline XAMPP development).
$local = null;
if (storage_enabled()) {
    $imageUrl = storage_upload('equipment/' . $file, $data, $mime);
    if ($imageUrl === null) fail('Could not upload the photo. Please try again.', 500);
} else {
    $dir = __DIR__ . '/../uploads/equipment';
    if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
        error_log("add_equipment: cannot create $dir");
        fail('The photo folder is missing and could not be created. Create an "uploads/equipment" folder next to "api".', 500);
    }
    if (!is_writable($dir) || file_put_contents("$dir/$file", $data, LOCK_EX) === false) {
        error_log("add_equipment: cannot write to $dir");
        fail('Could not save the photo on the server.', 500);
    }
    $local = "$dir/$file";
    $imageUrl = 'uploads/equipment/' . $file;
}

try {
    $pdo->prepare('INSERT INTO equipment (name, category, total_units, available_units, description, image_url) VALUES (?, ?, ?, ?, ?, ?)')
        ->execute([$name, $cat, $total, $total, $desc, $imageUrl]);
    out(['success' => true, 'message' => "$name added to inventory."]);
} catch (PDOException $e) {
    if ($local) @unlink($local); else storage_delete($imageUrl);
    error_log($e->getMessage());
    fail('Could not add the equipment.', 500);
}
