<?php
declare(strict_types=1);

namespace Portfolio;

// Extracted rendering logic. The translation hook is omitted and the application's
// escaping helper is replaced by the equivalent local helper below.
// Both callbacks are trusted application code, never request-provided HTML.
function escape_html(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function data_field_name(string $prefix, string $name): string
{
    return $prefix !== '' ? $prefix . '_' . $name : $name;
}

function render_data_options(array $items, string $name, callable $get, callable $checked, bool $hierarchical = false): string
{
    $html = '<div class="dpa-compact-check-grid">';

    foreach ($items as $item) {
        $label = $hierarchical ? ltrim((string)$item, '- ') : (string)$item;
        $class = '';
        if ($hierarchical) {
            $class = (string)$item !== '' && (string)$item[0] !== '-' ? ' parent' : ' child';
        }

        $html .= '<div class="checkbox-item' . $class . '"><label><input type="checkbox" name="' . escape_html($name) . '[]" value="' . escape_html($item) . '" ' . $checked($get($name), $item) . '> ' . escape_html($label) . '</label></div>';
    }

    return $html . '</div>';
}

