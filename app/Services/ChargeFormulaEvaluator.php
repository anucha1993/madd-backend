<?php

namespace App\Services;

/**
 * Safe Excel-style formula evaluator for ChargeFixedOverride.formula — e.g.
 * "({BASE} + {434}) * 35%" means (Base Freight + Surge Fee Commercial) * 35%, where {CODE}
 * references another charge code's amount from the SAME quote. Deliberately NOT eval()-based:
 * a hand-written recursive-descent parser over a tiny grammar (numbers, {code} refs, + - * / (),
 * and a postfix % operator) so no arbitrary PHP/code can ever be injected via the formula string.
 */
class ChargeFormulaEvaluator
{
    /** All distinct {CODE} references used in a formula, in the order they appear. */
    public static function extractReferencedCodes(string $formula): array
    {
        preg_match_all('/\{([^{}]+)\}/', $formula, $matches);

        return array_values(array_unique(array_map('trim', $matches[1] ?? [])));
    }

    /** Syntax-checks a formula (dummy value 1 for every referenced code) — catches typos/bad
     * syntax immediately at save time instead of only discovering it silently at quote time.
     * Returns a user-facing error message, or null if the formula is valid. */
    public static function validate(?string $formula): ?string
    {
        if (! $formula) {
            return 'กรุณาระบุสูตรคำนวณ';
        }

        $codes = self::extractReferencedCodes($formula);
        if (empty($codes)) {
            return 'สูตรต้องอ้างอิง Charge Code อย่างน้อย 1 รายการ เช่น {BASE}';
        }

        try {
            self::evaluate($formula, array_fill_keys($codes, 1.0));
        } catch (\Throwable $e) {
            return 'สูตรไม่ถูกต้อง: '.$e->getMessage();
        }

        return null;
    }

    /** @param array<string, float> $valuesByCode charge code => that quote's raw amount */
    public static function evaluate(string $formula, array $valuesByCode): float
    {
        $tokens = self::tokenize($formula);
        $pos = 0;
        $value = self::parseExpression($tokens, $pos, $valuesByCode);

        if ($pos < count($tokens)) {
            throw new \RuntimeException('มีอักขระเกินความจำเป็นในสูตร (unexpected token)');
        }

        return $value;
    }

    private static function tokenize(string $formula): array
    {
        $tokens = [];
        $len = strlen($formula);
        $i = 0;

        while ($i < $len) {
            $ch = $formula[$i];

            if (ctype_space($ch)) {
                $i++;

                continue;
            }

            if ($ch === '{') {
                $end = strpos($formula, '}', $i);
                if ($end === false) {
                    throw new \RuntimeException('สูตรมี { ที่ไม่ได้ปิดด้วย }');
                }
                $tokens[] = ['type' => 'ref', 'value' => trim(substr($formula, $i + 1, $end - $i - 1))];
                $i = $end + 1;

                continue;
            }

            if (ctype_digit($ch) || ($ch === '.' && $i + 1 < $len && ctype_digit($formula[$i + 1]))) {
                $j = $i;
                while ($j < $len && (ctype_digit($formula[$j]) || $formula[$j] === '.')) {
                    $j++;
                }
                $tokens[] = ['type' => 'num', 'value' => (float) substr($formula, $i, $j - $i)];
                $i = $j;

                continue;
            }

            if (in_array($ch, ['+', '-', '*', '/', '(', ')', '%'], true)) {
                $tokens[] = ['type' => 'op', 'value' => $ch];
                $i++;

                continue;
            }

            throw new \RuntimeException("พบอักขระที่ไม่รู้จักในสูตร: \"{$ch}\"");
        }

        return $tokens;
    }

    private static function parseExpression(array $tokens, int &$pos, array $valuesByCode): float
    {
        $value = self::parseTerm($tokens, $pos, $valuesByCode);

        while (self::peekOp($tokens, $pos, ['+', '-'])) {
            $op = $tokens[$pos]['value'];
            $pos++;
            $rhs = self::parseTerm($tokens, $pos, $valuesByCode);
            $value = $op === '+' ? $value + $rhs : $value - $rhs;
        }

        return $value;
    }

    private static function parseTerm(array $tokens, int &$pos, array $valuesByCode): float
    {
        $value = self::parseFactor($tokens, $pos, $valuesByCode);

        while (self::peekOp($tokens, $pos, ['*', '/'])) {
            $op = $tokens[$pos]['value'];
            $pos++;
            $rhs = self::parseFactor($tokens, $pos, $valuesByCode);
            if ($op === '/') {
                if ((float) $rhs === 0.0) {
                    throw new \RuntimeException('สูตรมีการหารด้วยศูนย์');
                }
                $value /= $rhs;
            } else {
                $value *= $rhs;
            }
        }

        return $value;
    }

    /** Postfix %, e.g. "35%" => 0.35 — stacks fine ("35%%"), just rarely used twice. */
    private static function parseFactor(array $tokens, int &$pos, array $valuesByCode): float
    {
        $value = self::parseUnary($tokens, $pos, $valuesByCode);

        while (self::peekOp($tokens, $pos, ['%'])) {
            $pos++;
            $value /= 100;
        }

        return $value;
    }

    private static function parseUnary(array $tokens, int &$pos, array $valuesByCode): float
    {
        if (self::peekOp($tokens, $pos, ['-'])) {
            $pos++;

            return -self::parseUnary($tokens, $pos, $valuesByCode);
        }

        return self::parsePrimary($tokens, $pos, $valuesByCode);
    }

    private static function parsePrimary(array $tokens, int &$pos, array $valuesByCode): float
    {
        if ($pos >= count($tokens)) {
            throw new \RuntimeException('สูตรไม่สมบูรณ์');
        }

        $token = $tokens[$pos];

        if ($token['type'] === 'num') {
            $pos++;

            return $token['value'];
        }

        if ($token['type'] === 'ref') {
            $pos++;
            $code = $token['value'];
            if (! array_key_exists($code, $valuesByCode)) {
                throw new \RuntimeException("ไม่พบ Charge Code \"{$code}\" ในใบเสนอราคานี้");
            }

            return (float) $valuesByCode[$code];
        }

        if ($token['type'] === 'op' && $token['value'] === '(') {
            $pos++;
            $value = self::parseExpression($tokens, $pos, $valuesByCode);
            if (! self::peekOp($tokens, $pos, [')'])) {
                throw new \RuntimeException('สูตรขาดวงเล็บปิด )');
            }
            $pos++;

            return $value;
        }

        throw new \RuntimeException('สูตรมีรูปแบบไม่ถูกต้อง');
    }

    private static function peekOp(array $tokens, int $pos, array $ops): bool
    {
        return $pos < count($tokens) && $tokens[$pos]['type'] === 'op' && in_array($tokens[$pos]['value'], $ops, true);
    }
}
