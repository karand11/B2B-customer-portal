<?php

namespace App\Support;

/** Locale-independent money text from integer cents (no number_format, no float): "USD 1,234.56". */
class Money
{
    public static function amount(int $cents): string
    {
        $abs = abs($cents);

        return ($cents < 0 ? '-' : '').preg_replace('/\B(?=(\d{3})+(?!\d))/', ',', (string) intdiv($abs, 100)).'.'.str_pad((string) ($abs % 100), 2, '0', STR_PAD_LEFT);
    }

    public static function format(int $cents, string $currency): string
    {
        return $currency.' '.self::amount($cents);
    }

    /** 1900 => "19%", 750 => "7.5%", 725 => "7.25%", 0 => "0%". */
    public static function percent(int $basisPoints): string
    {
        $fraction = rtrim(str_pad((string) ($basisPoints % 100), 2, '0', STR_PAD_LEFT), '0');

        return intdiv($basisPoints, 100).($fraction === '' ? '' : ".{$fraction}").'%';
    }
}
