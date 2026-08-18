<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Core\Database;
use App\Core\HttpException;
use App\Core\Settings;

/**
 * The configurable form engine behind both builders.
 *
 * The same code drives the customer visit form (TYPE A, filled on Android) and
 * the BC Supervisor inspection form (TYPE B, filled on the web), because the two
 * differ only in which tables they live in. That is what lets the final
 * inspection questionnaire be changed from the admin panel later without
 * touching application code.
 */
final class Forms
{
    public const KIND_VISIT = 'visit';
    public const KIND_INSPECTION = 'inspection';

    /** @return array<string, string> field type => label */
    public static function fieldTypes(): array
    {
        return [
            'section' => 'Section heading',
            'text' => 'Text',
            'textarea' => 'Long text',
            'number' => 'Number',
            'decimal' => 'Amount / decimal',
            'date' => 'Date',
            'time' => 'Time',
            'dropdown' => 'Dropdown',
            'radio' => 'Radio choice',
            'checkbox' => 'Checkboxes (multiple)',
            'yes_no' => 'Yes / No',
            'photo' => 'Photograph',
            'signature' => 'Signature',
            'gps' => 'GPS location',
            'remarks' => 'Remarks',
        ];
    }

    /** Types whose value is captured by the platform rather than typed in. */
    public static function isCapturedType(string $type): bool
    {
        return in_array($type, ['photo', 'signature', 'gps', 'section'], true);
    }

    public static function needsOptions(string $type): bool
    {
        return in_array($type, ['dropdown', 'radio', 'checkbox'], true);
    }

    /* ------------------------------------------------------------------ */
    /* Table plumbing                                                     */
    /* ------------------------------------------------------------------ */

    /** @return array{forms:string, fields:string, values:string, owner:string} */
    public static function tables(string $kind): array
    {
        return $kind === self::KIND_INSPECTION
            ? [
                'forms' => 'inspection_forms',
                'fields' => 'inspection_form_fields',
                'values' => 'inspection_form_values',
                'owner' => 'inspection_id',
            ]
            : [
                'forms' => 'visit_forms',
                'fields' => 'visit_form_fields',
                'values' => 'visit_form_values',
                'owner' => 'visit_id',
            ];
    }

    /* ------------------------------------------------------------------ */
    /* Reading forms                                                      */
    /* ------------------------------------------------------------------ */

    /** @return array<int, array<string, mixed>> */
    public static function forms(string $kind, bool $activeOnly = false): array
    {
        $tables = self::tables($kind);
        $sql = sprintf('SELECT * FROM `%s`', $tables['forms']);

        if ($activeOnly) {
            $sql .= ' WHERE is_active = 1';
        }

        return Database::select($sql . ' ORDER BY is_default DESC, name ASC');
    }

    public static function form(string $kind, int $formId): array
    {
        $tables = self::tables($kind);
        $row = Database::selectOne(
            sprintf('SELECT * FROM `%s` WHERE id = :id', $tables['forms']),
            ['id' => $formId]
        );

        if ($row === null) {
            throw new HttpException(404, 'Form not found.');
        }

        return $row;
    }

    /**
     * The form a new visit or inspection should use.
     */
    public static function defaultForm(string $kind, string $visitType = 'customer'): ?array
    {
        $tables = self::tables($kind);

        if ($kind === self::KIND_INSPECTION) {
            $configured = Settings::int('default_inspection_form_id', 0);

            if ($configured > 0) {
                $row = Database::selectOne(
                    sprintf('SELECT * FROM `%s` WHERE id = :id AND is_active = 1', $tables['forms']),
                    ['id' => $configured]
                );

                if ($row !== null) {
                    return $row;
                }
            }

            return Database::selectOne(
                sprintf('SELECT * FROM `%s` WHERE is_active = 1 ORDER BY is_default DESC, id ASC LIMIT 1', $tables['forms'])
            );
        }

        return Database::selectOne(
            sprintf(
                'SELECT * FROM `%s` WHERE is_active = 1 AND visit_type = :type ORDER BY is_default DESC, id ASC LIMIT 1',
                $tables['forms']
            ),
            ['type' => $visitType]
        ) ?? Database::selectOne(
            sprintf('SELECT * FROM `%s` WHERE is_active = 1 ORDER BY is_default DESC, id ASC LIMIT 1', $tables['forms'])
        );
    }

