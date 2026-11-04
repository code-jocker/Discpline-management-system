<?php
declare(strict_types=1);

namespace App\Services;

use Database;
use PDO;
use RuntimeException;

/**
 * FileStorageService — secure tenant-isolated file uploads.
 *
 * Validates MIME type, extension, and file size before storing.
 * Records metadata in stored_files table.
 * Directory layout: storage/uploads/{tenantId}/{randomHex}.{ext}
 *
 * S3-ready: swap the storeLocally() method for an S3 SDK call and
 * set storage_disk = 's3'.
 */
final class FileStorageService
{
    private const MAX_BYTES = 10_485_760; // 10 MiB
    private const MAX_IMAGE_WIDTH = 1600;
    private const MAX_IMAGE_HEIGHT = 1600;
    private const IMAGE_QUALITY = 82;

    /** MIME type → allowed extensions */
    private const ALLOWED = [
        'image/jpeg'     => ['jpg', 'jpeg'],
        'image/png'      => ['png'],
        'image/gif'      => ['gif'],
        'image/webp'     => ['webp'],
        'application/pdf'=> ['pdf'],
        'text/csv'       => ['csv'],
        'text/plain'     => ['txt'],
        'application/msword' => ['doc'],
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => ['docx'],
        'application/vnd.ms-excel' => ['xls'],
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => ['xlsx'],
    ];

    private string $storagePath;

    public function __construct(?string $storagePath = null)
    {
        $this->storagePath = $storagePath
            ?? dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'uploads';
    }

    /**
     * Validate, move, and record an uploaded file.
     *
     * @param  array       $file      Single entry from $_FILES
     * @param  int         $tenantId
     * @param  int|null    $userId
     * @return array{id: int, original_name: string, storage_path: string, mime_type: string, size_bytes: int}
     * @throws RuntimeException on any validation failure
     */
    public function store(array $file, int $tenantId, ?int $userId = null): array
    {
        $this->assertUploadIntegrity($file);

        $mimeType  = (string) (mime_content_type($file['tmp_name']) ?: 'application/octet-stream');
        $extension = strtolower(pathinfo((string) $file['name'], PATHINFO_EXTENSION));

        $this->assertAllowedMime($mimeType, $extension);

        $tenantDir = $this->storagePath . DIRECTORY_SEPARATOR . $tenantId;
        if (!is_dir($tenantDir) && !mkdir($tenantDir, 0755, true) && !is_dir($tenantDir)) {
            throw new RuntimeException('Upload directory could not be created.');
        }

        $sourceFile   = $file['tmp_name'];
        $sourceMime   = $mimeType;
        $sourceSize   = (int) $file['size'];
        $optimized = $this->maybeOptimizeImage($sourceFile, $sourceMime, $extension);
        if ($optimized !== null) {
            $sourceFile = $optimized['path'];
            $sourceMime = $optimized['mime_type'];
            $sourceSize = $optimized['size_bytes'];
        }

        $safeName   = bin2hex(random_bytes(16)) . '.' . $extension;
        $destination = $tenantDir . DIRECTORY_SEPARATOR . $safeName;

        if (!copy($sourceFile, $destination)) {
            throw new RuntimeException('File could not be saved to storage.');
        }

        if ($optimized !== null && is_file($sourceFile) && $sourceFile !== $file['tmp_name']) {
            @unlink($sourceFile);
        }

        $storagePath = $tenantId . '/' . $safeName;
        $fileId      = $this->recordToDatabase($tenantId, $userId, $file, $storagePath, $sourceMime, $sourceSize);

        return [
            'id'            => $fileId,
            'original_name' => basename((string) $file['name']),
            'storage_path'  => $storagePath,
            'mime_type'     => $sourceMime,
            'size_bytes'    => $sourceSize,
        ];
    }

    /**
     * Resolve a storage path to its absolute filesystem path.
     */
    public function getAbsolutePath(string $storagePath): string
    {
        return $this->storagePath . DIRECTORY_SEPARATOR . ltrim($storagePath, '/\\');
    }

    // -------------------------------------------------------------------------
    // Private
    // -------------------------------------------------------------------------

