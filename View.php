<?php
declare(strict_types=1);

namespace App\Core;

final class View
{
    /** Renders views/<name>.php inside a layout (null for a bare fragment). */
    public static function render(string $name, array $data = [], ?string $layout = 'layouts/main'): string
    {
        $content = self::capture($name, $data);
        if ($layout === null) {
            return $content;
        }
        return self::capture($layout, $data + ['content' => $content]);
    }

    public static function partial(string $name, array $data = []): string
    {
        return self::capture('partials/' . $name, $data);
    }

    private static function capture(string $name, array $data): string
    {
        $file = ROOT . '/views/' . $name . '.php';
        if (!is_file($file)) {
            throw new \RuntimeException("View not found: $name");
        }
        extract($data, EXTR_SKIP);
        ob_start();
        try {
            require $file;
        } catch (\Throwable $e) {
            ob_end_clean();
            throw $e;
        }
        return (string)ob_get_clean();
    }
}
