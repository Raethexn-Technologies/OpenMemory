<?php

namespace App\Services\Context;

class ContextExcerpt
{
    public static function select(string $text, array $terms): string
    {
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
        $start = 0;
        if (mb_strlen($text) > 600) {
            foreach ($terms as $term) {
                $position = mb_stripos($text, $term);
                if ($position !== false) {
                    $start = max(0, $position - 200);
                    break;
                }
            }
        }

        return mb_substr($text, $start, 600);
    }
}
