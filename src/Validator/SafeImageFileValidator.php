<?php

namespace App\Validator;

use Symfony\Component\HttpFoundation\File\File;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;

class SafeImageFileValidator extends ConstraintValidator
{
    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof SafeImageFile) {
            throw new UnexpectedTypeException($constraint, SafeImageFile::class);
        }

        if (null === $value || '' === $value) {
            return;
        }

        if (!$value instanceof File) {
            throw new UnexpectedTypeException($value, File::class);
        }

        $filename = $value->getFilename();
        $mimeType = $value->getMimeType() ?? 'unknown';

        // Check for SVG files explicitly (dangerous because they can contain JavaScript)
        if ($this->isSvgFile($value, $mimeType)) {
            $this->context->buildViolation($constraint->svgNotAllowedMessage)
                ->addViolation();
            return;
        }

        // Check MIME type
        if (!$this->isAllowedMimeType($mimeType, $constraint->mimeTypes)) {
            $this->context->buildViolation($constraint->invalidMimeTypeMessage)
                ->setParameter('{{ filename }}', $filename)
                ->setParameter('{{ mimeType }}', $mimeType)
                ->setCode(SafeImageFile::INVALID_MIME_TYPE_ERROR)
                ->addViolation();
            return;
        }

        // Check file extension as additional security layer
        if (!$this->isAllowedExtension($value, $constraint->extensions)) {
            $this->context->buildViolation($constraint->message)
                ->setParameter('{{ filename }}', $filename)
                ->setCode(SafeImageFile::FILE_TYPE_NOT_ALLOWED_ERROR)
                ->addViolation();
            return;
        }
    }

    /**
     * Check if file is SVG (either by MIME type or extension).
     */
    private function isSvgFile(File $file, string $mimeType): bool
    {
        // Check MIME type
        if (in_array($mimeType, ['image/svg+xml', 'image/svg', 'text/svg'], true)) {
            return true;
        }

        // Check file extension
        $extension = strtolower($file->guessExtension() ?? '');
        if ($extension === 'svg') {
            return true;
        }

        // Read first bytes to detect SVG by magic number
        try {
            $path = $file->getRealPath();
            if ($path && file_exists($path)) {
                $handle = fopen($path, 'rb');
                if ($handle) {
                    $header = fread($handle, 512);
                    fclose($handle);
                    // Check for SVG XML declaration or SVG tag
                    if (stripos($header, '<?xml') !== false && stripos($header, 'svg') !== false) {
                        return true;
                    }
                    if (stripos($header, '<svg') !== false) {
                        return true;
                    }
                }
            }
        } catch (\Exception) {
            // If we can't read the file, let other checks handle it
        }

        return false;
    }

    /**
     * Check if MIME type is in allowed list.
     * @param string[] $allowedMimeTypes
     */
    private function isAllowedMimeType(string $mimeType, array $allowedMimeTypes): bool
    {
        return in_array($mimeType, $allowedMimeTypes, true);
    }

    /**
     * Check if file extension is in allowed list.
     * @param string[] $allowedExtensions
     */
    private function isAllowedExtension(File $file, array $allowedExtensions): bool
    {
        // Try to get extension from file
        $extension = strtolower($file->guessExtension() ?? '');
        if (!$extension) {
            // Fallback: try to extract from filename
            $filename = $file->getFilename();
            $parts = explode('.', $filename);
            $extension = strtolower(end($parts));
        }

        return in_array($extension, $allowedExtensions, true);
    }
}
