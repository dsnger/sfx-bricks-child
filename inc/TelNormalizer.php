<?php

declare(strict_types=1);

namespace SFX;

/**
 * Phone number normalisation for tel: URIs, shared by ContactInfos and TelFormat.
 */
class TelNormalizer
{
    /**
     * A phone number as RFC 3966 wants it in a tel: URI: digits and one leading +, no spaces.
     * A leading 00 becomes +, a leading single 0 becomes +<country code> (filter
     * sfx_contact_info_default_country_code, default 49), and the "(0)" written after a country
     * code is dropped. Numbers without 0/00/+ cannot be completed and keep their digits only.
     * An extension (";ext=123", "x 123", "ext. 123", "Durchwahl 123", "DW 123" at the end) becomes
     * ";ext=123"; German "-0" style switchboard digits are part of the number and stay.
     * Known limit: the "(0)" marker is recognised after 1–3 digits following + or 00, so a
     * compact "+493(0)…" cannot be told apart from "+49 (0)…" without a country-code table.
     * Output only — stored values stay as entered.
     */
    public static function normalize_tel(string $value): string
    {
        // An extension is kept apart, so its digits never join the number. RFC 3966 form first:
        // everything from the first ";" is parameters, only "ext=" among them is used.
        $ext_raw = '';
        $semi = strpos($value, ';');
        if ($semi !== false) {
            // Digits, separators and spaces only — note text after it ("12 Büro 3") ends the extension.
            if (preg_match('/;[\s\p{Z}]*ext[\s\p{Z}]*=([0-9().\/\-\s\p{Z}]*)/iu', substr($value, $semi), $m)) {
                $ext_raw = $m[1];
            }
            $value = substr($value, 0, $semi);
        } elseif (preg_match('/(?<=[0-9\s\p{Z},)])(?:x|ext\.?|extension|durchwahl|dw\.?)[\s\p{Z}]*:?[\s\p{Z}]*([0-9(][0-9().\/\-\s\p{Z}]*)$/iu', $value, $m, PREG_OFFSET_CAPTURE)) {
            // Written form at the end: "x 12", "x12", "ext. 12", "Durchwahl 12", "DW 12".
            $ext_raw = $m[1][0];
            $value = substr($value, 0, $m[0][1]);
        }
        $ext_digits = (string) preg_replace('/\D+/', '', $ext_raw); // visual separators allowed
        $ext = $ext_digits !== '' ? ';ext=' . $ext_digits : '';

        $value = (string) preg_replace('/^[\s\p{Z}]+/u', '', $value); // incl. non-breaking spaces
        $international = str_starts_with($value, '+') || str_starts_with($value, '00');
        if ($international) {
            // "+49 (0)208": the trunk zero is only written, never dialled after a country code.
            // Only the marker right after the country code — a "(0)" later is a subscriber digit.
            $value = (string) preg_replace('/^(\+|00)([\s\p{Z}]*\d{1,3})[\s\p{Z}.\/-]*\([\s\p{Z}]*0[\s\p{Z}]*\)/u', '$1$2 ', $value);
        }

        $digits = (string) preg_replace('/\D+/', '', $value);
        if ($digits === '') {
            return '';
        }
        if (str_starts_with($value, '+')) {
            return '+' . $digits . $ext;
        }
        if (str_starts_with($digits, '00')) {
            $rest = substr($digits, 2);
            return $rest !== '' ? '+' . $rest . $ext : ''; // "00" alone is no number
        }
        if (str_starts_with($digits, '0')) {
            $rest = substr($digits, 1);
            if ($rest === '') {
                return '';
            }
            $country = (string) preg_replace('/\D+/', '', (string) apply_filters('sfx_contact_info_default_country_code', '49'));
            return '+' . ($country !== '' ? $country : '49') . $rest . $ext;
        }
        return $digits . $ext;
    }
}
