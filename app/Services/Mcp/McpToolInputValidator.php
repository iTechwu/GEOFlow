<?php

namespace App\Services\Mcp;

use App\Exceptions\ApiException;

final class McpToolInputValidator
{
    /**
     * @param  array<string,mixed>  $schema
     * @param  array<string,mixed>  $arguments
     */
    public function validate(array $schema, array $arguments): void
    {
        $errors = [];
        $this->validateValue($schema, $arguments, 'arguments', $errors);

        if ($errors !== []) {
            throw new ApiException('validation_failed', 'MCP 工具参数校验失败', 422, [
                'field_errors' => $errors,
            ]);
        }
    }

    /**
     * @param  array<string,mixed>  $schema
     * @param  array<string,string>  $errors
     */
    private function validateValue(array $schema, mixed $value, string $path, array &$errors): void
    {
        if (isset($schema['anyOf']) && is_array($schema['anyOf'])) {
            foreach ($schema['anyOf'] as $candidate) {
                if (! is_array($candidate)) {
                    continue;
                }

                $candidateErrors = [];
                $this->validateValue($candidate, $value, $path, $candidateErrors);
                if ($candidateErrors === []) {
                    return;
                }
            }

            $errors[$path] = '参数类型不符合约定';

            return;
        }

        $type = $schema['type'] ?? null;
        if (is_string($type) && ! $this->matchesType($type, $value)) {
            $errors[$path] = '参数类型必须为 '.$type;

            return;
        }

        if (isset($schema['enum']) && is_array($schema['enum']) && ! in_array($value, $schema['enum'], true)) {
            $errors[$path] = '参数值不在允许范围内';

            return;
        }

        if ($type === 'object' && is_array($value)) {
            $this->validateObject($schema, $value, $path, $errors);
        }

        if ($type === 'array' && is_array($value) && isset($schema['items']) && is_array($schema['items'])) {
            foreach ($value as $index => $item) {
                $this->validateValue($schema['items'], $item, $path.'.'.$index, $errors);
            }
        }

        if ($type === 'integer' && is_int($value)) {
            if (isset($schema['minimum']) && $value < (int) $schema['minimum']) {
                $errors[$path] = '参数值不能小于 '.$schema['minimum'];
            }
            if (isset($schema['maximum']) && $value > (int) $schema['maximum']) {
                $errors[$path] = '参数值不能大于 '.$schema['maximum'];
            }
        }
    }

    /**
     * @param  array<string,mixed>  $schema
     * @param  array<string,mixed>  $value
     * @param  array<string,string>  $errors
     */
    private function validateObject(array $schema, array $value, string $path, array &$errors): void
    {
        $properties = is_array($schema['properties'] ?? null) ? $schema['properties'] : [];
        $required = is_array($schema['required'] ?? null) ? $schema['required'] : [];

        foreach ($required as $field) {
            if (is_string($field) && ! array_key_exists($field, $value)) {
                $errors[$path.'.'.$field] = '缺少必填参数';
            }
        }

        if (($schema['additionalProperties'] ?? true) === false) {
            foreach (array_diff(array_keys($value), array_keys($properties)) as $field) {
                $errors[$path.'.'.$field] = '不支持该参数';
            }
        }

        foreach ($properties as $field => $propertySchema) {
            if (array_key_exists($field, $value) && is_array($propertySchema)) {
                $this->validateValue($propertySchema, $value[$field], $path.'.'.$field, $errors);
            }
        }
    }

    private function matchesType(string $type, mixed $value): bool
    {
        return match ($type) {
            'object' => is_array($value) && ($value === [] || ! array_is_list($value)),
            'array' => is_array($value) && array_is_list($value),
            'integer' => is_int($value),
            'string' => is_string($value),
            'boolean' => is_bool($value),
            'null' => $value === null,
            default => true,
        };
    }
}
