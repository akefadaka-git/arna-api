<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Domains\Shareholder\Services;

use MaaleArna\MASES\Core\Domains\Shareholder\Enums\ShareholderType;

/**
 * Validates shareholder identity rows for a non-destructive import preview.
 *
 * This class deliberately has no persistence dependency. It does not create,
 * update, match, or number shareholder records and does not accept holdings,
 * share quantities, subscriptions, capital, or payment data.
 */
final class ShareholderImportPreviewValidator
{
    /** @var list<string> */
    public const ALLOWED_FIELDS = [
        'source_reference',
        'shareholder_type',
        'first_name_am',
        'father_name_am',
        'grandfather_name_am',
        'first_name_en',
        'father_name_en',
        'grandfather_name_en',
        'entity_name_am',
        'entity_name_en',
        'representative_reference',
    ];

    /**
     * @param list<array<string, mixed>> $rows
     * @return array{
     *   mode:string,writes_performed:false,can_proceed_to_manual_review:bool,
     *   summary:array{total:int,valid:int,invalid:int},
     *   rows:list<array<string,mixed>>
     * }
     */
    public function preview(array $rows): array
    {
        $seenReferences = [];
        $previewRows = [];
        $validCount = 0;

        foreach ($rows as $index => $input) {
            $rowNumber = is_array($input) && isset($input['_row_number'])
                ? (int) $input['_row_number']
                : $index + 1;
            $errors = [];

            if (! is_array($input)) {
                $previewRows[] = [
                    'row_number' => $rowNumber,
                    'valid' => false,
                    'identity' => [],
                    'errors' => ['row' => ['The row must be an object of named fields.']],
                ];
                continue;
            }

            foreach (array_diff(array_keys($input), [...self::ALLOWED_FIELDS, '_row_number', '_row_error']) as $unknown) {
                $errors[(string) $unknown][] = 'This field is not accepted by the identity-only import contract.';
            }

            if (isset($input['_row_error'])) {
                $errors['row'][] = (string) $input['_row_error'];
            }

            $values = [];
            foreach (self::ALLOWED_FIELDS as $field) {
                $value = $input[$field] ?? null;

                if ($value !== null && ! is_scalar($value)) {
                    $errors[$field][] = 'The value must be plain text.';
                    $values[$field] = null;
                    continue;
                }

                $normalised = $value === null ? null : trim((string) $value);
                $values[$field] = $normalised === '' ? null : $normalised;

                if ($values[$field] !== null && mb_strlen($values[$field]) > 255) {
                    $errors[$field][] = 'The value must not exceed 255 characters.';
                }
            }

            $reference = $values['source_reference'];
            if ($reference === null) {
                $errors['source_reference'][] = 'A source reference is required.';
            } else {
                $referenceKey = strtolower($reference);
                if (isset($seenReferences[$referenceKey])) {
                    $errors['source_reference'][] = sprintf(
                        'Duplicate source reference; first seen on row %d.',
                        $seenReferences[$referenceKey],
                    );
                } else {
                    $seenReferences[$referenceKey] = $rowNumber;
                }
            }

            $typeValue = $values['shareholder_type'];
            $type = $typeValue === null ? null : ShareholderType::tryFrom($typeValue);
            if ($type === null) {
                $errors['shareholder_type'][] = 'A valid shareholder type is required.';
            } elseif ($type->isIndividual()) {
                if ($values['first_name_am'] === null) {
                    $errors['first_name_am'][] = 'An Amharic first name is required for an individual.';
                }
                if ($values['father_name_am'] === null) {
                    $errors['father_name_am'][] = 'An Amharic father name is required for an individual.';
                }
                if ($values['entity_name_am'] !== null || $values['entity_name_en'] !== null) {
                    $errors['entity_name_am'][] = 'Entity names cannot be supplied for an individual.';
                }
            } else {
                if ($values['entity_name_am'] === null) {
                    $errors['entity_name_am'][] = 'An Amharic legal-entity name is required.';
                }
                if ($values['representative_reference'] === null) {
                    $errors['representative_reference'][] = 'A representative source reference is required for a legal entity.';
                }
                foreach (['first_name_am', 'father_name_am', 'grandfather_name_am', 'first_name_en', 'father_name_en', 'grandfather_name_en'] as $field) {
                    if ($values[$field] !== null) {
                        $errors[$field][] = 'Individual name fields cannot be supplied for a legal entity.';
                    }
                }
            }

            $isValid = $errors === [];
            if ($isValid) {
                $validCount++;
            }

            $identity = array_filter(
                array_intersect_key($values, array_flip(self::ALLOWED_FIELDS)),
                static fn (mixed $value): bool => $value !== null,
            );

            $previewRows[] = [
                'row_number' => $rowNumber,
                'valid' => $isValid,
                'source_reference' => $reference,
                'identity' => $identity,
                'errors' => $errors,
            ];
        }

        $total = count($previewRows);
        $invalidCount = $total - $validCount;

        return [
            'mode' => 'preview_only',
            'writes_performed' => false,
            'can_proceed_to_manual_review' => $total > 0 && $invalidCount === 0,
            'summary' => [
                'total' => $total,
                'valid' => $validCount,
                'invalid' => $invalidCount,
            ],
            'rows' => $previewRows,
        ];
    }
}
