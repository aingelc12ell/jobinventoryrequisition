<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\AttachmentRepository;
use Psr\Http\Message\UploadedFileInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Handles file upload validation, storage, and attachment
 * record management for request attachments.
 */
class FileUploadService
{
    private readonly array $uploadSettings;

    public function __construct(
        private readonly AttachmentRepository $attachmentRepo,
        private readonly LoggerInterface $logger,
        array $uploadSettings = [],
    ) {
        $this->uploadSettings = $uploadSettings ?: [
            'max_size' => 10485760,
            'allowed_types' => [
                'application/pdf',
                'application/msword',
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                'application/vnd.ms-excel',
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'text/csv',
                'image/jpeg',
                'image/png',
                'image/gif',
                'image/webp',
            ],
            'path' => dirname(__DIR__, 2) . '/storage/uploads',
        ];
    }

    /**
     * Upload a file and create an attachment record.
     *
     * @param  UploadedFileInterface $file           The uploaded file.
     * @param  int                   $requestId      The request this file belongs to.
     * @param  int                   $uploadedBy     The user who uploaded the file.
     * @param  array                 $uploadSettings Upload configuration (max_size, allowed_types, path).
     * @return array The created attachment record.
     * @throws RuntimeException If validation fails or the file cannot be moved.
     */
    public function upload(UploadedFileInterface $file, int $requestId, int $uploadedBy, ?array $uploadSettings = null): array
    {
        $uploadSettings = $uploadSettings ?? $this->uploadSettings;

        // Validate the file
        $errors = $this->validateFile($file, $uploadSettings);

        if (!empty($errors)) {
            throw new RuntimeException(implode(' ', $errors));
        }

        // Generate a safe, collision-resistant file name
        $originalName = $file->getClientFilename() ?? 'unnamed';
        $safeName     = time() . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '_', $originalName);

        // Create the target directory
        $directory = rtrim($uploadSettings['path'], '/') . '/' . $requestId;

        if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
            throw new RuntimeException('Failed to create upload directory.');
        }

        $targetPath = $directory . '/' . $safeName;

        // Move the file to its final location
        try {
            $file->moveTo($targetPath);
        } catch (\Exception $e) {
            $this->logger->error('File upload failed', [
                'request_id' => $requestId,
                'file_name'  => $originalName,
                'error'      => $e->getMessage(),
            ]);

            throw new RuntimeException('Failed to save uploaded file.');
        }

        // Create the attachment record
        $attachmentId = $this->attachmentRepo->create([
            'request_id'  => $requestId,
            'file_name'   => $originalName,
            'file_path'   => $targetPath,
            'mime_type'    => $file->getClientMediaType() ?? 'application/octet-stream',
            'file_size'    => $file->getSize(),
            'uploaded_by'  => $uploadedBy,
        ]);

        $this->logger->info('File uploaded', [
            'attachment_id' => $attachmentId,
            'request_id'    => $requestId,
            'file_name'     => $originalName,
        ]);

        return $this->attachmentRepo->findById($attachmentId);
    }

    /**
     * Delete an attachment (both the physical file and the database record).
     *
     * @param  int  $attachmentId The attachment record ID.
     * @return bool True on success.
     * @throws RuntimeException If the attachment is not found.
     */
    public function delete(int $attachmentId): bool
    {
        $attachment = $this->attachmentRepo->findById($attachmentId);

        if ($attachment === null) {
            throw new RuntimeException('Attachment not found.');
        }

        // Delete the physical file if it exists
        if (file_exists($attachment['file_path'])) {
            unlink($attachment['file_path']);
        }

        $this->attachmentRepo->delete($attachmentId);

        $this->logger->info('Attachment deleted', [
            'attachment_id' => $attachmentId,
            'file_name'     => $attachment['file_name'],
        ]);

        return true;
    }

    /**
     * Validate an uploaded file against the upload settings.
     *
     * @param  UploadedFileInterface $file           The uploaded file.
     * @param  array                 $uploadSettings Upload configuration (max_size, allowed_types).
     * @return array An array of error message strings (empty = valid).
     */
    public function validateFile(UploadedFileInterface $file, array $uploadSettings): array
    {
        $errors = [];

        // Check for upload errors
        if ($file->getError() !== UPLOAD_ERR_OK) {
            $errors[] = 'File upload failed with error code: ' . $file->getError() . '.';

            return $errors;
        }

        // Check file size
        $maxSize = $uploadSettings['max_size'] ?? 10485760; // 10 MB default

        if ($file->getSize() > $maxSize) {
            $maxMB   = round($maxSize / 1048576, 1);
            $errors[] = "File size exceeds the maximum allowed size of {$maxMB} MB.";
        }

        // Check MIME type
        $allowedTypes = $uploadSettings['allowed_types'] ?? [];

        if (!empty($allowedTypes)) {
            $mimeType = $file->getClientMediaType() ?? '';

            if (!in_array($mimeType, $allowedTypes, true)) {
                $errors[] = 'File type "' . $mimeType . '" is not allowed. Allowed types: ' . implode(', ', $allowedTypes) . '.';
            }
        }

        return $errors;
    }
}
