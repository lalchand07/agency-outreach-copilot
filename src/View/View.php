<?php

declare(strict_types=1);

namespace App\View;

/**
 * Renders plain PHP templates from templates/. Templates must escape with e().
 */
final class View
{
    /** @param array<string, mixed> $shared variables available in every template */
    public function __construct(private string $dir, private array $shared = [])
    {
    }

    public function share(string $key, mixed $value): void
    {
        $this->shared[$key] = $value;
    }

    /** @param array<string, mixed> $data */
    public function render(string $template, array $data = [], ?string $layout = 'layout'): string
    {
        $content = $this->partial($template, $data);
        if ($layout === null) {
            return $content;
        }

        return $this->partial($layout, ['content' => $content] + $data);
    }

    /** @param array<string, mixed> $data */
    public function partial(string $template, array $data = []): string
    {
        $file = $this->dir . '/' . $template . '.php';
        if (!preg_match('/^[a-z0-9_\/-]+$/', $template) || !is_file($file)) {
            throw new \RuntimeException('Template not found: ' . $template);
        }
        $view = $this;
        extract($this->shared + $data, EXTR_SKIP);
        ob_start();
        try {
            include $file;
        } finally {
            $output = (string) ob_get_clean();
        }

        return $output;
    }
}
