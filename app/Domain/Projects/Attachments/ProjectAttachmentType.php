<?php

namespace App\Domain\Projects\Attachments;

use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use ZipArchive;

final class ProjectAttachmentType
{
    /** @return array{mime_type: string, extension: string} */
    public function inspect(UploadedFile $file): array
    {
        $mime = strtolower((string) $file->getMimeType());
        $clientExtension = strtolower($file->getClientOriginalExtension());

        $simple = [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            'image/heic' => 'heic',
            'image/heif' => 'heif',
            'application/pdf' => 'pdf',
        ];
        if (isset($simple[$mime])) {
            return ['mime_type' => $mime, 'extension' => $simple[$mime]];
        }

        if (in_array($mime, ['text/plain', 'text/csv', 'application/csv'], true) && in_array($clientExtension, ['txt', 'csv'], true)) {
            return ['mime_type' => $clientExtension === 'csv' ? 'text/csv' : 'text/plain', 'extension' => $clientExtension];
        }

        if (in_array($clientExtension, ['docx', 'xlsx'], true)
            && in_array($mime, [
                'application/zip',
                'application/x-zip',
                'application/x-zip-compressed',
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ], true)
            && $this->isSafeOfficeDocument($file, $clientExtension)) {
            return [
                'mime_type' => $clientExtension === 'docx'
                    ? 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'
                    : 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'extension' => $clientExtension,
            ];
        }

        throw ValidationException::withMessages(['file' => 'The selected file type is not supported.']);
    }

    private function isSafeOfficeDocument(UploadedFile $file, string $extension): bool
    {
        $zip = new ZipArchive;
        if ($zip->open($file->getRealPath()) !== true) {
            return false;
        }

        try {
            $required = $extension === 'docx' ? 'word/document.xml' : 'xl/workbook.xml';
            if ($zip->locateName('[Content_Types].xml') === false || $zip->locateName($required) === false) {
                return false;
            }

            for ($index = 0; $index < $zip->numFiles; $index++) {
                $name = strtolower((string) $zip->getNameIndex($index));
                if (str_ends_with($name, 'vbaproject.bin')) {
                    return false;
                }
            }

            return true;
        } finally {
            $zip->close();
        }
    }
}
