<?php

/**
 * This file is part of PHPWord - A pure PHP library for reading and writing
 * word processing documents.
 *
 * PHPWord is free software distributed under the terms of the GNU Lesser
 * General Public License version 3 as published by the Free Software Foundation.
 *
 * For the full copyright and license information, please read the LICENSE
 * file that was distributed with this source code. For the full list of
 * contributors, visit https://github.com/PHPOffice/PHPWord/contributors.
 *
 * @see         https://github.com/PHPOffice/PHPWord
 *
 * @license     http://www.gnu.org/licenses/lgpl.txt LGPL version 3
 */

namespace PhpOffice\PhpWord\SimpleType;

use PhpOffice\PhpWord\Shared\AbstractEnum;

/**
 * Numbering Format.
 *
 * @since 0.14.0
 * @see http://www.datypic.com/sc/ooxml/t-w_ST_NumberFormat.html.
 */
final class NumberFormat extends AbstractEnum
{
	//Decimal Numbers
	public const DECIMAL = 'decimal';
	//Uppercase Roman Numerals
	public const UPPER_ROMAN = 'upperRoman';
	//Lowercase Roman Numerals
	public const LOWER_ROMAN = 'lowerRoman';
	//Uppercase Latin Alphabet
	public const UPPER_LETTER = 'upperLetter';
	//Lowercase Latin Alphabet
	public const LOWER_LETTER = 'lowerLetter';
	//Ordinal
	public const ORDINAL = 'ordinal';
	//Cardinal Text
	public const CARDINAL_TEXT = 'cardinalText';
	//Ordinal Text
	public const ORDINAL_TEXT = 'ordinalText';
	//Hexadecimal Numbering
	public const HEX = 'hex';
	//Chicago Manual of Style
	public const CHICAGO = 'chicago';
	//Ideographs
	public const IDEOGRAPH_DIGITAL = 'ideographDigital';
	//Japanese Counting System
	public const JAPANESE_COUNTING = 'japaneseCounting';
	//AIUEO Order Hiragana
	public const AIUEO = 'aiueo';
	//Iroha Ordered Katakana
	public const IROHA = 'iroha';
	//Double Byte Arabic Numerals
	public const DECIMAL_FULL_WIDTH = 'decimalFullWidth';
	//Single Byte Arabic Numerals
	public const DECIMAL_HALF_WIDTH = 'decimalHalfWidth';
	//Japanese Legal Numbering
	public const JAPANESE_LEGAL = 'japaneseLegal';
	//Japanese Digital Ten Thousand Counting System
	public const JAPANESE_DIGITAL_TEN_THOUSAND = 'japaneseDigitalTenThousand';
	//Decimal Numbers Enclosed in a Circle
	public const DECIMAL_ENCLOSED_CIRCLE = 'decimalEnclosedCircle';
	//Double Byte Arabic Numerals Alternate
	public const DECIMAL_FULL_WIDTH2 = 'decimalFullWidth2';
	//Full-Width AIUEO Order Hiragana
	public const AIUEO_FULL_WIDTH = 'aiueoFullWidth';
	//Full-Width Iroha Ordered Katakana
	public const IROHA_FULL_WIDTH = 'irohaFullWidth';
	//Initial Zero Arabic Numerals
	public const DECIMAL_ZERO = 'decimalZero';
	//Bullet
	public const BULLET = 'bullet';
	//Korean Ganada Numbering
	public const GANADA = 'ganada';
	//Korean Chosung Numbering
	public const CHOSUNG = 'chosung';
	//Decimal Numbers Followed by a Period
	public const DECIMAL_ENCLOSED_FULL_STOP = 'decimalEnclosedFullstop';
	//Decimal Numbers Enclosed in Parenthesis
	public const DECIMAL_ENCLOSED_PAREN = 'decimalEnclosedParen';
	//Decimal Numbers Enclosed in a Circle
	public const DECIMAL_ENCLOSED_CIRCLE_CHINESE = 'decimalEnclosedCircleChinese';
	//Ideographs Enclosed in a Circle
	public const IDEOGRAPHENCLOSEDCIRCLE = 'ideographEnclosedCircle';
	//Traditional Ideograph Format
	public const IDEOGRAPH_TRADITIONAL = 'ideographTraditional';
	//Zodiac Ideograph Format
	public const IDEOGRAPH_ZODIAC = 'ideographZodiac';
	//Traditional Zodiac Ideograph Format
	public const IDEOGRAPH_ZODIAC_TRADITIONAL = 'ideographZodiacTraditional';
	//Taiwanese Counting System
	public const TAIWANESE_COUNTING = 'taiwaneseCounting';
	//Traditional Legal Ideograph Format
	public const IDEOGRAPH_LEGAL_TRADITIONAL = 'ideographLegalTraditional';
	//Taiwanese Counting Thousand System
	public const TAIWANESE_COUNTING_THOUSAND = 'taiwaneseCountingThousand';
	//Taiwanese Digital Counting System
	public const TAIWANESE_DIGITAL = 'taiwaneseDigital';
	//Chinese Counting System
	public const CHINESE_COUNTING = 'chineseCounting';
	//Chinese Legal Simplified Format
	public const CHINESE_LEGAL_SIMPLIFIED = 'chineseLegalSimplified';
	//Chinese Counting Thousand System
	public const CHINESE_COUNTING_THOUSAND = 'chineseCountingThousand';
	//Korean Digital Counting System
	public const KOREAN_DIGITAL = 'koreanDigital';
	//Korean Counting System
	public const KOREAN_COUNTING = 'koreanCounting';
	//Korean Legal Numbering
	public const KOREAN_LEGAL = 'koreanLegal';
	//Korean Digital Counting System Alternate
	public const KOREAN_DIGITAL2 = 'koreanDigital2';
	//Vietnamese Numerals
	public const VIETNAMESE_COUNTING = 'vietnameseCounting';
	//Lowercase Russian Alphabet
	public const RUSSIAN_LOWER = 'russianLower';
	//Uppercase Russian Alphabet
	public const RUSSIAN_UPPER = 'russianUpper';
	//No Numbering
	public const NONE = 'none';
	//Number With Dashes
	public const NUMBER_IN_DASH = 'numberInDash';
	//Hebrew Numerals
	public const HEBREW1 = 'hebrew1';
	//Hebrew Alphabet
	public const HEBREW2 = 'hebrew2';
	//Arabic Alphabet
	public const ARABIC_ALPHA = 'arabicAlpha';
	//Arabic Abjad Numerals
	public const ARABIC_ABJAD = 'arabicAbjad';
	//Hindi Vowels
	public const HINDI_VOWELS = 'hindiVowels';
	//Hindi Consonants
	public const HINDI_CONSONANTS = 'hindiConsonants';
	//Hindi Numbers
	public const HINDI_NUMBERS = 'hindiNumbers';
	//Hindi Counting System
	public const HINDI_COUNTING = 'hindiCounting';
	//Thai Letters
	public const THAI_LETTERS = 'thaiLetters';
	//Thai Numerals
	public const THAI_NUMBERS = 'thaiNumbers';
	//Thai Counting System
	public const THAI_COUNTING = 'thaiCounting';
}
