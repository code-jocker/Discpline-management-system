<?php
declare(strict_types=1);

namespace App\Support;

/**
 * StudentCard — renders the printable landscape ID card (900 x 570 artboard).
 *
 * Single source of truth for the card markup: students/card.php uses it for the
 * print / PDF sheet and for the in-page preview, so a card can never look
 * different on screen from what comes out of the printer.
 */
final class StudentCard
{
    public const WIDTH = 900;
    public const HEIGHT = 570;

    /**
     * @param array<string, mixed>  $student row from Student::find()
     * @param array<string, string> $school  name, address, phone, logo, year
     */
    public static function render(array $student, array $school): string
    {
        $photo = self::photoUrl($student['photo_path'] ?? null);
        $logo  = self::assetUrl($school['logo'] ?? 'assets/img/logo.svg');
        $code  = (string) ($student['student_code'] ?? '');
        $dob   = self::formatDate($student['date_of_birth'] ?? null);

        return '<article class="id-card" role="img" aria-label="' . self::esc('Student card for ' . ($student['full_name'] ?? '')) . '">'
            . self::waves()
            . '<header class="id-card-crest">'
            . '<img class="id-card-logo" src="' . self::esc($logo) . '" alt="">'
            . '<div class="id-card-school">'
            . '<span class="id-card-school-name">' . self::esc($school['name'] ?? '') . '</span>'
            . '<span class="id-card-school-tag">Student Behaviour Management System</span>'
            . '</div>'
            . '</header>'
            . '<div class="id-card-body">'
            . '<div class="id-card-portrait">'
            . ($photo !== null
                ? '<img class="id-card-photo" src="' . self::esc($photo) . '" alt="' . self::esc('Portrait of ' . ($student['full_name'] ?? '')) . '">'
                : '<span class="id-card-photo id-card-photo-empty" aria-hidden="true">' . self::esc(self::initials($student['full_name'] ?? '')) . '</span>')
            . '<div class="id-card-code">'
            . '<span class="id-card-bars" aria-hidden="true">' . self::barcodeBars($code) . '</span>'
            . '<span class="id-card-code-text">' . self::esc($code) . '</span>'
            . '</div>'
            . '</div>'
            . '<div class="id-card-panel">'
            . '<p class="id-card-title">Student Card</p>'
            . self::row('Name', $student['full_name'] ?? '')
            . self::row('Student ID', $code)
            . self::row('Date of Birth', $dob)
            . self::row('Class', $student['class_name'] ?? '')
            . self::row('Academic Year', $school['year'] ?? '')
            . self::row('Address', $student['address'] ?? ($school['address'] ?? ''))
            . '</div>'
            . '</div>'
            . '<footer class="id-card-foot">'
            . '<span>' . self::esc($school['phone'] ?? '') . '</span>'
            . '<span>' . self::esc($school['email'] ?? '') . '</span>'
            . '<span class="id-card-valid">' . self::esc(self::validity($school['year'] ?? '')) . '</span>'
            . '</footer>'
            . '</article>';
    }

    /**
     * Deterministic bar widths derived from the student code. This is a visual
     * reference strip, not a scannable symbology — the code is always printed in
     * human-readable form underneath it.
     */
    private static function barcodeBars(string $code): string
    {
        $seed = 7;
        foreach (str_split($code) as $character) {
            $seed = ($seed * 31 + ord($character)) % 1000003;
        }

        $bars = '';
        for ($index = 0; $index < 44; $index++) {
            $seed = ($seed * 1103515245 + 12345) % 2147483648;
            $width = 1 + $seed % 4;
            $bars .= '<i style="width:' . $width . 'px;opacity:' . ($seed % 2 ? '1' : '.62') . '"></i>';
        }

        return $bars;
    }

    private static function waves(): string
    {
        // Two stacked curves per edge: gold behind, navy in front.
        $gold = '<path d="M0 62C140 14 300 74 470 40 630 8 760 52 900 26V0H0Z" fill="#F5A33F"/>';
        $navy = '<path d="M0 78C150 26 300 88 480 52 650 18 780 66 900 38V0H0Z" fill="#080747"/>';

        return '<svg class="id-card-wave id-card-wave-top" viewBox="0 0 900 90" preserveAspectRatio="none" aria-hidden="true">'
            . $gold . $navy . '</svg>'
            . '<svg class="id-card-wave id-card-wave-bottom" viewBox="0 0 900 90" preserveAspectRatio="none" aria-hidden="true">'
            . $gold . $navy . '</svg>';
    }

    private static function row(string $label, $value): string
    {
        $value = trim((string) $value);

        return '<div class="id-card-row">'
            . '<span class="id-card-label">' . self::esc($label) . '</span>'
            . '<span class="id-card-colon">:</span>'
            . '<span class="id-card-value">' . self::esc($value !== '' ? $value : '—') . '</span>'
            . '</div>';
    }

    private static function formatDate(?string $value): string
    {
        if (!is_string($value) || $value === '' || !preg_match('/^\d{4}-\d{2}-\d{2}/', $value)) {
            return '';
        }

        $timestamp = strtotime($value);
        if ($timestamp === false) {
            return '';
        }

        return date('j/n/Y', $timestamp);
    }

    private static function validity(string $year): string
    {
        if ($year === '') {
            return '';
        }

        $parts = preg_split('/\s*-\s*/', $year) ?: [];
        $last = end($parts);

        return is_string($last) && ctype_digit($last) ? 'Valid until 31/12/' . $last : 'Academic year ' . $year;
    }

    private static function initials(string $name): string
    {
        $parts = array_values(array_filter(preg_split('/\s+/', trim($name)) ?: []));
        if (!$parts) {
            return '—';
        }

        $last = count($parts) > 1 ? (string) end($parts) : '';

        return mb_strtoupper(mb_substr((string) $parts[0], 0, 1) . mb_substr($last, 0, 1));
    }

    private static function photoUrl($path): ?string
    {
        if (!is_string($path) || trim($path) === '') {
            return null;
        }

        return self::assetUrl('storage/uploads/' . ltrim($path, '/'));
    }

    private static function assetUrl(string $path): string
    {
        $path = ltrim($path, '/');
        if (preg_match('#^https?://#i', $path) === 1) {
            return $path;
        }

        return rtrim(function_exists('app_base_url') ? (string) app_base_url() : '', '/') . '/' . $path;
    }

    private static function esc(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
