<?php

namespace App\Services;

/**
 * Safe Excel-style formula evaluator for ChargeFixedOverride.formula / MarkupRule.formula — e.g.
 * "({BASE} + {434}) * 35%" means (Base Freight + Surge Fee Commercial) * 35%, where {CODE}
 * references another charge code's amount from the SAME quote. Deliberately NOT eval()-based:
 * a hand-written recursive-descent parser over a tiny grammar so no arbitrary PHP/code can ever
 * be injected via the formula string.
 *
 * Grammar (lowest → highest precedence):
 *   or      := and (('|' | '||' | OR) and)*
 *   and     := compare (('&' | '&&' | AND) compare)*
 *   compare := sum (('>' | '>=' | '<' | '<=' | '=' | '==' | '!=' | '<>') sum)?
 *   sum     := term (('+' | '-') term)*
 *   term    := percent (('*' | '/') percent)*
 *   percent := unary ('%')*
 *   unary   := ('-' | '!') unary | primary
 *   primary := NUMBER [unit] | {CODE} | '(' or ')' | FUNC '(' args ')'
 *
 * Functions: IF(cond, then[, else=0]), AND(..), OR(..), NOT(x), MIN(..), MAX(..),
 * ROUNDUP(x[, digits]), BOX_OVER(kg) = number of boxes heavier than kg, BOX_KG_OVER(kg) = total
 * weight of ONLY those boxes (20 kg + 33 kg boxes, BOX_KG_OVER(30) = 33) — both need the quote's
 * per-box weights, see $context['package_weights']. A comparison/logic result is 1 or 0.
 *
 * IF only evaluates the branch it picks, and inside an IF condition a {CODE} the quote doesn't
 * have counts as 0 — so IF({190} > 0, 800, 0) works on quotes without code 190. Everywhere else a
 * missing code is still an error (callers fall back to the carrier's own amount).
 *
 * Unit words right after a number are ignored for readability: 26kg, 800THB, 2 boxes.
 */
class ChargeFormulaEvaluator
{
    private const FUNCTIONS = ['IF', 'AND', 'OR', 'NOT', 'MIN', 'MAX', 'ROUNDUP', 'BOX_OVER', 'BOX_KG_OVER'];

    private const UNIT_WORDS = ['KG', 'KGS', 'THB', 'BAHT', 'BOX', 'BOXES'];

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
        if (empty($codes) && ! preg_match('/\bBOX_(KG_)?OVER\s*\(/i', $formula)) {
            return 'สูตรต้องอ้างอิง Charge Code อย่างน้อย 1 รายการ เช่น {BASE}';
        }

        try {
            // all_branches: evaluate EVERY IF branch (not just the one dummy values would pick)
            // so a mistake hiding in an IF's other branch is still caught at save time.
            self::run(self::parse($formula), array_fill_keys($codes, 1.0), ['package_weights' => [1.0], 'all_branches' => true], false);
        } catch (\Throwable $e) {
            return 'สูตรไม่ถูกต้อง: '.$e->getMessage();
        }

