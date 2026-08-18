<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Small rule-string validator: 'required|email|max:255'.
 */
class Validator
{
    private array $errors = [];
    private array $validated = [];

    public function __construct(private array $data, private array $rules, private array $messages = [])
    {
    }

    public static function make(array $data, array $rules, array $messages = []): self
    {
        return new self($data, $rules, $messages);
    }

    public function passes(): bool
    {
        $this->errors = [];
        $this->validated = [];

        foreach ($this->rules as $field => $ruleString) {
            $rules = is_array($ruleString) ? $ruleString : explode('|', $ruleString);
            $value = $this->data[$field] ?? null;
            $value = is_string($value) ? trim($value) : $value;

            $nullable = in_array('nullable', $rules, true);
            $isEmpty = $value === null || $value === '' || $value === [];

            if ($nullable && $isEmpty) {
                $this->validated[$field] = null;
                continue;
            }

            foreach ($rules as $rule) {
                if ($rule === 'nullable' || $rule === '') {
                    continue;
                }

                [$name, $parameter] = array_pad(explode(':', $rule, 2), 2, null);
                $this->applyRule($field, $name, $parameter, $value);

                if (isset($this->errors[$field])) {
                    break;
                }
            }

            if (!isset($this->errors[$field])) {
                $this->validated[$field] = $value;
            }
        }

        return $this->errors === [];
    }

    public function fails(): bool
    {
        return !$this->passes();
    }

    public function errors(): array
    {
        return $this->errors;
    }

    public function firstError(): ?string
    {
        foreach ($this->errors as $messages) {
            return is_array($messages) ? (string) reset($messages) : (string) $messages;
        }

        return null;
    }

    public function validated(): array
    {
        return $this->validated;
    }

    private function applyRule(string $field, string $rule, ?string $parameter, mixed $value): void
    {
        $label = $this->label($field);

        switch ($rule) {
            case 'required':
                if ($value === null || $value === '' || $value === []) {
                    $this->addError($field, $rule, $label . ' is required.');
                }
                break;

            case 'email':
                if (!is_string($value) || filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
                    $this->addError($field, $rule, 'Please enter a valid email address.');
                }
                break;

            case 'url':
                if (!is_string($value) || filter_var($value, FILTER_VALIDATE_URL) === false) {
                    $this->addError($field, $rule, $label . ' must be a valid URL.');
                }
                break;

            case 'numeric':
                if (!is_numeric($value)) {
                    $this->addError($field, $rule, $label . ' must be a number.');
                }
                break;

            case 'integer':
                if (filter_var($value, FILTER_VALIDATE_INT) === false) {
                    $this->addError($field, $rule, $label . ' must be a whole number.');
                }
                break;

            case 'min':
                $min = (float) $parameter;
                if (is_numeric($value)) {
                    if ((float) $value < $min) {
                        $this->addError($field, $rule, $label . ' must be at least ' . $parameter . '.');
                    }
                } elseif (mb_strlen((string) $value) < (int) $parameter) {
                    $this->addError($field, $rule, $label . ' must be at least ' . $parameter . ' characters.');
                }
                break;

            case 'max':
                if (is_numeric($value) && !is_string($value)) {
                    if ((float) $value > (float) $parameter) {
                        $this->addError($field, $rule, $label . ' may not be greater than ' . $parameter . '.');
                    }
                } elseif (mb_strlen((string) $value) > (int) $parameter) {
                    $this->addError($field, $rule, $label . ' may not be longer than ' . $parameter . ' characters.');
                }
                break;

            case 'between':
                [$low, $high] = array_pad(explode(',', (string) $parameter), 2, '0');
                if (!is_numeric($value) || (float) $value < (float) $low || (float) $value > (float) $high) {
                    $this->addError($field, $rule, $label . ' must be between ' . $low . ' and ' . $high . '.');
                }
                break;

            case 'in':
                $allowed = explode(',', (string) $parameter);
                if (!in_array((string) $value, $allowed, true)) {
                    $this->addError($field, $rule, $label . ' is invalid.');
                }
                break;

            case 'confirmed':
                if (($this->data[$field . '_confirmation'] ?? null) !== $value) {
                    $this->addError($field, $rule, $label . ' confirmation does not match.');
                }
                break;

            case 'same':
                if (($this->data[(string) $parameter] ?? null) !== $value) {
                    $this->addError($field, $rule, $label . ' must match ' . $this->label((string) $parameter) . '.');
                }
                break;

            case 'date':
                if (!is_string($value) || strtotime($value) === false) {
                    $this->addError($field, $rule, $label . ' must be a valid date.');
                }
                break;

            case 'boolean':
                if (!in_array($value, [true, false, 0, 1, '0', '1', 'on', 'off'], true)) {
                    $this->addError($field, $rule, $label . ' must be true or false.');
                }
                break;

            case 'alpha_num':
                if (!is_string($value) || preg_match('/^[a-zA-Z0-9]+$/', $value) !== 1) {
                    $this->addError($field, $rule, $label . ' may only contain letters and numbers.');
                }
                break;

            case 'regex':
                if (!is_string($value) || preg_match((string) $parameter, $value) !== 1) {
                    $this->addError($field, $rule, $label . ' format is invalid.');
                }
                break;

            case 'unique':
                // unique:table,column[,ignore_id[,id_column]]
                $parts = explode(',', (string) $parameter);
                $table = $parts[0] ?? '';
                $column = $parts[1] ?? $field;
                $ignore = $parts[2] ?? null;
                $idColumn = $parts[3] ?? 'id';

                if ($table === '') {
                    break;
                }

                $sql = sprintf('SELECT COUNT(*) FROM `%s` WHERE `%s` = :value', $table, $column);
                $params = ['value' => $value];

                if ($ignore !== null && $ignore !== '') {
                    $sql .= sprintf(' AND `%s` <> :ignore', $idColumn);
                    $params['ignore'] = (int) $ignore;
                }

                if ((int) Database::scalar($sql, $params) > 0) {
                    $this->addError($field, $rule, $label . ' is already taken.');
                }
                break;

            case 'exists':
                $parts = explode(',', (string) $parameter);
                $table = $parts[0] ?? '';
                $column = $parts[1] ?? 'id';

                if ($table === '') {
                    break;
                }

                $count = (int) Database::scalar(
                    sprintf('SELECT COUNT(*) FROM `%s` WHERE `%s` = :value', $table, $column),
                    ['value' => $value]
                );

                if ($count === 0) {
                    $this->addError($field, $rule, 'The selected ' . strtolower($label) . ' is invalid.');
                }
                break;

            case 'password':
                if (!is_string($value) || mb_strlen($value) < 8) {
                    $this->addError($field, $rule, 'Password must be at least 8 characters.');
                } elseif (preg_match('/[A-Za-z]/', $value) !== 1 || preg_match('/\d/', $value) !== 1) {
                    $this->addError($field, $rule, 'Password must contain at least one letter and one number.');
                }
                break;
        }
    }

    private function addError(string $field, string $rule, string $message): void
    {
        $this->errors[$field][] = $this->messages[$field . '.' . $rule] ?? $this->messages[$field] ?? $message;
    }

    private function label(string $field): string
    {
        return ucfirst(str_replace(['_', '-'], ' ', $field));
    }
}
