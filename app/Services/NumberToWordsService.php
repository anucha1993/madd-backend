<?php

namespace App\Services;

/**
 * English "amount in words" for the GRAND TOTAL IN WORDS line on Receipts/Tax Invoices — matches
 * the reference template's style exactly (e.g. 48,327.00 => "Forty Eight Thousand Three Hundred
 * Twenty Seven Baht Only", no hyphens between tens/ones).
 */
class NumberToWordsService
{
    private const ONES = ['', 'One', 'Two', 'Three', 'Four', 'Five', 'Six', 'Seven', 'Eight', 'Nine'];

    private const TEENS = ['Ten', 'Eleven', 'Twelve', 'Thirteen', 'Fourteen', 'Fifteen', 'Sixteen', 'Seventeen', 'Eighteen', 'Nineteen'];

    private const TENS = ['', '', 'Twenty', 'Thirty', 'Forty', 'Fifty', 'Sixty', 'Seventy', 'Eighty', 'Ninety'];

    public function bahtText(float $amount): string
    {
        $amount = round($amount, 2);
        $baht = (int) floor($amount);
        $satang = (int) round(($amount - $baht) * 100);

        $words = $this->convert($baht).' Baht';
        $words .= $satang > 0 ? ' and '.$this->convert($satang).' Satang' : ' Only';

        return $words;
    }

    private function convert(int $number): string
    {
        if ($number === 0) {
            return 'Zero';
        }

        $groups = [1_000_000_000 => 'Billion', 1_000_000 => 'Million', 1_000 => 'Thousand'];

        $words = '';
        foreach ($groups as $value => $label) {
            if ($number >= $value) {
                $words .= $this->convertHundreds(intdiv($number, $value)).' '.$label.' ';
                $number %= $value;
            }
        }
        $words .= $this->convertHundreds($number);

        return trim(preg_replace('/\s+/', ' ', $words));
    }

    private function convertHundreds(int $number): string
    {
        if ($number === 0) {
            return '';
        }

        $words = '';
        if ($number >= 100) {
            $words .= self::ONES[intdiv($number, 100)].' Hundred ';
            $number %= 100;
        }

        if ($number >= 20) {
            $words .= self::TENS[intdiv($number, 10)];
            if ($number % 10 > 0) {
                $words .= ' '.self::ONES[$number % 10];
            }
        } elseif ($number >= 10) {
            $words .= self::TEENS[$number - 10];
        } elseif ($number > 0) {
            $words .= self::ONES[$number];
        }

        return trim($words);
    }
}
