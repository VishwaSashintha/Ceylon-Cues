<?php

declare(strict_types=1);

namespace CeylonCues\Core;

/**
 * Server-Side Validator
 *
 * Validates request data against a set of rules and returns structured errors.
 * Supports a comprehensive rule set suitable for e-commerce/ERP operations.
 *
 * Usage:
 *   $result = Validator::validate($request->getJson(), [
 *       'name'  => 'required|string|max:255',
 *       'price' => 'required|decimal|min:0',
 *       'email' => 'required|email|unique:users,email',
 *   ]);
 *
 *   if (!$result['valid']) {
 *       Response::error('Validation failed', 422, $result['errors']);
 *   }
 *
 * @package CeylonCues\Core
 */
final class Validator
{
    /** @var array<string, string[]> Accumulated validation errors */
    private array $errors = [];

    /** @var array<string, mixed> The data being validated */
    private array $data;

    /**
     * Validate data against the given rules.
     *
     * @param array<string, mixed>  $data  Input data to validate
     * @param array<string, string> $rules Validation rules (pipe-delimited)
     * @return array{valid: bool, errors: array<string, string[]>}
     *
     * @example
     *   Validator::validate($data, [
     *       'name'     => 'required|string|min:2|max:255',
     *       'email'    => 'required|email',
     *       'price'    => 'required|decimal|min:0',
     *       'category' => 'required|in:cues,accessories,cases',
     *       'status'   => 'nullable|boolean',
     *       'image'    => 'file|image|max_size:5120',
     *   ]);
     */
    public static function validate(array $data, array $rules): array
    {
        $validator = new self($data);

        foreach ($rules as $field => $ruleString) {
            $fieldRules = explode('|', $ruleString);
            $validator->validateField($field, $fieldRules);
        }

        return [
            'valid'  => empty($validator->errors),
            'errors' => $validator->errors,
        ];
    }

    /**
     * Private constructor.
     *
     * @param array<string, mixed> $data Data to validate
     */
    private function __construct(array $data)
    {
        $this->data = $data;
    }

    /**
     * Validate a single field against its rules.
     *
     * @param string   $field Field name
     * @param string[] $rules Array of rule strings (e.g., ['required', 'string', 'max:255'])
     * @return void
     */
    private function validateField(string $field, array $rules): void
    {
        $value = $this->data[$field] ?? null;
        $isNullable = in_array('nullable', $rules, true);

        // If nullable and value is null/empty, skip all other rules
        if ($isNullable && ($value === null || $value === '')) {
            return;
        }

        foreach ($rules as $rule) {
            if ($rule === 'nullable') {
                continue;
            }

            $params = [];
            if (str_contains($rule, ':')) {
                [$rule, $paramStr] = explode(':', $rule, 2);
                $params = explode(',', $paramStr);
            }

            $methodName = 'rule' . str_replace('_', '', ucwords($rule, '_'));

            if (method_exists($this, $methodName)) {
                $this->$methodName($field, $value, $params);
            }
        }
    }

    /**
     * Add a validation error for a field.
     *
     * @param string $field   Field name
     * @param string $message Error message
     * @return void
     */
    private function addError(string $field, string $message): void
    {
        $this->errors[$field][] = $message;
    }

    /**
     * Get a human-readable label from the field name.
     *
     * @param string $field
     * @return string
     */
    private function label(string $field): string
    {
        return str_replace('_', ' ', $field);
    }

    // -------------------------------------------------------------------------
    // Validation Rules
    // -------------------------------------------------------------------------

    /**
     * The field must be present and not empty.
     */
    private function ruleRequired(string $field, mixed $value, array $params): void
    {
        if ($value === null || $value === '' || (is_array($value) && empty($value))) {
            $this->addError($field, "The {$this->label($field)} field is required.");
        }
    }

    /**
     * The field must be a string.
     */
    private function ruleString(string $field, mixed $value, array $params): void
    {
        if ($value !== null && !is_string($value)) {
            $this->addError($field, "The {$this->label($field)} must be a string.");
        }
    }

