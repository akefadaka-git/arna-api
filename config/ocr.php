<?php

declare(strict_types=1);

return [
    'enabled' => (bool) env('ARNA_OCR_ENABLED', false),
    'queue' => env('ARNA_OCR_QUEUE', 'ocr'),
    'connection' => env('ARNA_OCR_QUEUE_CONNECTION', env('QUEUE_CONNECTION', 'database')),
    'engine' => env('ARNA_OCR_ENGINE', 'tesseract'),
    'tesseract_binary' => env('ARNA_OCR_TESSERACT_BINARY', '/usr/bin/tesseract'),
    'pdftoppm_binary' => env('ARNA_OCR_PDFTOPPM_BINARY', '/usr/bin/pdftoppm'),
    'pdfinfo_binary' => env('ARNA_OCR_PDFINFO_BINARY', '/usr/bin/pdfinfo'),
    'languages' => ['amh', 'eng'],
    'default_language' => 'amh+eng',
    'max_file_bytes' => (int) env('ARNA_OCR_MAX_FILE_BYTES', 26214400),
    'max_result_bytes' => (int) env('ARNA_OCR_MAX_RESULT_BYTES', 10485760),
    'max_pages' => (int) env('ARNA_OCR_MAX_PAGES', 100),
    'pdf_dpi' => (int) env('ARNA_OCR_PDF_DPI', 200),
    'timeout_seconds' => (int) env('ARNA_OCR_TIMEOUT_SECONDS', 90),
    'temp_directory' => env('ARNA_OCR_TEMP_DIRECTORY', storage_path('app/private/ocr-tmp')),
    'allowed_mime_types' => ['application/pdf', 'image/jpeg', 'image/png', 'image/tiff'],
    'allowed_extensions' => ['pdf', 'jpg', 'jpeg', 'png', 'tif', 'tiff'],
];