        return null;
    }

    /**
     * @param  array<string, float>  $valuesByCode  charge code => that quote's raw amount
     * @param  array{package_weights?: array<int, float>}  $context
     */
    public static function evaluate(string $formula, array $valuesByCode, array $context = []): float
    {
        return self::run(self::parse($formula), $valuesByCode, $context, false);
    }

    private static function parse(string $formula): array
    {
        $tokens = self::tokenize($formula);
        $pos = 0;
        $ast = self::parseOr($tokens, $pos);

        if ($pos < count($tokens)) {
            throw new \RuntimeException('มีอักขระเกินความจำเป็นในสูตร (unexpected token)');
        }

        return $ast;
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

            if (ctype_alpha($ch) || $ch === '_') {
                $j = $i;
                while ($j < $len && (ctype_alnum($formula[$j]) || $formula[$j] === '_')) {
                    $j++;
                }
                $word = strtoupper(substr($formula, $i, $j - $i));
                $i = $j;

                $previous = end($tokens) ?: null;
                if (in_array($word, self::UNIT_WORDS, true) && ($previous['type'] ?? null) === 'num') {
                    continue; // "26kg", "800 THB" — the unit is just for readability
                }
                // AND(..) / OR(..) are functions; "a AND b" / "a OR b" are infix operators.
                $isCall = (ltrim(substr($formula, $i))[0] ?? '') === '(';
                if ($word === 'AND' && ! $isCall) {
                    $tokens[] = ['type' => 'op', 'value' => '&'];
                } elseif ($word === 'OR' && ! $isCall) {
                    $tokens[] = ['type' => 'op', 'value' => '|'];
                } elseif (in_array($word, self::FUNCTIONS, true)) {
                    $tokens[] = ['type' => 'func', 'value' => $word];
                } else {
                    throw new \RuntimeException("ไม่รู้จักคำว่า \"{$word}\" — Charge Code ต้องอยู่ใน { } เช่น {{$word}}");
                }

                continue;
            }

            $two = substr($formula, $i, 2);
            if (in_array($two, ['>=', '<=', '==', '!=', '<>', '&&', '||'], true)) {
                $tokens[] = ['type' => 'op', 'value' => match ($two) {
                    '==' => '=', '<>' => '!=', '&&' => '&', '||' => '|', default => $two,
                }];
                $i += 2;

                continue;
            }

            if (in_array($ch, ['+', '-', '*', '/', '(', ')', '%', '>', '<', '=', '!', '&', '|', ','], true)) {
                $tokens[] = ['type' => 'op', 'value' => $ch];
                $i++;

                continue;
            }

            throw new \RuntimeException("พบอักขระที่ไม่รู้จักในสูตร: \"{$ch}\"");
        }

        return $tokens;
    }

    private static function parseOr(array $tokens, int &$pos): array
    {
        $node = self::parseAnd($tokens, $pos);
        while (self::peekOp($tokens, $pos, ['|'])) {
            $pos++;
            $node = ['bin', '|', $node, self::parseAnd($tokens, $pos)];
        }

        return $node;
    }

    private static function parseAnd(array $tokens, int &$pos): array
    {
        $node = self::parseCompare($tokens, $pos);
        while (self::peekOp($tokens, $pos, ['&'])) {
            $pos++;
            $node = ['bin', '&', $node, self::parseCompare($tokens, $pos)];
        }

        return $node;
    }

    private static function parseCompare(array $tokens, int &$pos): array
    {
        $node = self::parseSum($tokens, $pos);
        if (self::peekOp($tokens, $pos, ['>', '>=', '<', '<=', '=', '!='])) {
            $op = $tokens[$pos]['value'];
            $pos++;
            $node = ['bin', $op, $node, self::parseSum($tokens, $pos)];
        }

        return $node;
    }

    private static function parseSum(array $tokens, int &$pos): array
    {
        $node = self::parseTerm($tokens, $pos);
        while (self::peekOp($tokens, $pos, ['+', '-'])) {
            $op = $tokens[$pos]['value'];
            $pos++;
            $node = ['bin', $op, $node, self::parseTerm($tokens, $pos)];
        }

        return $node;
    }

    private static function parseTerm(array $tokens, int &$pos): array
    {
        $node = self::parsePercent($tokens, $pos);
        while (self::peekOp($tokens, $pos, ['*', '/'])) {
            $op = $tokens[$pos]['value'];
            $pos++;
            $node = ['bin', $op, $node, self::parsePercent($tokens, $pos)];
        }

        return $node;
    }

    /** Postfix %, e.g. "35%" => 0.35 — stacks fine ("35%%"), just rarely used twice. */
    private static function parsePercent(array $tokens, int &$pos): array
    {
        $node = self::parseUnary($tokens, $pos);
        while (self::peekOp($tokens, $pos, ['%'])) {
            $pos++;
            $node = ['pct', $node];
        }

        return $node;
    }

    private static function parseUnary(array $tokens, int &$pos): array
    {
        if (self::peekOp($tokens, $pos, ['-'])) {
            $pos++;

            return ['neg', self::parseUnary($tokens, $pos)];
        }
        if (self::peekOp($tokens, $pos, ['!'])) {
            $pos++;

            return ['not', self::parseUnary($tokens, $pos)];
        }

        return self::parsePrimary($tokens, $pos);
    }

    private static function parsePrimary(array $tokens, int &$pos): array
    {
        if ($pos >= count($tokens)) {
            throw new \RuntimeException('สูตรไม่สมบูรณ์');
        }

        $token = $tokens[$pos];

        if ($token['type'] === 'num') {
            $pos++;

            return ['num', $token['value']];
        }

        if ($token['type'] === 'ref') {
            $pos++;

            return ['ref', $token['value']];
        }

        if ($token['type'] === 'func') {
            $pos++;
            if (! self::peekOp($tokens, $pos, ['('])) {
                throw new \RuntimeException("{$token['value']} ต้องตามด้วยวงเล็บ เช่น {$token['value']}(...)");
            }
            $pos++;
            $args = [];
            if (! self::peekOp($tokens, $pos, [')'])) {
                $args[] = self::parseOr($tokens, $pos);
                while (self::peekOp($tokens, $pos, [','])) {
                    $pos++;
                    $args[] = self::parseOr($tokens, $pos);
                }
            }
            if (! self::peekOp($tokens, $pos, [')'])) {
                throw new \RuntimeException("{$token['value']}( ขาดวงเล็บปิด )");
            }
            $pos++;
            self::checkArity($token['value'], count($args));

            return ['call', $token['value'], $args];
        }

        if ($token['type'] === 'op' && $token['value'] === '(') {
            $pos++;
            $node = self::parseOr($tokens, $pos);
            if (! self::peekOp($tokens, $pos, [')'])) {
                throw new \RuntimeException('สูตรขาดวงเล็บปิด )');
            }
            $pos++;

            return $node;
        }

        throw new \RuntimeException('สูตรมีรูปแบบไม่ถูกต้อง');
    }

    private static function checkArity(string $func, int $count): void
    {
        [$min, $max] = match ($func) {
            'IF' => [2, 3],
            'NOT', 'BOX_OVER', 'BOX_KG_OVER' => [1, 1],
            'ROUNDUP' => [1, 2],
            default => [1, PHP_INT_MAX],
        };
        if ($count < $min || $count > $max) {
            throw new \RuntimeException($func === 'IF'
                ? 'IF ต้องเขียนแบบ IF(เงื่อนไข, ค่าเมื่อจริง, ค่าเมื่อไม่จริง)'
                : "{$func}() ใส่ค่าไม่ครบหรือเกิน");
        }
    }

    /** @param  bool  $lenient  inside an IF condition — a missing {CODE} counts as 0 */
    private static function run(array $node, array $values, array $context, bool $lenient): float
    {
        $eval = fn (array $n, ?bool $l = null) => self::run($n, $values, $context, $l ?? $lenient);

        switch ($node[0]) {
            case 'num':
                return $node[1];

            case 'ref':
                if (! array_key_exists($node[1], $values)) {
                    if ($lenient) {
                        return 0.0;
                    }
                    throw new \RuntimeException("ไม่พบ Charge Code \"{$node[1]}\" ในใบเสนอราคานี้");
                }

                return (float) $values[$node[1]];

            case 'neg':
                return -$eval($node[1]);

            case 'not':
                return $eval($node[1]) == 0 ? 1.0 : 0.0;

            case 'pct':
                return $eval($node[1]) / 100;

            case 'bin':
                [, $op, $left, $right] = $node;
                if ($op === '&') {
                    return ($eval($left) != 0 && $eval($right) != 0) ? 1.0 : 0.0;
                }
                if ($op === '|') {
                    return ($eval($left) != 0 || $eval($right) != 0) ? 1.0 : 0.0;
                }
                $a = $eval($left);
                $b = $eval($right);

                return match ($op) {
                    '+' => $a + $b,
                    '-' => $a - $b,
                    '*' => $a * $b,
                    '/' => (float) $b === 0.0 ? throw new \RuntimeException('สูตรมีการหารด้วยศูนย์') : $a / $b,
                    '>' => $a > $b ? 1.0 : 0.0,
                    '>=' => $a >= $b ? 1.0 : 0.0,
                    '<' => $a < $b ? 1.0 : 0.0,
                    '<=' => $a <= $b ? 1.0 : 0.0,
                    '=' => abs($a - $b) < 0.005 ? 1.0 : 0.0,
                    '!=' => abs($a - $b) >= 0.005 ? 1.0 : 0.0,
                };

            case 'call':
                [, $func, $args] = $node;

                switch ($func) {
                    case 'IF':
                        if (! empty($context['all_branches'])) {
                            $eval($args[0], true);
                            $then = $eval($args[1]);
                            isset($args[2]) && $eval($args[2]);

                            return $then;
                        }
                        if ($eval($args[0], true) != 0) {
                            return $eval($args[1]);
                        }

                        return isset($args[2]) ? $eval($args[2]) : 0.0;
                    case 'AND':
                        foreach ($args as $arg) {
                            if ($eval($arg) == 0) {
                                return 0.0;
                            }
                        }

                        return 1.0;
                    case 'OR':
                        foreach ($args as $arg) {
                            if ($eval($arg) != 0) {
                                return 1.0;
                            }
                        }

                        return 0.0;
                    case 'NOT':
                        return $eval($args[0]) == 0 ? 1.0 : 0.0;
                    case 'MIN':
                        return min(array_map($eval, $args));
                    case 'MAX':
                        return max(array_map($eval, $args));
                    case 'ROUNDUP':
                        $factor = 10 ** (int) (isset($args[1]) ? $eval($args[1]) : 0);

                        return ceil(round($eval($args[0]) * $factor, 6)) / $factor;
                    case 'BOX_OVER':
                        $limit = $eval($args[0]);

                        return (float) count(array_filter($context['package_weights'] ?? [], fn ($w) => (float) $w > $limit));
                    case 'BOX_KG_OVER':
                        $limit = $eval($args[0]);

                        return (float) array_sum(array_filter($context['package_weights'] ?? [], fn ($w) => (float) $w > $limit));
                }
        }

        throw new \RuntimeException('สูตรมีรูปแบบไม่ถูกต้อง');
    }

    private static function peekOp(array $tokens, int $pos, array $ops): bool
    {
        return $pos < count($tokens) && $tokens[$pos]['type'] === 'op' && in_array($tokens[$pos]['value'], $ops, true);
    }
}
