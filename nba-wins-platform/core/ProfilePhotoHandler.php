<?php
// /data/www/default/nba-wins-platform/core/ProfilePhotoHandler.php
// Secure Profile Photo Upload Handler

class ProfilePhotoHandler {
    private $pdo;
    private $uploadDir;
    private $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    private $maxFileSize = 5 * 1024 * 1024; // 5MB
    private $maxWidth = 800;
    private $maxHeight = 800;
    
    public function __construct($pdo) {
        $this->pdo = $pdo;
        $this->uploadDir = '/data/www/default/nba-wins-platform/public/assets/profile_photos/';
        
        // Create directory if it doesn't exist
        if (!is_dir($this->uploadDir)) {
            mkdir($this->uploadDir, 0755, true);
        }
    }
    
    /**
     * Upload and process profile photo
     */
    public function uploadPhoto($userId, $file) {
        try {
            // Validate file
            $validation = $this->validateFile($file);
            if ($validation !== true) {
                return ['success' => false, 'error' => $validation];
            }
            
            // Generate unique filename
            $extension = $this->getFileExtension($file['type']);
            $filename = $this->generateUniqueFilename($userId, $extension);
            $filepath = $this->uploadDir . $filename;
            
            // Get current photo to delete later
            $currentPhoto = $this->getCurrentPhoto($userId);
            
            // Process and resize image
            $result = $this->processImage($file['tmp_name'], $filepath, $file['type']);
            if (!$result) {
                return ['success' => false, 'error' => 'Failed to process image'];
            }
            
            // Update database
            $updated = $this->updateUserPhoto($userId, $filename);
            if (!$updated) {
                // Clean up uploaded file if database update failed
                if (file_exists($filepath)) {
                    unlink($filepath);
                }
                return ['success' => false, 'error' => 'Failed to update database'];
            }
            
            // Delete old photo if it exists and isn't default
            if ($currentPhoto && $currentPhoto !== 'default.png') {
                $oldPath = $this->uploadDir . $currentPhoto;
                if (file_exists($oldPath)) {
                    unlink($oldPath);
                }
            }
            
            return [
                'success' => true, 
                'filename' => $filename,
                'message' => 'Profile photo updated successfully!'
            ];
            
        } catch (Exception $e) {
            error_log("Profile photo upload error: " . $e->getMessage());
            return ['success' => false, 'error' => 'Upload failed: ' . $e->getMessage()];
        }
    }
    
    /**
     * Delete user's profile photo
     */
    public function deletePhoto($userId) {
        try {
            $currentPhoto = $this->getCurrentPhoto($userId);
            
            // Update database to remove photo
            $stmt = $this->pdo->prepare("UPDATE users SET profile_photo = NULL WHERE id = ?");
            $updated = $stmt->execute([$userId]);
            
            if (!$updated) {
                return ['success' => false, 'error' => 'Failed to update database'];
            }
            
            // Delete physical file if it exists and isn't default
            if ($currentPhoto && $currentPhoto !== 'default.png') {
                $filepath = $this->uploadDir . $currentPhoto;
                if (file_exists($filepath)) {
                    unlink($filepath);
                }
            }
            
            return ['success' => true, 'message' => 'Profile photo deleted successfully!'];
            
        } catch (Exception $e) {
            error_log("Profile photo deletion error: " . $e->getMessage());
            return ['success' => false, 'error' => 'Deletion failed: ' . $e->getMessage()];
        }
    }
    
    /**
     * Get user's profile photo URL
     */
    public function getPhotoUrl($userId, $filename = null) {
        if (!$filename) {
            $filename = $this->getCurrentPhoto($userId);
        }
        
        if (!$filename) {
            return '/nba-wins-platform/public/assets/profile_photos/default.png';
        }
        
        $filepath = $this->uploadDir . $filename;
        if (!file_exists($filepath)) {
            return '/nba-wins-platform/public/assets/profile_photos/default.png';
        }
        
        return '/nba-wins-platform/public/assets/profile_photos/' . $filename;
    }
    
