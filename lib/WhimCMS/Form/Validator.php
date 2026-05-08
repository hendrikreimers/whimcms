<?php
declare(strict_types=1);

namespace H42\WhimCMS\Form;

/**
 * Field-rule validator + sanitizer for form input.
 *
 * Rules come straight from config (`contact.fields`); adding a field is
 * a one-line change there plus matching markup in the template.
 *
 * Supported rule keys:
 *   required   bool   field must be present and non-empty after trim
 *   type       str    text | email | tel | select | checkbox
 *   min        int    minimum length (text/textarea)
 *   max        int    maximum length / hard trim cap
 *   pattern    str    optional regex (PHP, including delimiters)
 *   allowed    list   for type='select': whitelist of allowed values
 *   multiline  bool   text fields only — true = preserve \n / \t
 *                     (textarea-style); false (default) = collapse
 *                     them to spaces. Set true for multi-line fields
 *                     (e.g. message bodies); leave false for any
 *                     single-line field whose value could later flow
 *                     into a header sink (mail Subject, Reply-To, …).
 *
 * Sanitization happens *before* validation:
 *   - strip null bytes, normalise newlines to \n
 *   - drop control chars except \t, \n
 *   - if NOT multiline: replace remaining \n / \t with single space
 *   - hard-cut at `max` length to bound memory
 *   - NFC normalise (if Intl is available) so canonically equivalent
 *     unicode forms compare/store consistently
 *
 * The validator returns the cleaned values plus a parallel error map.
 * Errors are *codes* (e.g. 'required', 'too_short', 'invalid_email'),
 * resolved to user-facing strings by the caller via i18n.
 */
final class Validator
{
    /**
     * @param array<string, array<string, mixed>> $rules  field → rules
     */
    public function __construct(private array $rules)
    {
    }

    /**
     * @param array<string, mixed> $input  Raw $_POST data.
     * @return array{values: array<string, mixed>, errors: array<string, string>}
     */
    public function validate(array $input): array
    {
        $values = [];
        $errors = [];

        foreach ($this->rules as $field => $rule) {
            $type = (string)($rule['type'] ?? 'text');
            $raw = $input[$field] ?? null;

            // Checkbox: present + non-empty = true.
            if ($type === 'checkbox') {
                $values[$field] = !empty($raw);
                if (!empty($rule['required']) && $values[$field] !== true) {
                    $errors[$field] = 'required';
                }
                continue;
            }

            // Strings: sanitize first, validate after.
            if (!is_string($raw)) {
                $raw = '';
            }
            $multiline = !empty($rule['multiline']);
            $clean = $this->sanitize($raw, (int)($rule['max'] ?? 1000), $multiline);
            $values[$field] = $clean;

            if (!empty($rule['required']) && $clean === '') {
                $errors[$field] = 'required';
                continue;
            }
            if ($clean === '' && empty($rule['required'])) {
                continue; // empty optional field — skip remaining checks
            }

            $err = match ($type) {
                'email'  => $this->validateEmail($clean, $rule),
                'tel'    => $this->validateTel($clean, $rule),
                'select' => $this->validateSelect($clean, $rule),
                default  => $this->validateText($clean, $rule),
            };
            if ($err !== null) {
                $errors[$field] = $err;
            }
        }

        return ['values' => $values, 'errors' => $errors];
    }

    /**
     * Strip null bytes / control chars (except \t, \n), normalise CRLF/CR
     * to LF, NFC-normalise (if Intl present), and hard-cut to `$max`.
     *
     * `$multiline = false` (the default) collapses any surviving \n / \t
     * to single spaces — so a `name` field with an embedded newline
     * cannot later flow into a mail header sink as a header injection.
     * Mehrzeilen-Felder wie `message` setzen `$multiline = true` und
     * behalten die Zeilenumbrüche.
     */
    private function sanitize(string $value, int $max, bool $multiline = false): string
    {
        // Normalise newlines first so width calculations are stable.
        $value = str_replace(["\r\n", "\r"], "\n", $value);
        // Strip null + most control chars (keep \t \n).
        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value) ?? $value;
        // Single-line fields: collapse remaining \n / \t to spaces.
        // Defence against the case where a future code path drops a
        // single-line value into a mail header / log line / similar
        // line-oriented sink. The message body explicitly opts in to
        // keep newlines via `multiline: true` in its rule.
        if (!$multiline) {
            $value = strtr($value, ["\n" => ' ', "\t" => ' ']);
        }
        // NFC normalise if Intl is available (homoglyph defence).
        if (class_exists(\Normalizer::class)) {
            $normalised = \Normalizer::normalize($value, \Normalizer::FORM_C);
            if (is_string($normalised)) {
                $value = $normalised;
            }
        }
        $value = trim($value);
        if ($max > 0 && mb_strlen($value, 'UTF-8') > $max) {
            $value = mb_substr($value, 0, $max, 'UTF-8');
        }
        return $value;
    }

    /** @param array<string, mixed> $rule */
    private function validateText(string $clean, array $rule): ?string
    {
        $min = (int)($rule['min'] ?? 0);
        $max = (int)($rule['max'] ?? 0);
        $len = mb_strlen($clean, 'UTF-8');
        if ($min > 0 && $len < $min) {
            return 'too_short';
        }
        if ($max > 0 && $len > $max) {
            return 'too_long';
        }
        if (!empty($rule['pattern']) && is_string($rule['pattern'])
            && @preg_match((string)$rule['pattern'], $clean) !== 1) {
            return 'invalid_format';
        }
        return null;
    }

    /** @param array<string, mixed> $rule */
    private function validateEmail(string $clean, array $rule): ?string
    {
        if (filter_var($clean, FILTER_VALIDATE_EMAIL) === false) {
            return 'invalid_email';
        }
        // Reject quoted-local-parts ("foo bar"@a.tld). FILTER_VALIDATE_EMAIL
        // accepts them per RFC 5321, but the form accepts arbitrary characters
        // inside the quotes — letting a submitter stuff a long, visually
        // misleading string into the local-part that surfaces verbatim in
        // the operator's mail client (mailto-href text) when replying.
        // Real-world senders never use this form.
        if (str_starts_with($clean, '"')) {
            return 'invalid_email';
        }
        // Reject obvious header-injection payloads even though FILTER would
        // already catch most. Defence-in-depth.
        if (preg_match('/[\r\n\t]/', $clean) === 1) {
            return 'invalid_email';
        }
        $max = (int)($rule['max'] ?? 0);
        if ($max > 0 && mb_strlen($clean, 'UTF-8') > $max) {
            return 'too_long';
        }
        return null;
    }

    /** @param array<string, mixed> $rule */
    private function validateTel(string $clean, array $rule): ?string
    {
        // Permissive: digits, spaces, +, -, (, ), /. Up to 32 chars.
        if (preg_match('/^[+0-9 ()\/\-]{4,32}$/', $clean) !== 1) {
            return 'invalid_phone';
        }
        return null;
    }

    /** @param array<string, mixed> $rule */
    private function validateSelect(string $clean, array $rule): ?string
    {
        $allowed = (array)($rule['allowed'] ?? []);
        if ($allowed === [] || in_array($clean, $allowed, true)) {
            return null;
        }
        return 'invalid_choice';
    }
}
