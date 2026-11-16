<?php
declare(strict_types=1);

namespace Portfolio;

use DateTimeImmutable;
use InvalidArgumentException;

// Extracted from the application; only the namespace and function name changed.
/** A readable download name; the private storage name and PDF bytes stay unchanged. */
function document_filename(string $company, string $title, string $date, int $documentId = 0, bool $deduplicateCompany = true): string
{
    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
    if (!$parsed || $parsed->format('Y-m-d') !== $date || $documentId < 0) {
        throw new InvalidArgumentException('Invalid capture filename metadata.');
    }
    $title = preg_replace('/^Klik her\s*[–—-]?\s*så\s+(?:finder|får)\s+du\s+/iu', '', trim($title)) ?? $title;
    $title = preg_replace('/\.pdf$/iu', '', $title) ?? $title;
    $part = static function (string $value, int $limit, string $fallback): string {
        $value = strtr(strip_tags($value), ['Æ'=>'Ae','æ'=>'ae','Ø'=>'Oe','ø'=>'oe','Å'=>'Aa','å'=>'aa']);
        $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
        $value = strtolower($ascii === false ? $value : $ascii);
        $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?? '';
        return trim(substr($value, 0, $limit), '-') ?: $fallback;
    };
    $companyPart = $part($company, PHP_INT_MAX, 'virksomhed');
    $titlePart = $part($title, PHP_INT_MAX, 'webdokument');
    if ($deduplicateCompany) {
        // Match whole leading/trailing names, including the name without a legal suffix.
        // Keep company mentions inside a title: they may describe comparisons or products.
        $brand = preg_replace('/(?:-(?:inc|incorporated|corporation|corp|aps|a-s|as|ltd|limited|llc|gmbh))+$/', '', $companyPart);
        foreach (array_unique([$companyPart, $brand]) as $alias) {
            if (strlen($alias) < 3) continue;
            if ($titlePart === $alias) $titlePart = '';
            elseif (str_starts_with($titlePart, $alias . '-')) $titlePart = substr($titlePart, strlen($alias) + 1);
            if (str_ends_with($titlePart, '-for-' . $alias)) $titlePart = substr($titlePart, 0, -strlen('-for-' . $alias));
            elseif (str_ends_with($titlePart, '-' . $alias)) $titlePart = substr($titlePart, 0, -strlen('-' . $alias));
        }
    }
    return (trim(substr($companyPart, 0, 65), '-') ?: 'virksomhed') . '-'
        . (trim(substr($titlePart, 0, 95), '-') ?: 'webdokument')
        . '-' . $date . ($documentId > 0 ? '-dokument-' . $documentId : '-forhaandsvisning') . '.pdf';
}

