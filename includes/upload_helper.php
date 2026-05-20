<?php
// =============================================
// METROMART — Upload helper
// File: includes/upload_helper.php
// =============================================

function delete_upload(string $relativePath): bool {
    if (!$relativePath) {
        return false;
    }

    $relativePath = ltrim($relativePath, '/\\');
    $targetPath = realpath(__DIR__ . '/../' . $relativePath);
    if (!$targetPath) {
        return false;
    }

    $uploadsRoot = realpath(__DIR__ . '/../uploads');
    if (!$uploadsRoot || strpos($targetPath, $uploadsRoot) !== 0) {
        return false;
    }

    if (!is_file($targetPath)) {
        return false;
    }

    return unlink($targetPath);
}

function replace_upload(string $field, string $subdir, ?string $existingPath = null): ?string {
    $imgPath = save_upload($field, $subdir);
    if (!$imgPath) {
        return null;
    }

    if ($existingPath && $existingPath !== $imgPath) {
        delete_upload($existingPath);
    }

    return $imgPath;
}
