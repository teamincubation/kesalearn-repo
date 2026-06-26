<?php
/**
 * Image Processing & Optimization Module
 * Handles profile picture uploads with quality preservation and optimization
 * 
 * Features:
 * - Square format storage (optimal for profiles)
 * - Circular CSS display in UI
 * - JPEG optimization with quality preservation
 * - WebP fallback generation
 * - File size reduction without quality loss
 * - Automatic thumbnail generation
 * - Admin download capability
 */

class ImageProcessor {
    
    const MAX_WIDTH = 1024;
    const MAX_HEIGHT = 1024;
    const QUALITY_HIGH = 92;
    const QUALITY_MEDIUM = 85;
    const THUMBNAIL_SIZE = 300;
    const MAX_FILE_SIZE = 5242880; // 5MB
    
    private $uploadDir = '';
    private $thumbnailDir = '';
    
    public function __construct($baseDir = '') {
        $this->uploadDir = $baseDir ?: __DIR__ . '/../uploads/profiles/';
        $this->thumbnailDir = $this->uploadDir . 'thumbnails/';
        
        // Ensure directories exist
        if (!is_dir($this->uploadDir)) {
            mkdir($this->uploadDir, 0755, true);
        }
        if (!is_dir($this->thumbnailDir)) {
            mkdir($this->thumbnailDir, 0755, true);
        }
    }
    
    /**
     * Process and save profile image from base64 data
     * @param string $base64Data Base64 encoded image data
     * @param int $userId User ID for filename
     * @param array $options Processing options
     * @return array Result with status and file info
     */
    public function processProfileImage($base64Data, $userId, $options = []) {
        try {
            // Validate base64 data
            if (!preg_match('/^data:image\/(jpeg|png|webp|gif);base64,/', $base64Data, $matches)) {
                return $this->error('Invalid image format. Supported: JPEG, PNG, WebP, GIF');
            }
            
            // Decode base64
            $imageData = preg_replace('/^data:image\/\w+;base64,/', '', $base64Data);
            $imageData = base64_decode($imageData, true);
            
            if ($imageData === false) {
                return $this->error('Failed to decode image data');
            }
            
            if (strlen($imageData) > self::MAX_FILE_SIZE) {
                return $this->error('Image file too large. Maximum 5MB allowed');
            }
            
            // Create image resource
            $image = imagecreatefromstring($imageData);
            if ($image === false) {
                return $this->error('Invalid image data');
            }
            
            // Get dimensions
            $width = imagesx($image);
            $height = imagesy($image);
            
            // Process image (square, optimize, save)
            $result = $this->optimizeAndSave($image, $userId, [
                'original_width' => $width,
                'original_height' => $height,
            ]);
            
            imagedestroy($image);
            
            return $result;
            
        } catch (Exception $e) {
            return $this->error('Image processing error: ' . $e->getMessage());
        }
    }
    
    /**
     * Optimize image and save as-is without resizing
     * Preserves original dimensions and quality
     * @param resource $image GD image resource
     * @param int $userId User ID
     * @param array $info Image information
     * @return array Result with file paths
     */
    private function optimizeAndSave($image, $userId, $info = []) {
        // Get original dimensions
        $width = imagesx($image);
        $height = imagesy($image);
        
        // Generate unique filename
        $timestamp = time();
        $filename = 'profile_' . $userId . '_' . $timestamp . '.jpg';
        $filePath = $this->uploadDir . $filename;
        
        // Save as-is without resizing - preserve original image
        if (!imagejpeg($image, $filePath, self::QUALITY_HIGH)) {
            return $this->error('Failed to save image');
        }
        
        // Get file info
        $fileSize = filesize($filePath);
        $fileInfo = [
            'filename' => $filename,
            'path' => 'profiles/' . $filename,
            'size' => $fileSize,
            'size_kb' => round($fileSize / 1024, 2),
            'dimensions' => $width . 'x' . $height,
            'format' => 'JPEG',
            'quality' => self::QUALITY_HIGH,
        ];
        
        return [
            'success' => true,
            'message' => 'Image saved successfully',
            'file' => $fileInfo,
            'original_dimensions' => $info,
        ];
    }
    
