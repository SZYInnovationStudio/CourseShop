<?php

declare(strict_types=1);

namespace App\Support;

/**
 * 表单校验器
 *
 * 用法：
 *   $validator = (new Validator(Request::all()))
 *       ->required('username', '用户名')
 *       ->username('username', '用户名')
 *       ->required('password', '密码')->min('password', 8, '密码');
 *   if ($validator->fails()) { ... $validator->firstError() ... }
 */
final class Validator
{
    /** @var array<string, string> 字段 => 第一条错误信息 */
    private array $errors = [];

    /** @param array<string, mixed> $data */
    public function __construct(private array $data)
    {
    }

    /**
     * 必填
     */
    public function required(string $field, string $label): self
    {
        $value = $this->value($field);

        if ($value === null || (is_string($value) && trim($value) === '')) {
            $this->addError($field, $label . '不能为空。');
        }

        return $this;
    }

    public function email(string $field, string $label): self
    {
        $value = (string) $this->value($field);
        if ($value !== '' && filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
            $this->addError($field, $label . '格式不正确。');
        }

        return $this;
    }

    /**
     * 用户名字符规则：3-20 位字母、数字或下划线
     */
    public function username(string $field, string $label): self
    {
        $value = (string) $this->value($field);
        if ($value !== '' && preg_match('/^[A-Za-z0-9_]{3,20}$/', $value) !== 1) {
            $this->addError($field, $label . '只能包含 3-20 位字母、数字或下划线。');
        }

        return $this;
    }

    /**
     * 最小长度（按字符数计算，兼容中文）
     */
    public function min(string $field, int $length, string $label): self
    {
        $value = (string) $this->value($field);
        if ($value !== '' && mb_strlen($value) < $length) {
            $this->addError($field, sprintf('%s不能少于 %d 个字符。', $label, $length));
        }

        return $this;
    }

    public function max(string $field, int $length, string $label): self
    {
        $value = (string) $this->value($field);
        if (mb_strlen($value) > $length) {
            $this->addError($field, sprintf('%s不能超过 %d 个字符。', $label, $length));
        }

        return $this;
    }

    /**
     * 两次输入一致
     */
    public function same(string $field, string $otherField, string $label): self
    {
        if ((string) $this->value($field) !== (string) $this->value($otherField)) {
            $this->addError($field, $label . '与确认输入不一致。');
        }

        return $this;
    }

    /**
     * 必须为真（勾选协议等）
     */
    public function accepted(string $field, string $label): self
    {
        $value = $this->value($field);
        if (!in_array((string) $value, ['1', 'on', 'true', 'yes'], true)) {
            $this->addError($field, '请先勾选并同意' . $label . '。');
        }

        return $this;
    }

    public function in(string $field, array $allowed, string $label): self
    {
        $value = (string) $this->value($field);
        if ($value !== '' && !in_array($value, $allowed, true)) {
            $this->addError($field, $label . '取值不合法。');
        }

        return $this;
    }

    public function integer(string $field, string $label, int $min = PHP_INT_MIN, int $max = PHP_INT_MAX): self
    {
        $value = $this->value($field);
        if ($value === null || $value === '' || !is_numeric($value)) {
            $this->addError($field, $label . '必须为数字。');

            return $this;
        }

        $int = (int) $value;
        if ($int < $min || $int > $max) {
            $this->addError($field, $label . '超出允许范围。');
        }

        return $this;
    }

    public function addError(string $field, string $message): void
    {
        $this->errors[$field] ??= $message;
    }

    public function fails(): bool
    {
        return $this->errors !== [];
    }

    /** @return array<string, string> */
    public function errors(): array
    {
        return $this->errors;
    }

    public function firstError(): ?string
    {
        foreach ($this->errors as $message) {
            return $message;
        }

        return null;
    }

    private function value(string $field): mixed
    {
        return $this->data[$field] ?? null;
    }
}