    /**
     * Active fields of a form in display order.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function fields(string $kind, int $formId, bool $activeOnly = true): array
    {
        $tables = self::tables($kind);
        $sql = sprintf('SELECT * FROM `%s` WHERE form_id = :form', $tables['fields']);

        if ($activeOnly) {
            $sql .= ' AND is_active = 1';
        }

        $fields = Database::select($sql . ' ORDER BY sort_order ASC, id ASC', ['form' => $formId]);

        foreach ($fields as $index => $field) {
            $fields[$index]['option_list'] = self::optionList($field['options'] ?? null);
        }

        return $fields;
    }

    /** @return array<int, string> */
    public static function optionList(?string $options): array
    {
        if ($options === null || trim($options) === '') {
            return [];
        }

        $items = preg_split('/\r\n|\r|\n/', $options) ?: [];
        $items = array_map('trim', $items);

        return array_values(array_filter($items, static fn (string $item): bool => $item !== ''));
    }

    /**
     * A field's condition expressed against another field's submitted value.
     *
     * @param array<string, mixed>  $field
     * @param array<string, string> $values field_key => submitted value
     */
    public static function isVisible(array $field, array $values, array $fieldsById): bool
    {
        $parentId = $field['condition_field_id'] ?? null;

        if ($parentId === null) {
            return true;
        }

        $parent = $fieldsById[(int) $parentId] ?? null;

        if ($parent === null) {
            return true;
        }

        $parentValue = trim((string) ($values[$parent['field_key']] ?? ''));
        $expected = trim((string) ($field['condition_value'] ?? ''));

        return match ((string) $field['condition_operator']) {
            'equals' => strcasecmp($parentValue, $expected) === 0,
            'not_equals' => strcasecmp($parentValue, $expected) !== 0,
            'in' => in_array(
                strtolower($parentValue),
                array_map('strtolower', array_map('trim', explode(',', $expected))),
                true
            ),
            'filled' => $parentValue !== '',
            'empty' => $parentValue === '',
            default => true,
        };
    }

    /* ------------------------------------------------------------------ */
    /* Validating and storing submitted values                            */
    /* ------------------------------------------------------------------ */

