<?php

namespace Canyon\Core;

/**
 * Canyon Framework - Image Optimizer
 *
 * Image optimization with lossless/lossy compression, AVIF/WebP support,
 * and responsive image generation. QUIC.cloud image optimization.
 */
class ImageOptimizer
{
  /** @var array Optimization settings */
  protected static array $settings = [
    'quality' => 85,       // JPEG quality (0-100)
    'webp_quality' => 80,     // WebP quality
    'avif_quality' => 75,     // AVIF quality
    'lossless' => false,     // Use lossless compression
    'generate_webp' => true,   // Generate WebP versions
    'generate_avif' => false,   // Generate AVIF versions
    'responsive' => true,     // Generate responsive variants
    'responsive_widths' => [320, 480, 768, 1024, 1280, 1920],
    'max_width' => 1920,     // Maximum image width
  ];

  /** @var array Optimization log */
  protected static array $log = [];

  /** @var string Image directory */
  protected static string $imageDir;

  /** @var string Optimized image cache directory */
  protected static string $cacheDir;

  /**
   * Initialize image optimizer.
   */
  public static function init(): void
  {
    self::$imageDir = CANYON_BASE_PATH . '/public/uploads';
    self::$cacheDir = CANYON_STORAGE_PATH . '/cache/images';
    foreach ([self::$imageDir, self::$cacheDir] as $dir) {
      if (!is_dir($dir)) {
        mkdir($dir, 0750, true);
      }
    }
  }

  // -----------------------------------------------------------------------
  // Upload & Optimize
  // -----------------------------------------------------------------------