    /**
     * The field must be an integer.
     */
    private function ruleInteger(string $field, mixed $value, array $params): void
    {
        if ($value !== null && filter_var($value, FILTER_VALIDATE_INT) === false) {
            $this->addError($field, "The {$this->label($field)} must be an integer.");
        }
    }

    /**
     * The field must be a decimal/numeric value.
     */
    private function ruleDecimal(string $field, mixed $value, array $params): void
    {
        if ($value !== null && !is_numeric($value)) {
            $this->addError($field, "The {$this->label($field)} must be a decimal number.");
        }
    }

    /**
     * The field must be a valid email address.
     */
    private function ruleEmail(string $field, mixed $value, array $params): void
    {
        if ($value !== null && filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
            $this->addError($field, "The {$this->label($field)} must be a valid email address.");
        }
    }

    /**
     * Minimum value (numeric) or minimum length (string).
     * Usage: min:3
     */
    private function ruleMin(string $field, mixed $value, array $params): void
    {
        $min = (float) ($params[0] ?? 0);

        if ($value === null) {
            return;
        }

        if (is_numeric($value)) {
            if ((float) $value < $min) {
                $this->addError($field, "The {$this->label($field)} must be at least {$min}.");
            }
        } elseif (is_string($value)) {
            if (mb_strlen($value) < (int) $min) {
                $this->addError($field, "The {$this->label($field)} must be at least {$min} characters.");
            }
        } elseif (is_array($value)) {
            if (count($value) < (int) $min) {
                $this->addError($field, "The {$this->label($field)} must have at least {$min} items.");
            }
        }
    }

    /**
     * Maximum value (numeric) or maximum length (string).
     * Usage: max:255
     */
    private function ruleMax(string $field, mixed $value, array $params): void
    {
        $max = (float) ($params[0] ?? 0);

        if ($value === null) {
            return;
        }

        if (is_numeric($value)) {
            if ((float) $value > $max) {
                $this->addError($field, "The {$this->label($field)} must not exceed {$max}.");
            }
        } elseif (is_string($value)) {
            if (mb_strlen($value) > (int) $max) {
                $this->addError($field, "The {$this->label($field)} must not exceed {$max} characters.");
            }
        } elseif (is_array($value)) {
            if (count($value) > (int) $max) {
                $this->addError($field, "The {$this->label($field)} must not have more than {$max} items.");
            }
        }
    }

    /**
     * The field must be one of the given values.
     * Usage: in:draft,active,archived
     */
    private function ruleIn(string $field, mixed $value, array $params): void
    {
        if ($value !== null && !in_array((string) $value, $params, true)) {
            $allowed = implode(', ', $params);
            $this->addError($field, "The {$this->label($field)} must be one of: {$allowed}.");
        }
    }