    /**
     * Validate submitted values against the form definition.
     *
     * Conditional fields that are not visible are neither required nor stored,
     * which is what makes "Promise amount only when the customer was met" work
     * identically on the web and in the app.
     *
     * @param array<int, array<string, mixed>> $fields
     * @param array<string, mixed>             $input field_key => value
     * @return array{values: array<string, string>, errors: array<string, string>}
     */
    public static function validate(array $fields, array $input): array
    {
        $fieldsById = [];

        foreach ($fields as $field) {
            $fieldsById[(int) $field['id']] = $field;
        }

        // Normalise first so conditions can be evaluated against raw input.
        $normalised = [];

        foreach ($fields as $field) {
            $key = (string) $field['field_key'];
            $raw = $input[$key] ?? null;

            if (is_array($raw)) {
                // Checkbox groups arrive as arrays.
                $raw = implode(', ', array_map('strval', $raw));
            }

            $normalised[$key] = $raw === null ? '' : trim((string) $raw);
        }

        $values = [];
        $errors = [];

        foreach ($fields as $field) {
            $key = (string) $field['field_key'];
            $type = (string) $field['field_type'];
            $label = (string) $field['label'];
            $value = $normalised[$key];

            if ($type === 'section') {
                continue;
            }

            if (!self::isVisible($field, $normalised, $fieldsById)) {
                continue;
            }

            $required = (int) $field['is_required'] === 1;

            // Photo, signature and GPS values are recorded by their own tables;
            // the form value only records that they were captured.
            if (self::isCapturedType($type)) {
                if ($value !== '') {
                    $values[$key] = $value;
                }

                continue;
            }

            if ($value === '') {
                if ($required) {
                    $errors[$key] = $label . ' is required.';
                }

                continue;
            }

            switch ($type) {
                case 'number':
                    if (!preg_match('/^-?\d+$/', $value)) {
                        $errors[$key] = $label . ' must be a whole number.';
                        break;
                    }

                    $values[$key] = $value;
                    break;

                case 'decimal':
                    if (!is_numeric(str_replace(',', '', $value))) {
                        $errors[$key] = $label . ' must be a number.';
                        break;
                    }

                    $number = (float) str_replace(',', '', $value);

                    if ($field['min_value'] !== null && $number < (float) $field['min_value']) {
                        $errors[$key] = sprintf('%s must be at least %s.', $label, money((float) $field['min_value']));
                        break;
                    }

                    if ($field['max_value'] !== null && $number > (float) $field['max_value']) {
                        $errors[$key] = sprintf('%s may not exceed %s.', $label, money((float) $field['max_value']));
                        break;
                    }

                    $values[$key] = (string) $number;
                    break;

                case 'date':
                    $timestamp = strtotime($value);

                    if ($timestamp === false) {
                        $errors[$key] = $label . ' must be a valid date.';
                        break;
                    }

                    $values[$key] = date('Y-m-d', $timestamp);
                    break;

                case 'time':
                    if (preg_match('/^([01]?\d|2[0-3]):[0-5]\d$/', $value) !== 1) {
                        $errors[$key] = $label . ' must be a valid time (HH:MM).';
                        break;
                    }

                    $values[$key] = $value;
                    break;

                case 'yes_no':
                    $answer = strtolower($value);

                    if (!in_array($answer, ['yes', 'no', '1', '0', 'true', 'false'], true)) {
                        $errors[$key] = $label . ' must be Yes or No.';
                        break;
                    }

                    $values[$key] = in_array($answer, ['yes', '1', 'true'], true) ? 'Yes' : 'No';
                    break;

                case 'dropdown':
                case 'radio':
                    $options = self::optionList($field['options'] ?? null);

                    if ($options !== [] && !in_array($value, $options, true)) {
                        $errors[$key] = $label . ': "' . str_excerpt($value, 40) . '" is not one of the configured choices.';
                        break;
                    }

                    $values[$key] = $value;
                    break;

                case 'checkbox':
                    $options = self::optionList($field['options'] ?? null);
                    $chosen = array_map('trim', explode(',', $value));
                    $chosen = array_values(array_filter($chosen, static fn (string $v): bool => $v !== ''));

                    if ($options !== []) {
                        foreach ($chosen as $choice) {
                            if (!in_array($choice, $options, true)) {
                                $errors[$key] = $label . ': "' . str_excerpt($choice, 40) . '" is not one of the configured choices.';
                                break 2;
                            }
                        }
                    }

                    $values[$key] = implode(', ', $chosen);
                    break;

                default: // text, textarea, remarks
                    $maxLength = $field['max_length'] === null ? 5000 : (int) $field['max_length'];

                    if (mb_strlen($value) > $maxLength) {
                        $errors[$key] = sprintf('%s may not be longer than %d characters.', $label, $maxLength);
                        break;
                    }

                    $values[$key] = $value;
            }
        }

        return ['values' => $values, 'errors' => $errors];
    }