    /**
     * Validate uploaded file
     */
    private function validateFile($file) {
        // Check for upload errors
        if ($file['error'] !== UPLOAD_ERR_OK) {
            switch ($file['error']) {
                case UPLOAD_ERR_INI_SIZE:
                case UPLOAD_ERR_FORM_SIZE:
                    return 'File is too large';
                case UPLOAD_ERR_PARTIAL:
                    return 'File upload was interrupted';
                case UPLOAD_ERR_NO_FILE:
                    return 'No file was uploaded';
                default:
                    return 'Upload error occurred';
            }
        }
        
        // Check file size
        if ($file['size'] > $this->maxFileSize) {
            return 'File is too large. Maximum size is 5MB';
        }
        
        // Check file type
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);
        
        if (!in_array($mimeType, $this->allowedTypes)) {
            return 'Invalid file type. Only JPEG, PNG, GIF, and WebP images are allowed';
        }
        
        // Verify it's actually an image
        $imageInfo = getimagesize($file['tmp_name']);
        if (!$imageInfo) {
            return 'Invalid image file';
        }
        
        return true;
    }
    
    /**
     * Process and resize image
     */
    private function processImage($sourcePath, $destinationPath, $mimeType) {
        $imageInfo = getimagesize($sourcePath);
        if (!$imageInfo) {
            return false;
        }
        
        list($width, $height) = $imageInfo;
        
        // Calculate new dimensions
        $ratio = min($this->maxWidth / $width, $this->maxHeight / $height);
        $newWidth = intval($width * $ratio);
        $newHeight = intval($height * $ratio);
        
        // Create source image
        switch ($mimeType) {
            case 'image/jpeg':
                $source = imagecreatefromjpeg($sourcePath);
                break;
            case 'image/png':
                $source = imagecreatefrompng($sourcePath);
                break;
            case 'image/gif':
                $source = imagecreatefromgif($sourcePath);
                break;
            case 'image/webp':
                $source = imagecreatefromwebp($sourcePath);
                break;
            default:
                return false;
        }
        
        if (!$source) {
            return false;
        }
        
        // Create destination image
        $destination = imagecreatetruecolor($newWidth, $newHeight);
        
        // Preserve transparency for PNG and GIF
        if ($mimeType === 'image/png' || $mimeType === 'image/gif') {
            imagealphablending($destination, false);
            imagesavealpha($destination, true);
            $transparent = imagecolorallocatealpha($destination, 255, 255, 255, 127);
            imagefilledrectangle($destination, 0, 0, $newWidth, $newHeight, $transparent);
        }
        
        // Resize image
        imagecopyresampled(
            $destination, $source,
            0, 0, 0, 0,
            $newWidth, $newHeight, $width, $height
        );
        
        // Save image (always save as JPEG for consistency)
        $result = imagejpeg($destination, $destinationPath, 90);
        
        // Clean up memory
        imagedestroy($source);
        imagedestroy($destination);
        
        return $result;
    }
    
    /**
     * Generate unique filename
     */
    private function generateUniqueFilename($userId, $extension) {
        $timestamp = time();
        $random = mt_rand(1000, 9999);
        return "profile_{$userId}_{$timestamp}_{$random}.jpg"; // Always use .jpg extension
    }
    
    /**
     * Get file extension from MIME type
     */
    private function getFileExtension($mimeType) {
        switch ($mimeType) {
            case 'image/jpeg':
                return 'jpg';
            case 'image/png':
                return 'png';
            case 'image/gif':
                return 'gif';
            case 'image/webp':
                return 'webp';
            default:
                return 'jpg';
        }
    }
    
    /**
     * Get user's current photo filename
     */
    private function getCurrentPhoto($userId) {
        $stmt = $this->pdo->prepare("SELECT profile_photo FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $result = $stmt->fetch(PDO::FETCH_COLUMN);
        return $result;
    }
    
    /**
     * Update user's photo in database
     */
    private function updateUserPhoto($userId, $filename) {
        $stmt = $this->pdo->prepare("UPDATE users SET profile_photo = ?, updated_at = NOW() WHERE id = ?");
        return $stmt->execute([$filename, $userId]);
    }
}
?>