  /**
   * Optimize an uploaded image file.
   */
  public static function optimizeUpload(array $file, string $directory = 'images'): ?array
  {
    self::init();

    if (!isset($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
      return null;
    }

    $sourcePath = $file['tmp_name'];
    $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

    // Only optimize raster images
    if (!in_array($extension, ['jpg', 'jpeg', 'png', 'gif', 'webp'])) {
      return null;
    }

    // Generate safe filename
    $filename = uniqid('img_', true) . '.' . $extension;
    $targetDir = self::$imageDir . '/' . $directory;
    if (!is_dir($targetDir)) {
      mkdir($targetDir, 0750, true);
    }

    $targetPath = $targetDir . '/' . $filename;

    // Optimize image type
    if (self::isImagickAvailable()) {
      $result = self::optimizeWithImagick($sourcePath, $targetPath);
    } elseif (self::isGdAvailable()) {
      $result = self::optimizeWithGd($sourcePath, $targetPath);
    } else {
      // No image library available, just move the file
      move_uploaded_file($sourcePath, $targetPath);
      $result = ['success' => true, 'optimized' => false];
    }

    if (!$result['success']) {
      return null;
    }

    $metadata = [
      'original_name' => $file['name'],
      'filename' => $filename,
      'directory' => $directory,
      'path' => $targetPath,
      'url' => '/uploads/' . $directory . '/' . $filename,
      'size' => filesize($targetPath),
      'width' => $result['width'] ?? null,
      'height' => $result['height'] ?? null,
      'optimized' => $result['optimized'] ?? false,
      'original_size' => $file['size'],
      'savings_percent' => isset($result['original_size']) ? round((1 - $result['size'] / $file['size']) * 100, 1) : 0,
    ];

    // Generate WebP version
    if (self::$settings['generate_webp']) {
      $metadata['webp'] = self::generateWebP($targetPath, $directory, $filename);
    }

    // Generate AVIF version
    if (self::$settings['generate_avif'] && self::isImagickAvailable()) {
      $metadata['avif'] = self::generateAvif($targetPath, $directory, $filename);
    }

    // Generate responsive variants
    if (self::$settings['responsive']) {
      $metadata['responsive'] = self::generateResponsive($targetPath, $directory, $filename);
    }

    self::$log[] = [
      'file' => $file['name'],
      'action' => 'optimize_upload',
      'result' => $metadata,
    ];

    return $metadata;
  }

  // -----------------------------------------------------------------------
  // ImageMagick Optimization
  // -----------------------------------------------------------------------

  /**
   * Optimize using Imagick.
   */
  protected static function optimizeWithImagick(string $source, string $target): array
  {
    try {
      $imagick = new \Imagick($source);

      // Auto-orient EXIF
      $imagick->autoOrient();

      // Strip metadata
      $imagick->stripImage();

      // Resize if too large
      $width = $imagick->getImageWidth();
      $height = $imagick->getImageHeight();
      if ($width > self::$settings['max_width']) {
        $newHeight = (int) round($height * self::$settings['max_width'] / $width);
        $imagick->resizeImage(self::$settings['max_width'], $newHeight, \Imagick::FILTER_LANCZOS, 1);
      }

      // Set quality
      $format = $imagick->getImageFormat();
      if (in_array($format, ['JPEG', 'JPG'])) {
        $imagick->setImageCompressionQuality(self::$settings['quality']);
        if (self::$settings['lossless']) {
          $imagick->setImageFormat('webp');
          $imagick->setImageCompressionQuality(100);
        }
      }

      $imagick->writeImage($target);
      $imagick->clear();
      $imagick->destroy();

      return [
        'success' => true,
        'optimized' => true,
        'width' => $width,
        'height' => $height,
        'size' => filesize($target),
        'original_size' => filesize($source),
      ];
    } catch (\Exception $e) {
      // Fall back to GD
      return self::optimizeWithGd($source, $target);
    }
  }

  // -----------------------------------------------------------------------
  // GD Optimization
  // -----------------------------------------------------------------------

  /**
   * Optimize using GD library.
   */
  protected static function optimizeWithGd(string $source, string $target): array
  {
    $info = getimagesize($source);
    if (!$info) {
      return ['success' => false];
    }

    [$width, $height, $type] = $info;

    // Create source image
    $srcImage = match ($type) {
      IMAGETYPE_JPEG => imagecreatefromjpeg($source),
      IMAGETYPE_PNG => imagecreatefrompng($source),
      IMAGETYPE_GIF => imagecreatefromgif($source),
      default => null,
    };

    if (!$srcImage) {
      return ['success' => false];
    }

    // Calculate new dimensions
    $newWidth = $width;
    $newHeight = $height;
    if ($width > self::$settings['max_width']) {
      $newWidth = self::$settings['max_width'];
      $newHeight = (int) round($height * $newWidth / $width);
    }

    // Create target image
    $dstImage = imagecreatetruecolor($newWidth, $newHeight);

    // Preserve transparency for PNG/GIF
    if ($type === IMAGETYPE_PNG || $type === IMAGETYPE_GIF) {
      imagealphablending($dstImage, false);
      imagesavealpha($dstImage, true);
    }

    imagecopyresampled($dstImage, $srcImage, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);

    // Save optimized image
    $result = match ($type) {
      IMAGETYPE_JPEG => imagejpeg($dstImage, $target, self::$settings['quality']),
      IMAGETYPE_PNG => imagepng($dstImage, $target, (int) round((100 - self::$settings['quality']) / 11.1)),
      IMAGETYPE_GIF => imagegif($dstImage, $target),
      default => false,
    };

    imagedestroy($srcImage);
    imagedestroy($dstImage);

    return [
      'success' => $result,
      'optimized' => true,
      'width' => $newWidth,
      'height' => $newHeight,
      'size' => filesize($target),
      'original_size' => filesize($source),
    ];
  }

  // -----------------------------------------------------------------------
  // WebP Generation
  // -----------------------------------------------------------------------

  /**
   * Generate WebP version of an image.
   */
  public static function generateWebP(string $sourcePath, string $directory, string $filename): ?array
  {
    if (!function_exists('imagewebp')) {
      return null;
    }

    $webpFilename = pathinfo($filename, PATHINFO_FILENAME) . '.webp';
    $webpPath = self::$imageDir . '/' . $directory . '/' . $webpFilename;

    $info = getimagesize($sourcePath);
    if (!$info) return null;

    $srcImage = match ($info[2]) {
      IMAGETYPE_JPEG => imagecreatefromjpeg($sourcePath),
      IMAGETYPE_PNG => imagecreatefrompng($sourcePath),
      IMAGETYPE_GIF => imagecreatefromgif($sourcePath),
      default => null,
    };

    if (!$srcImage) return null;

    // Preserve transparency
    imagepalettetotruecolor($srcImage);
    imagealphablending($srcImage, true);
    imagesavealpha($srcImage, true);

    $result = imagewebp($srcImage, $webpPath, self::$settings['webp_quality']);
    imagedestroy($srcImage);

    if (!$result) return null;

    return [
      'filename' => $webpFilename,
      'path' => $webpPath,
      'url' => '/uploads/' . $directory . '/' . $webpFilename,
      'size' => filesize($webpPath),
    ];
  }

  // -----------------------------------------------------------------------
  // AVIF Generation
  // -----------------------------------------------------------------------

  /**
   * Generate AVIF version of an image.
   */
  public static function generateAvif(string $sourcePath, string $directory, string $filename): ?array
  {
    if (!function_exists('imageavif')) {
      return null;
    }

    $avifFilename = pathinfo($filename, PATHINFO_FILENAME) . '.avif';
    $avifPath = self::$imageDir . '/' . $directory . '/' . $avifFilename;

    $info = getimagesize($sourcePath);
    if (!$info) return null;

    $srcImage = match ($info[2]) {
      IMAGETYPE_JPEG => imagecreatefromjpeg($sourcePath),
      IMAGETYPE_PNG => imagecreatefrompng($sourcePath),
      default => null,
    };

    if (!$srcImage) return null;

    $result = imageavif($srcImage, $avifPath, self::$settings['avif_quality']);
    imagedestroy($srcImage);

    if (!$result) return null;

    return [
      'filename' => $avifFilename,
      'path' => $avifPath,
      'url' => '/uploads/' . $directory . '/' . $avifFilename,
      'size' => filesize($avifPath),
    ];
  }

  // -----------------------------------------------------------------------
  // Responsive Images
  // -----------------------------------------------------------------------

  /**
   * Generate responsive image variants.
   */
  public static function generateResponsive(string $sourcePath, string $directory, string $filename): array
  {
    $variants = [];
    $info = getimagesize($sourcePath);
    if (!$info) return $variants;

    $originalWidth = $info[0];
    $originalHeight = $info[1];

    foreach (self::$settings['responsive_widths'] as $width) {
      if ($width >= $originalWidth) continue;

      $variantFilename = pathinfo($filename, PATHINFO_FILENAME) . "_{$width}w." . pathinfo($filename, PATHINFO_EXTENSION);
      $variantPath = self::$imageDir . '/' . $directory . '/' . $variantFilename;

      $newHeight = (int) round($originalHeight * $width / $originalWidth);

      $srcImage = imagecreatefromjpeg($sourcePath);
      if (!$srcImage) continue;

      $dstImage = imagecreatetruecolor($width, $newHeight);
      imagecopyresampled($dstImage, $srcImage, 0, 0, 0, 0, $width, $newHeight, $originalWidth, $originalHeight);
      imagejpeg($dstImage, $variantPath, self::$settings['quality']);
      imagedestroy($srcImage);
      imagedestroy($dstImage);

      $variants[$width] = [
        'filename' => $variantFilename,
        'url' => '/uploads/' . $directory . '/' . $variantFilename,
        'width' => $width,
        'height' => $newHeight,
        'size' => filesize($variantPath),
      ];
    }

    return $variants;
  }

  // -----------------------------------------------------------------------
  // Utility
  // -----------------------------------------------------------------------

  /**
   * Check if Imagick is available.
   */
  public static function isImagickAvailable(): bool
  {
    return extension_loaded('imagick') && class_exists('Imagick');
  }

  /**
   * Check if GD is available.
   */
  public static function isGdAvailable(): bool
  {
    return extension_loaded('gd');
  }

  /**
   * Generate srcset attribute for responsive images.
   */
  public static function srcset(array $responsiveVariants): string
  {
    $srcset = [];
    foreach ($responsiveVariants as $variant) {
      $srcset[] = $variant['url'] . ' ' . $variant['width'] . 'w';
    }
    return implode(', ', $srcset);
  }

  /**
   * Generate picture element with WebP/AVIF fallbacks.
   */
  public static function picture(string $imageUrl, array $metadata = [], string $alt = '', string $class = ''): string
  {
    $classAttr = $class ? " class=\"{$class}\"" : '';
    $altAttr = $alt ? " alt=\"{$alt}\"" : '';

    $html = "<picture{$classAttr}>";

    // AVIF source
    if (isset($metadata['avif'])) {
      $html .= "<source srcset=\"{$metadata['avif']['url']}\" type=\"image/avif\">";
    }

    // WebP source
    if (isset($metadata['webp'])) {
      $html .= "<source srcset=\"{$metadata['webp']['url']}\" type=\"image/webp\">";
    }

    // Original with responsive srcset
    $srcset = isset($metadata['responsive']) ? ' srcset="' . self::srcset($metadata['responsive']) . '"' : '';
    $html .= "<img src=\"{$imageUrl}\"{$srcset}{$altAttr} loading=\"lazy\" decoding=\"async\">";

    $html .= "</picture>";
    return $html;
  }

  /**
   * Delete an image and all its variants.
   */
  public static function delete(string $directory, string $filename): bool
  {
    $path = self::$imageDir . '/' . $directory . '/' . $filename;
    $result = true;

    // Delete original
    if (file_exists($path)) {
      $result = unlink($path) && $result;
    }

    // Delete WebP
    $webp = pathinfo($path, PATHINFO_DIRNAME) . '/' . pathinfo($filename, PATHINFO_FILENAME) . '.webp';
    if (file_exists($webp)) {
      $result = unlink($webp) && $result;
    }

    // Delete AVIF
    $avif = pathinfo($path, PATHINFO_DIRNAME) . '/' . pathinfo($filename, PATHINFO_FILENAME) . '.avif';
    if (file_exists($avif)) {
      $result = unlink($avif) && $result;
    }

    // Delete responsive variants
    foreach (self::$settings['responsive_widths'] as $width) {
      $variant = pathinfo($path, PATHINFO_DIRNAME) . '/' . pathinfo($filename, PATHINFO_FILENAME) . "_{$width}w." . pathinfo($filename, PATHINFO_EXTENSION);
      if (file_exists($variant)) {
        $result = unlink($variant) && $result;
      }
    }

    return $result;
  }

  /**
   * Set optimization settings.
   */
  public static function configure(array $settings): void
  {
    self::$settings = array_merge(self::$settings, $settings);
  }

  /**
   * Get settings.
   */
  public static function getSettings(): array
  {
    return self::$settings;
  }

  /**
   * Get optimization log.
   */
  public static function getLog(): array
  {
    return self::$log;
  }

  /**
   * Clear log.
   */
  public static function clearLog(): void
  {
    self::$log = [];
  }

  /**
   * Get optimization stats.
   */
  public static function getStats(): array
  {
    $totalOriginal = 0;
    $totalOptimized = 0;

    foreach (self::$log as $entry) {
      if (isset($entry['result']['original_size'])) {
        $totalOriginal += $entry['result']['original_size'];
        $totalOptimized += $entry['result']['size'];
      }
    }

    return [
      'total_optimized' => count(self::$log),
      'total_original_size' => $totalOriginal,
      'total_optimized_size' => $totalOptimized,
      'total_savings_bytes' => $totalOriginal - $totalOptimized,
      'total_savings_percent' => $totalOriginal > 0 ? round((1 - $totalOptimized / $totalOriginal) * 100, 1) : 0,
    ];
  }
}