    /**
     * Persist submitted values, replacing anything previously stored.
     *
     * @param array<int, array<string, mixed>> $fields
     * @param array<string, string>            $values
     */
    public static function saveValues(string $kind, int $ownerId, array $fields, array $values): int
    {
        $tables = self::tables($kind);
        $byKey = [];

        foreach ($fields as $field) {
            $byKey[(string) $field['field_key']] = $field;
        }

        $saved = 0;

        foreach ($values as $key => $value) {
            $field = $byKey[$key] ?? null;

            Database::statement(
                sprintf(
                    'INSERT INTO `%s` (`%s`, field_id, field_key, label, field_type, value, created_at, updated_at)
                     VALUES (:owner, :field_id, :field_key, :label, :field_type, :value, :now, :now)
                     ON DUPLICATE KEY UPDATE value = :value, label = :label, field_type = :field_type, updated_at = :now',
                    $tables['values'],
                    $tables['owner']
                ),
                [
                    'owner' => $ownerId,
                    'field_id' => $field === null ? null : (int) $field['id'],
                    'field_key' => mb_substr($key, 0, 80),
                    'label' => $field === null ? null : mb_substr((string) $field['label'], 0, 255),
                    'field_type' => $field === null ? null : (string) $field['field_type'],
                    'value' => $value,
                    'now' => now(),
                ]
            );

            $saved++;
        }

        return $saved;
    }

    /**
     * Stored values for a record, in form order, ready for display.
     *
     * @return array<int, array{label:string, field_key:string, field_type:string, value:string}>
     */
    public static function values(string $kind, int $ownerId): array
    {
        $tables = self::tables($kind);

        return Database::select(
            sprintf(
                'SELECT v.field_key, v.label, v.field_type, v.value
                   FROM `%s` v
              LEFT JOIN `%s` f ON f.id = v.field_id
                  WHERE v.`%s` = :owner
                  ORDER BY COALESCE(f.sort_order, 9999) ASC, v.id ASC',
                $tables['values'],
                $tables['fields'],
                $tables['owner']
            ),
            ['owner' => $ownerId]
        );
    }

    /* ------------------------------------------------------------------ */
    /* Builder mutations                                                  */
    /* ------------------------------------------------------------------ */

    /**
     * @param array<string, mixed> $data
     */
    public static function createForm(string $kind, array $data): int
    {
        $tables = self::tables($kind);

        $payload = [
            'name' => mb_substr(trim((string) $data['name']), 0, 160),
            'description' => isset($data['description']) && $data['description'] !== ''
                ? mb_substr((string) $data['description'], 0, 255)
                : null,
            'version' => 1,
            'is_active' => !empty($data['is_active']) ? 1 : 0,
            'is_default' => !empty($data['is_default']) ? 1 : 0,
            'created_by' => Auth::id(),
            'created_at' => now(),
            'updated_at' => now(),
        ];

        if ($kind === self::KIND_VISIT) {
            $payload['visit_type'] = in_array((string) ($data['visit_type'] ?? 'customer'), ['customer', 'krm_ots', 'ckcc_od2'], true)
                ? (string) $data['visit_type']
                : 'customer';
        }

        $id = Database::insert($tables['forms'], $payload);

        if ($payload['is_default'] === 1) {
            self::makeDefault($kind, $id);
        }

        Audit::log(Audit::FORM_CREATED, [
            'entity_type' => $tables['forms'],
            'entity_id' => $id,
            'description' => sprintf('%s form "%s" created.', ucfirst($kind), $payload['name']),
            'new' => $payload,
        ]);

        return $id;
    }

