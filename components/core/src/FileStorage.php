<?php

namespace Canyon\Core;

/**
 * Canyon Framework - File Storage
 *
 * Flexible file storage API for user-generated files.
 * Elgg's file storage system.
 */
class FileStorage
{
  /** @var string Base storage directory */
  protected static string $baseDir;

  /** @var array Allowed MIME types */
  protected static array $allowedTypes = [];

  /** @var int Maximum file size in bytes */
  protected static int $maxSize = 10485760; // 10MB

  /** @var array Upload errors */
  protected static array $errors = [];

  /**
   * Initialize file storage.
   */
  public static function init(): void
  {
    self::$baseDir = CANYON_STORAGE_PATH . '/files';
    if (!is_dir(self::$baseDir)) {
      mkdir(self::$baseDir, 0750, true);
    }
  }

  /**
   * Store an uploaded file.
   *
   * @param array $file $_FILES array element
   * @param string $ownerId Owner user ID
   * @param string $directory Subdirectory (e.g., 'images', 'documents')
   * @return array|null File metadata or null on failure
   */
  public static function store(array $file, string $ownerId, string $directory = ''): ?array
  {
    self::init();

    // Validate upload
    if (!isset($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
      self::$errors[] = 'Invalid file upload';
      return null;
    }

    // Check for upload errors
    if ($file['error'] !== UPLOAD_ERR_OK) {
      self::$errors[] = self::getUploadError($file['error']);
      return null;
    }

    // Validate file size
    if ($file['size'] > self::$maxSize) {
      self::$errors[] = 'File size exceeds maximum allowed (' . self::$maxSize . ' bytes)';
      return null;
    }

    // Validate MIME type
    $mimeType = mime_content_type($file['tmp_name']);
    if (!empty(self::$allowedTypes) && !in_array($mimeType, self::$allowedTypes)) {
      self::$errors[] = 'File type not allowed: ' . $mimeType;
      return null;
    }

    // Generate safe filename with strict extension whitelist
    $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $allowedExtensions = ['jpg', 'jpeg', 'png', 'gif', 'pdf', 'doc', 'docx', 'zip'];
    if (!in_array($extension, $allowedExtensions, true)) {
      self::$errors[] = 'File type not allowed';
      return null;
    }
    $filename = uniqid() . '_' . md5($file['name']) . '.' . $extension;

    // Create owner directory
    $ownerDir = self::$baseDir . '/' . $ownerId;
    if (!is_dir($ownerDir)) {
      mkdir($ownerDir, 0750, true);
    }

    // Create subdirectory if specified
    if ($directory) {
      $ownerDir .= '/' . $directory;
      if (!is_dir($ownerDir)) {
        mkdir($ownerDir, 0750, true);
      }
    }

    // Move file
    $destination = $ownerDir . '/' . $filename;
    if (!move_uploaded_file($file['tmp_name'], $destination)) {
      self::$errors[] = 'Failed to move uploaded file';
      return null;
    }

    // Set permissions
    chmod($destination, 0640);

    return [
      'id' => md5($destination),
      'owner' => $ownerId,
      'directory' => $directory,
      'filename' => $filename,
      'original_name' => $file['name'],
      'mime_type' => $mimeType,
      'size' => $file['size'],
      'path' => $destination,
      'url' => self::getUrl($ownerId, $directory, $filename),
      'created_at' => time(),
    ];
  }

  /**
   * Get a file by ID.
   */
  public static function get(string $fileId): ?array
  {
    self::init();
    $iterator = new \RecursiveIteratorIterator(
      new \RecursiveDirectoryIterator(self::$baseDir, \RecursiveDirectoryIterator::SKIP_DOTS)
    );
    foreach ($iterator as $file) {
      if ($file->isFile() && md5($file->getPathname()) === $fileId) {
        return [
          'id' => $fileId,
          'path' => $file->getPathname(),
          'filename' => $file->getFilename(),
          'size' => $file->getSize(),
          'mime_type' => mime_content_type($file->getPathname()),
        ];
      }
    }
    return null;
  }

  /**
   * Delete a file.
   */
  public static function delete(string $fileId): bool
  {
    self::init();
    $file = self::get($fileId);
    if (!$file) {
      return false;
    }
    return unlink($file['path']);
  }

  /**
   * Get file URL.
   */
  public static function getUrl(string $ownerId, string $directory, string $filename): string
  {
    $path = '/files/' . $ownerId;
    if ($directory) {
      $path .= '/' . $directory;
    }
    $path .= '/' . $filename;
    return base_url($path);
  }

  /**
   * Get files by owner.
   */
  public static function getByOwner(string $ownerId, string $directory = ''): array
  {
    self::init();
    $ownerDir = self::$baseDir . '/' . $ownerId;
    if ($directory) {
      $ownerDir .= '/' . $directory;
    }
    if (!is_dir($ownerDir)) {
      return [];
    }

    $files = [];
    $iterator = new \RecursiveIteratorIterator(
      new \RecursiveDirectoryIterator($ownerDir, \RecursiveDirectoryIterator::SKIP_DOTS)
    );
    foreach ($iterator as $file) {
      if ($file->isFile()) {
        $files[] = [
          'id' => md5($file->getPathname()),
          'filename' => $file->getFilename(),
          'size' => $file->getSize(),
          'mime_type' => mime_content_type($file->getPathname()),
        ];
      }
    }
    return $files;
  }

  /**
   * Get upload error message.
   */
  protected static function getUploadError(int $code): string
  {
    return match ($code) {
      UPLOAD_ERR_INI_SIZE => 'File exceeds upload_max_filesize directive',
      UPLOAD_ERR_FORM_SIZE => 'File exceeds MAX_FILE_SIZE directive',
      UPLOAD_ERR_PARTIAL => 'File was only partially uploaded',
      UPLOAD_ERR_NO_FILE => 'No file was uploaded',
      UPLOAD_ERR_NO_TMP_DIR => 'Missing temporary folder',
      UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk',
      UPLOAD_ERR_EXTENSION => 'A PHP extension stopped the file upload',
      default => 'Unknown upload error',
    };
  }

  /**
   * Get errors.
   */
  public static function errors(): array
  {
    return self::$errors;
  }

  /**
   * Set allowed MIME types.
   */
  public static function setAllowedTypes(array $types): void
  {
    self::$allowedTypes = $types;
  }

  /**
   * Set maximum file size.
   */
  public static function setMaxSize(int $bytes): void
  {
    self::$maxSize = $bytes;
  }

  /**
   * Stream a file to the browser.
   */
  public static function stream(string $fileId): void
  {
    $file = self::get($fileId);
    if (!$file) {
      http_response_code(404);
      echo 'File not found';
      exit;
    }

    header('Content-Type: ' . $file['mime_type']);
    header('Content-Length: ' . $file['size']);
    header('Content-Disposition: inline; filename="' . $file['filename'] . '"');
    readfile($file['path']);
    exit;
  }
}