    /**
     * The value must be unique in the given database table/column.
     * Usage: unique:table,column  or  unique:table,column,except_id
     */
    private function ruleUnique(string $field, mixed $value, array $params): void
    {
        if ($value === null || count($params) < 2) {
            return;
        }

        $table = $params[0];
        $column = $params[1];
        $exceptId = $params[2] ?? null;

        try {
            $db = Database::getInstance()->getConnection();

            $sql = "SELECT COUNT(*) FROM `{$table}` WHERE `{$column}` = ?";
            $bindings = [$value];

            if ($exceptId !== null) {
                $sql .= " AND `id` != ?";
                $bindings[] = $exceptId;
            }

            $stmt = $db->prepare($sql);
            $stmt->execute($bindings);
            $count = (int) $stmt->fetchColumn();

            if ($count > 0) {
                $this->addError($field, "The {$this->label($field)} has already been taken.");
            }
        } catch (\Throwable $e) {
            Logger::getInstance()->error('Unique validation query failed', [
                'field' => $field,
                'table' => $table,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * The value must exist in the given database table/column.
     * Usage: exists:table,column
     */
    private function ruleExists(string $field, mixed $value, array $params): void
    {
        if ($value === null || count($params) < 2) {
            return;
        }

        $table = $params[0];
        $column = $params[1];

        try {
            $db = Database::getInstance()->getConnection();
            $stmt = $db->prepare("SELECT COUNT(*) FROM `{$table}` WHERE `{$column}` = ?");
            $stmt->execute([$value]);
            $count = (int) $stmt->fetchColumn();

            if ($count === 0) {
                $this->addError($field, "The selected {$this->label($field)} is invalid.");
            }
        } catch (\Throwable $e) {
            Logger::getInstance()->error('Exists validation query failed', [
                'field' => $field,
                'table' => $table,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * The field must be a valid date string.
     * Usage: date  or  date:Y-m-d
     */
    private function ruleDate(string $field, mixed $value, array $params): void
    {
        if ($value === null) {
            return;
        }

        $format = $params[0] ?? 'Y-m-d';
        $date = \DateTimeImmutable::createFromFormat($format, (string) $value);

        if ($date === false || $date->format($format) !== (string) $value) {
            $this->addError($field, "The {$this->label($field)} must be a valid date ({$format}).");
        }
    }

    /**
     * The field must be boolean-like (true, false, 1, 0, "1", "0").
     */
    private function ruleBoolean(string $field, mixed $value, array $params): void
    {
        if ($value === null) {
            return;
        }

        $acceptable = [true, false, 0, 1, '0', '1'];

        if (!in_array($value, $acceptable, true)) {
            $this->addError($field, "The {$this->label($field)} must be true or false.");
        }
    }

    /**
     * The field must be an uploaded file (present in $_FILES).
     */
    private function ruleFile(string $field, mixed $value, array $params): void
    {
        if (!isset($_FILES[$field]) || $_FILES[$field]['error'] === UPLOAD_ERR_NO_FILE) {
            $this->addError($field, "The {$this->label($field)} must be a file.");
        }
    }

    /**
     * The uploaded file must be an image (jpeg, png, gif, webp).
     */
    private function ruleImage(string $field, mixed $value, array $params): void
    {
        if (!isset($_FILES[$field]) || $_FILES[$field]['error'] !== UPLOAD_ERR_OK) {
            return;
        }

        $allowedMimes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($_FILES[$field]['tmp_name']);

        if (!in_array($mime, $allowedMimes, true)) {
            $this->addError($field, "The {$this->label($field)} must be an image (jpeg, png, gif, webp).");
        }
    }

    /**
     * The uploaded file must not exceed the given size in kilobytes.
     * Usage: max_size:5120  (5 MB)
     */
    private function ruleMaxsize(string $field, mixed $value, array $params): void
    {
        if (!isset($_FILES[$field]) || $_FILES[$field]['error'] !== UPLOAD_ERR_OK) {
            return;
        }

        $maxKb = (int) ($params[0] ?? 2048);
        $fileSizeKb = $_FILES[$field]['size'] / 1024;

        if ($fileSizeKb > $maxKb) {
            $this->addError($field, "The {$this->label($field)} must not exceed {$maxKb} KB.");
        }
    }

    /**
     * The field value must match the given regular expression.
     * Usage: regex:/^[A-Z]{3}-\d+$/
     */
    private function ruleRegex(string $field, mixed $value, array $params): void
    {
        if ($value === null) {
            return;
        }

        // Rejoin params in case the regex contained commas
        $pattern = implode(',', $params);

        if (@preg_match($pattern, (string) $value) !== 1) {
            $this->addError($field, "The {$this->label($field)} format is invalid.");
        }
    }

    /**
     * The field must match a {field}_confirmation field.
     * Usage: confirmed  (checks password_confirmation for the 'password' field)
     */
    private function ruleConfirmed(string $field, mixed $value, array $params): void
    {
        $confirmField = $field . '_confirmation';
        $confirmValue = $this->data[$confirmField] ?? null;

        if ($value !== $confirmValue) {
            $this->addError($field, "The {$this->label($field)} confirmation does not match.");
        }
    }

    /**
     * The field must be an array.
     */
    private function ruleArray(string $field, mixed $value, array $params): void
    {
        if ($value !== null && !is_array($value)) {
            $this->addError($field, "The {$this->label($field)} must be an array.");
        }
    }
}