    public static function updateForm(string $kind, int $formId, array $data): void
    {
        $tables = self::tables($kind);
        $before = self::form($kind, $formId);

        $payload = [
            'name' => mb_substr(trim((string) $data['name']), 0, 160),
            'description' => isset($data['description']) && $data['description'] !== ''
                ? mb_substr((string) $data['description'], 0, 255)
                : null,
            'is_active' => !empty($data['is_active']) ? 1 : 0,
            'is_default' => !empty($data['is_default']) ? 1 : 0,
            'updated_at' => now(),
        ];

        if ($kind === self::KIND_VISIT && isset($data['visit_type'])) {
            $payload['visit_type'] = in_array((string) $data['visit_type'], ['customer', 'krm_ots', 'ckcc_od2'], true)
                ? (string) $data['visit_type']
                : 'customer';
        }

        Database::update($tables['forms'], $payload, 'id = :id', ['id' => $formId]);

        if ($payload['is_default'] === 1) {
            self::makeDefault($kind, $formId);
        }

        Audit::logChange(
            Audit::FORM_UPDATED,
            $tables['forms'],
            $formId,
            $before,
            $payload,
            sprintf('%s form "%s" updated.', ucfirst($kind), $payload['name'])
        );
    }

    private static function makeDefault(string $kind, int $formId): void
    {
        $tables = self::tables($kind);

        if ($kind === self::KIND_VISIT) {
            $visitType = (string) Database::scalar(
                sprintf('SELECT visit_type FROM `%s` WHERE id = :id', $tables['forms']),
                ['id' => $formId]
            );

            Database::update(
                $tables['forms'],
                ['is_default' => 0, 'updated_at' => now()],
                'id <> :id AND visit_type = :type',
                ['id' => $formId, 'type' => $visitType]
            );
        } else {
            Database::update($tables['forms'], ['is_default' => 0, 'updated_at' => now()], 'id <> :id', ['id' => $formId]);
            Settings::set('default_inspection_form_id', (string) $formId, 'forms');
        }

        Database::update($tables['forms'], ['is_default' => 1, 'is_active' => 1, 'updated_at' => now()], 'id = :id', ['id' => $formId]);
    }

    /**
     * Create or update a single field.
     *
     * @param array<string, mixed> $data
     */
    public static function saveField(string $kind, int $formId, array $data, ?int $fieldId = null): int
    {
        $tables = self::tables($kind);
        $type = (string) ($data['field_type'] ?? 'text');

        if (!array_key_exists($type, self::fieldTypes())) {
            throw new HttpException(422, 'Unknown field type.');
        }

        $label = trim((string) ($data['label'] ?? ''));

        if ($label === '') {
            throw new HttpException(422, 'A field label is required.');
        }

        $key = trim((string) ($data['field_key'] ?? ''));
        $key = $key !== '' ? $key : self::keyFromLabel($label);
        $key = mb_substr(preg_replace('/[^a-z0-9_]/', '', strtolower($key)) ?? '', 0, 80);

        if ($key === '') {
            $key = 'field_' . str_random(6);
        }

        // Keys must be unique within a form: values are stored against them.
        $clash = Database::selectOne(
            sprintf('SELECT id FROM `%s` WHERE form_id = :form AND field_key = :key AND id <> :id', $tables['fields']),
            ['form' => $formId, 'key' => $key, 'id' => $fieldId ?? 0]
        );

        if ($clash !== null) {
            $key .= '_' . str_random(4);
        }

        $options = null;

        if (self::needsOptions($type)) {
            $options = trim((string) ($data['options'] ?? ''));

            if (self::optionList($options) === []) {
                throw new HttpException(422, 'Add at least one choice for a dropdown, radio or checkbox field.');
            }
        }

        $conditionFieldId = isset($data['condition_field_id']) && $data['condition_field_id'] !== ''
            ? (int) $data['condition_field_id']
            : null;

        if ($conditionFieldId !== null && $conditionFieldId === $fieldId) {
            throw new HttpException(422, 'A field cannot depend on itself.');
        }

        $payload = [
            'form_id' => $formId,
            'field_key' => $key,
            'label' => mb_substr($label, 0, 255),
            'field_type' => $type,
            'options' => $options,
            'placeholder' => isset($data['placeholder']) && $data['placeholder'] !== ''
                ? mb_substr((string) $data['placeholder'], 0, 160)
                : null,
            'help_text' => isset($data['help_text']) && $data['help_text'] !== ''
                ? mb_substr((string) $data['help_text'], 0, 255)
                : null,
            'is_required' => !empty($data['is_required']) ? 1 : 0,
            'min_value' => isset($data['min_value']) && $data['min_value'] !== '' ? (float) $data['min_value'] : null,
            'max_value' => isset($data['max_value']) && $data['max_value'] !== '' ? (float) $data['max_value'] : null,
            'max_length' => isset($data['max_length']) && $data['max_length'] !== '' ? (int) $data['max_length'] : null,
            'sort_order' => isset($data['sort_order']) && $data['sort_order'] !== ''
                ? (int) $data['sort_order']
                : self::nextSortOrder($kind, $formId),
            'is_active' => array_key_exists('is_active', $data) ? (!empty($data['is_active']) ? 1 : 0) : 1,
            'condition_field_id' => $conditionFieldId,
            'condition_operator' => $conditionFieldId === null
                ? null
                : (in_array((string) ($data['condition_operator'] ?? 'equals'), ['equals', 'not_equals', 'in', 'filled', 'empty'], true)
                    ? (string) $data['condition_operator']
                    : 'equals'),
            'condition_value' => $conditionFieldId === null || !isset($data['condition_value']) || $data['condition_value'] === ''
                ? null
                : mb_substr((string) $data['condition_value'], 0, 255),
            'updated_at' => now(),
        ];

        if ($fieldId === null) {
            $payload['created_at'] = now();
            $fieldId = Database::insert($tables['fields'], $payload);
        } else {
            Database::update($tables['fields'], $payload, 'id = :id AND form_id = :form', ['id' => $fieldId, 'form' => $formId]);
        }

        Audit::log(Audit::FORM_FIELD_SAVED, [
            'entity_type' => $tables['fields'],
            'entity_id' => $fieldId,
            'description' => sprintf('Field "%s" saved on %s form #%d.', $label, $kind, $formId),
            'new' => $payload,
        ]);

        return $fieldId;
    }