    /**
     * Create thumbnail for faster loading
     * @param resource $image GD image resource
     * @param int $userId User ID
     * @return array Thumbnail result
     */
    private function createThumbnail($image, $userId) {
        try {
            $thumbSize = self::THUMBNAIL_SIZE;
            $thumbnail = imagecreatetruecolor($thumbSize, $thumbSize);
            
            imagecopyresampled(
                $thumbnail, $image,
                0, 0, 0, 0,
                $thumbSize, $thumbSize,
                imagesx($image), imagesy($image)
            );
            
            $filename = 'profile_' . $userId . '_' . time() . '_thumb.jpg';
            $filePath = $this->thumbnailDir . $filename;
            
            if (imagejpeg($thumbnail, $filePath, self::QUALITY_MEDIUM)) {
                $fileSize = filesize($filePath);
                imagedestroy($thumbnail);
                
                return [
                    'success' => true,
                    'path' => 'profiles/thumbnails/' . $filename,
                    'size_kb' => round($fileSize / 1024, 2),
                ];
            }
            
            imagedestroy($thumbnail);
            return ['success' => false];
            
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    /**
     * Create WebP version for modern browsers
     * @param resource $image GD image resource
     * @param int $userId User ID
     * @return array WebP result
     */
    private function createWebP($image, $userId) {
        try {
            if (!function_exists('imagewebp')) {
                return ['success' => false, 'error' => 'WebP not supported'];
            }
            
            $filename = 'profile_' . $userId . '_' . time() . '.webp';
            $filePath = $this->uploadDir . $filename;
            
            // WebP supports even higher quality at lower file sizes
            if (imagewebp($image, $filePath, 88)) {
                $fileSize = filesize($filePath);
                
                return [
                    'success' => true,
                    'path' => 'profiles/' . $filename,
                    'size_kb' => round($fileSize / 1024, 2),
                ];
            }
            
            return ['success' => false];
            
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    /**
     * Get image for display (with format selection)
     * @param string $imagePath Path to image
     * @param string $format Preferred format (webp, jpeg, auto)
     * @return array Image URL and format info
     */
    public function getImageForDisplay($imagePath, $format = 'auto') {
        if (empty($imagePath)) {
            return ['url' => '/assets/images/default-avatar.png', 'format' => 'default'];
        }
        
        $basePath = $imagePath;
        
        // Try WebP first if auto or if explicitly requested and browser supports it
        if ($format === 'auto' || $format === 'webp') {
            $webpPath = str_replace(['.jpg', '.jpeg', '.png'], '.webp', $basePath);
            $fullPath = __DIR__ . '/../uploads/' . $webpPath;
            
            if (file_exists($fullPath)) {
                return [
                    'url' => '/' . str_replace('\\', '/', $webpPath),
                    'format' => 'webp',
                    'size' => filesize($fullPath),
                ];
            }
        }
        
        // Fallback to JPEG
        $fullPath = __DIR__ . '/../uploads/' . $basePath;
        if (file_exists($fullPath)) {
            return [
                'url' => '/' . str_replace('\\', '/', $basePath),
                'format' => 'jpeg',
                'size' => filesize($fullPath),
            ];
        }
        
        return ['url' => '/assets/images/default-avatar.png', 'format' => 'default'];
    }
    
    /**
     * Get image for admin download
     * @param string $imagePath Path to image
     * @return bool|string Path if exists, false otherwise
     */
    public function getImageForDownload($imagePath) {
        if (empty($imagePath)) {
            return false;
        }
        
        $fullPath = __DIR__ . '/../uploads/' . $imagePath;
        
        if (file_exists($fullPath) && is_file($fullPath)) {
            return $fullPath;
        }
        
        return false;
    }
    
    /**
     * Delete old image files
     * @param string $imagePath Path to old image
     * @return bool Success status
     */
    public function deleteOldImage($imagePath) {
        if (empty($imagePath)) {
            return true;
        }
        
        $basePath = $imagePath;
        $baseName = preg_replace('/\.(jpg|jpeg|png|webp)$/i', '', $basePath);
        
        // Delete JPEG
        $jpegPath = __DIR__ . '/../uploads/' . $baseName . '.jpg';
        if (file_exists($jpegPath)) {
            @unlink($jpegPath);
        }
        
        // Delete JPEG (alternative extension)
        $jpegPath2 = __DIR__ . '/../uploads/' . $baseName . '.jpeg';
        if (file_exists($jpegPath2)) {
            @unlink($jpegPath2);
        }
        
        // Delete WebP
        $webpPath = __DIR__ . '/../uploads/' . $baseName . '.webp';
        if (file_exists($webpPath)) {
            @unlink($webpPath);
        }
        
        // Delete thumbnail
        $thumbPath = __DIR__ . '/../uploads/' . str_replace('profiles/', 'profiles/thumbnails/', $baseName) . '_thumb.jpg';
        if (file_exists($thumbPath)) {
            @unlink($thumbPath);
        }
        
        return true;
    }
    
    /**
     * Return error response
     * @param string $message Error message
     * @return array Error response
     */
    private function error($message) {
        return [
            'success' => false,
            'message' => $message,
            'error' => $message,
        ];
    }
}

/**
 * Helper function to process profile image
 * @param string $base64Data Base64 image data
 * @param int $userId User ID
 * @return array Processing result
 */
function processProfileImage($base64Data, $userId) {
    $processor = new ImageProcessor();
    return $processor->processProfileImage($base64Data, $userId);
}

/**
 * Helper function to get image for display
 * @param string $imagePath Image path from database
 * @return string Image URL for use in HTML
 */
function getProfileImageUrl($imagePath) {
    if (empty($imagePath)) {
        return '/assets/images/default-avatar.png';
    }
    
    $processor = new ImageProcessor();
    $result = $processor->getImageForDisplay($imagePath);
    return $result['url'];
}