    private function maybeOptimizeImage(string $path, string $mimeType, string $extension): ?array
    {
        if (!function_exists('imagecreatefromstring') || !function_exists('imageinterlace')) {
            return null;
        }

        if (!str_starts_with($mimeType, 'image/')) {
            return null;
        }

        $raw = @file_get_contents($path);
        if ($raw === false || $raw === '') {
            return null;
        }

        $source = @imagecreatefromstring($raw);
        if (!$source) {
            return null;
        }

        $width = imagesx($source);
        $height = imagesy($source);
        if ($width <= 0 || $height <= 0) {
            imagedestroy($source);
            return null;
        }

        $ratio = min(1, self::MAX_IMAGE_WIDTH / $width, self::MAX_IMAGE_HEIGHT / $height);
        $newWidth = max(1, (int) round($width * $ratio));
        $newHeight = max(1, (int) round($height * $ratio));

        $resized = imagecreatetruecolor($newWidth, $newHeight);
        if (!$resized) {
            imagedestroy($source);
            return null;
        }

        if (in_array($mimeType, ['image/png', 'image/webp', 'image/gif'], true)) {
            imagealphablending($resized, false);
            imagesavealpha($resized, true);
        }

        imagecopyresampled($resized, $source, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);
        if (in_array($mimeType, ['image/jpeg', 'image/jpg'], true)) {
            imageinterlace($resized, true);
        }

        $tmpPath = tempnam(sys_get_temp_dir(), 'sbms_img_');
        if ($tmpPath === false) {
            imagedestroy($source);
            imagedestroy($resized);
            return null;
        }

        if ($mimeType === 'image/jpeg' || $mimeType === 'image/jpg') {
            $outputMime = 'image/jpeg';
            imagejpeg($resized, $tmpPath, self::IMAGE_QUALITY);
        } elseif ($mimeType === 'image/png') {
            $outputMime = 'image/png';
            imagepng($resized, $tmpPath, 8);
        } elseif ($mimeType === 'image/webp') {
            $outputMime = 'image/webp';
            imagewebp($resized, $tmpPath, self::IMAGE_QUALITY);
        } else {
            $outputMime = $mimeType;
            imagegif($resized, $tmpPath);
        }

        imagedestroy($source);
        imagedestroy($resized);

        if (!is_file($tmpPath)) {
            @unlink($tmpPath);
            return null;
        }

        $sizeBytes = (int) filesize($tmpPath);
        if ($sizeBytes <= 0) {
            @unlink($tmpPath);
            return null;
        }

        return [
            'path' => $tmpPath,
            'mime_type' => $outputMime,
            'size_bytes' => $sizeBytes,
        ];
    }

    private function assertUploadIntegrity(array $file): void
    {
        if (!isset($file['tmp_name']) || !is_uploaded_file((string) $file['tmp_name'])) {
            throw new RuntimeException('No valid upload detected.');
        }

        if ((int) $file['error'] !== UPLOAD_ERR_OK) {
            throw new RuntimeException('Upload error code: ' . $file['error']);
        }

        if ((int) $file['size'] > self::MAX_BYTES) {
            throw new RuntimeException('File exceeds maximum allowed size of 10 MiB.');
        }
    }

    private function assertAllowedMime(string $mimeType, string $extension): void
    {
        if (!isset(self::ALLOWED[$mimeType])) {
            throw new RuntimeException("File type \"{$mimeType}\" is not permitted.");
        }

        if (!in_array($extension, self::ALLOWED[$mimeType], true)) {
            throw new RuntimeException("Extension \".{$extension}\" does not match MIME type.");
        }
    }

    private function recordToDatabase(
        int    $tenantId,
        ?int   $userId,
        array  $file,
        string $storagePath,
        string $mimeType,
        int    $sizeBytes
    ): int {
        $db   = Database::connection();
        $stmt = $db->prepare(
            'INSERT INTO stored_files
                 (tenant_id, user_id, storage_disk, original_name, storage_path, mime_type, size_bytes, created_at)
             VALUES
                 (:tenant_id, :user_id, "local", :original_name, :storage_path, :mime_type, :size_bytes, NOW())'
        );
        $stmt->bindValue(':tenant_id',     $tenantId,                         PDO::PARAM_INT);
        $stmt->bindValue(':user_id',       $userId,  $userId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $stmt->bindValue(':original_name', basename((string) $file['name']),  PDO::PARAM_STR);
        $stmt->bindValue(':storage_path',  $storagePath,                      PDO::PARAM_STR);
        $stmt->bindValue(':mime_type',     $mimeType,                         PDO::PARAM_STR);
        $stmt->bindValue(':size_bytes',    $sizeBytes,                        PDO::PARAM_INT);
        $stmt->execute();

        return (int) $db->lastInsertId();
    }
}