    public static function deleteField(string $kind, int $formId, int $fieldId): void
    {
        $tables = self::tables($kind);

        $field = Database::selectOne(
            sprintf('SELECT * FROM `%s` WHERE id = :id AND form_id = :form', $tables['fields']),
            ['id' => $fieldId, 'form' => $formId]
        );

        if ($field === null) {
            throw new HttpException(404, 'Field not found.');
        }

        $used = (int) Database::scalar(
            sprintf('SELECT COUNT(*) FROM `%s` WHERE field_id = :id', $tables['values']),
            ['id' => $fieldId]
        );

        if ($used > 0) {
            // Historic answers must stay readable, so a used field is retired
            // rather than deleted.
            Database::update($tables['fields'], ['is_active' => 0, 'updated_at' => now()], 'id = :id', ['id' => $fieldId]);

            Audit::log(Audit::FORM_FIELD_DELETED, [
                'entity_type' => $tables['fields'],
                'entity_id' => $fieldId,
                'description' => sprintf(
                    'Field "%s" deactivated (kept because %d submitted answers reference it).',
                    $field['label'],
                    $used
                ),
            ]);

            return;
        }

        Database::delete($tables['fields'], 'id = :id', ['id' => $fieldId]);

        Audit::log(Audit::FORM_FIELD_DELETED, [
            'entity_type' => $tables['fields'],
            'entity_id' => $fieldId,
            'description' => sprintf('Field "%s" deleted from %s form #%d.', $field['label'], $kind, $formId),
            'old' => $field,
        ]);
    }

    /**
     * @param array<int, int> $orderedFieldIds
     */
    public static function reorder(string $kind, int $formId, array $orderedFieldIds): void
    {
        $tables = self::tables($kind);
        $order = 0;

        foreach ($orderedFieldIds as $fieldId) {
            $order += 10;
            Database::update(
                $tables['fields'],
                ['sort_order' => $order, 'updated_at' => now()],
                'id = :id AND form_id = :form',
                ['id' => (int) $fieldId, 'form' => $formId]
            );
        }
    }

