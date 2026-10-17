<?php

declare(strict_types=1);

namespace App\Research;

/**
 * One fetched page, reduced to what research needs.
 */
final class Page
{
    /**
     * @param list<array{href: string, text: string}> $links absolute URLs
     * @param list<array{text: string, fields: int, dates: int}> $forms
     */
    public function __construct(
        public readonly string $url,
        public readonly string $title,
        public readonly string $description,
        public readonly string $text,
        public readonly array $links = [],
        public readonly array $forms = [],
        public readonly string $lang = '',
        public readonly string $html = '',
    ) {
    }

    /** @return array{url: string, title: string} */
    public function summary(): array
    {
        return ['url' => $this->url, 'title' => $this->title];
    }
}
