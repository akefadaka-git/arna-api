<?php

declare(strict_types=1);

namespace MaaleArna\\MASES\\Core\\Domains\\Shareholder\\Services;

/**
 * Parses a UTF-8 CSV file and delegates identity validation.
 *
 * The service is preview-only: it has no database, storage, or domain-write
 * dependency. Source documents and workbook formats require a separate,
 * evidence-backed adapter; this adapter intentionally accepts CSV only.
 */
final class ShareholderCsvPreviewService
{
    private const MAX_BYTES = 5_242_880;
    private const MAX_ROWS = 2_000;

    public function __construct(
        private readonly ShareholderImportPreviewValidator $validator,
    ) {
    }

    /**
     * @return array<string,mixed>
     */
    public function preview(string $contents): array
    {
        if ($contents === '') {
            return $this->fileError('The CSV file is empty.');
        }

        if (strlen($contents) > self::MAX_BYTES) {
            return $this->fileError('The CSV file exceeds the 5 MiB preview limit.');
        }

        if (! mb_check_encoding($contents, 'UTF-8')) {
            return $this->fileError('The CSV file must be UTF-8 encoded.');
        }

        $stream = fopen('php://temp', 'r+');
        if ($stream === false) {
            return $this->fileError('The CSV preview stream could not be opened.');
        }

        try {
            fwrite($stream, $contents);
            rewind($stream);

            $headers = fgetcsv($stream, null, ',', '"', '');
            if (! is_array($headers) || $headers === []) {
                return $this->fileError('A CSV header row is required.');
            }

            $headers[0] = preg_replace('/^\\xEF\\xBB\\xBF/', '', (string) $headers[0]) ?? (string) $headers[0];
            $headers = array_map(
                static fn (mixed $header): string => strtolower(trim((string) $header)),
                $headers,
            );

            if (count($headers) !== count(array_unique($headers))) {
                return $this->fileError('Duplicate CSV column names are not allowed.');
            }

            $unknownHeaders = array_values(array_diff(
                $headers,
                ShareholderImportPreviewValidator::ALLOWED_FIELDS,
            ));
            if ($unknownHeaders !== []) {
                return $this->fileError(
                    'Unsupported CSV columns: ' . implode(', ', $unknownHeaders) . '. Only shareholder identity fields are accepted.',
                );
            }

            foreach (['source_reference', 'shareholder_type'] as $required) {
                if (! in_array($required, $headers, true)) {
                    return $this->fileError(sprintf('The required CSV column "%s" is missing.', $required));
                }
            }

            $rows = [];
            $recordNumber = 1;
            while (($cells = fgetcsv($stream, null, ',', '"', '')) !== false) {
                $recordNumber++;

                if ($cells === [null] || $cells === []) {
                    continue;
                }

                if (count($rows) >= self::MAX_ROWS) {
                    return $this->fileError('The CSV file exceeds the 2,000-row preview limit.');
                }

                $nonEmpty = array_filter(
                    $cells,
                    static fn (mixed $cell): bool => trim((string) $cell) !== '',
                );
                if ($nonEmpty === []) {
                    continue;
                }

                $row = ['_row_number' => $recordNumber];
                if (count($cells) > count($headers)) {
                    $row['_row_error'] = 'The CSV row contains more values than the header defines.';
                }

                foreach ($headers as $index => $header) {
                    $row[$header] = $cells[$index] ?? null;
                }

                $rows[] = $row;
            }

            if ($rows === []) {
                return $this->fileError('The CSV contains a header but no data rows.');
            }

            $result = $this->validator->preview($rows);
            $result['format'] = 'csv';
            $result['source_file_stored'] = false;

            return $result;
        } finally {
            fclose($stream);
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function fileError(string $message): array
    {
        return [
            'mode' => 'preview_only',
            'format' => 'csv',
            'writes_performed' => false,
            'source_file_stored' => false,
            'can_proceed_to_manual_review' => false,
            'summary' => ['total' => 0, 'valid' => 0, 'invalid' => 0],
            'file_errors' => [$message],
            'rows' => [],
        ];
    }
}