    private static function nextSortOrder(string $kind, int $formId): int
    {
        $tables = self::tables($kind);

        return ((int) Database::scalar(
            sprintf('SELECT COALESCE(MAX(sort_order), 0) FROM `%s` WHERE form_id = :form', $tables['fields']),
            ['form' => $formId]
        )) + 10;
    }

    public static function keyFromLabel(string $label): string
    {
        $key = strtolower(trim($label));
        $key = preg_replace('/[^a-z0-9]+/', '_', $key) ?? $key;

        return trim($key, '_');
    }

    /**
     * Duplicate a form and its fields — the safe way to revise a live form.
     */
    public static function duplicate(string $kind, int $formId): int
    {
        $tables = self::tables($kind);
        $form = self::form($kind, $formId);

        $payload = $form;
        unset($payload['id']);
        $payload['name'] = mb_substr($form['name'] . ' (copy)', 0, 160);
        $payload['is_default'] = 0;
        $payload['is_active'] = 0;
        $payload['version'] = (int) $form['version'] + 1;
        $payload['created_by'] = Auth::id();
        $payload['created_at'] = now();
        $payload['updated_at'] = now();

        $newFormId = Database::insert($tables['forms'], $payload);

        // Copy fields, then remap conditional references onto the new field ids.
        $map = [];

        foreach (self::fields($kind, $formId, false) as $field) {
            $original = (int) $field['id'];
            unset($field['id'], $field['option_list']);
            $field['form_id'] = $newFormId;
            $field['condition_field_id'] = null;
            $field['created_at'] = now();
            $field['updated_at'] = now();

            $map[$original] = ['new_id' => Database::insert($tables['fields'], $field), 'condition' => null];
        }

        foreach (self::fields($kind, $formId, false) as $field) {
            if ($field['condition_field_id'] === null) {
                continue;
            }

            $parent = $map[(int) $field['condition_field_id']]['new_id'] ?? null;
            $child = $map[(int) $field['id']]['new_id'] ?? null;

            if ($parent === null || $child === null) {
                continue;
            }

            Database::update(
                $tables['fields'],
                ['condition_field_id' => $parent, 'updated_at' => now()],
                'id = :id',
                ['id' => $child]
            );
        }

        Audit::log(Audit::FORM_CREATED, [
            'entity_type' => $tables['forms'],
            'entity_id' => $newFormId,
            'description' => sprintf('%s form "%s" duplicated from #%d.', ucfirst($kind), $payload['name'], $formId),
        ]);

        return $newFormId;
    }

    /**
     * Compact definition for the Android app so it can render the visit form
     * offline and validate before queueing a submission.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function definitionForApp(string $kind, int $formId): array
    {
        $fields = self::fields($kind, $formId);
        $byId = [];

        foreach ($fields as $field) {
            $byId[(int) $field['id']] = (string) $field['field_key'];
        }

        $definition = [];

        foreach ($fields as $field) {
            $definition[] = [
                'key' => (string) $field['field_key'],
                'label' => (string) $field['label'],
                'type' => (string) $field['field_type'],
                'required' => (int) $field['is_required'] === 1,
                'options' => $field['option_list'],
                'placeholder' => $field['placeholder'],
                'help' => $field['help_text'],
                'min' => $field['min_value'] === null ? null : (float) $field['min_value'],
                'max' => $field['max_value'] === null ? null : (float) $field['max_value'],
                'order' => (int) $field['sort_order'],
                'condition' => $field['condition_field_id'] === null ? null : [
                    'field' => $byId[(int) $field['condition_field_id']] ?? null,
                    'operator' => (string) $field['condition_operator'],
                    'value' => $field['condition_value'],
                ],
            ];
        }

        return $definition;
    }
}
