<?php

declare(strict_types=1);

namespace App\Research;

/**
 * Turns HTML into visible text, links and form information with DOMDocument.
 */
final class PageExtractor
{
    public const MAX_TEXT = 8000;

    public function extract(string $url, string $html): Page
    {
        $doc = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        // The XML encoding hint makes libxml treat the input as UTF-8.
        $doc->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NONET | LIBXML_NOWARNING | LIBXML_NOERROR);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $xpath = new \DOMXPath($doc);
        $title = self::clean($xpath->evaluate('string(//title)'));
        $description = self::clean($xpath->evaluate('string(//meta[translate(@name,"DESCRIPTION","description")="description"]/@content)'));
        $lang = strtolower(self::clean($xpath->evaluate('string(//html/@lang)')));
        $base = $url;
        $baseHref = self::clean($xpath->evaluate('string(//base/@href)'));
        if ($baseHref !== '') {
            $base = self::resolve($url, $baseHref) ?? $url;
        }

        $links = [];
        foreach ($xpath->query('//a[@href]') ?: [] as $a) {
            if (!$a instanceof \DOMElement) {
                continue;
            }
            $href = self::resolve($base, trim($a->getAttribute('href')));
            if ($href !== null) {
                $links[] = ['href' => $href, 'text' => mb_substr(self::clean($a->textContent . ' ' . $a->getAttribute('title') . ' ' . $a->getAttribute('aria-label')), 0, 120)];
            }
        }

        $forms = [];
        foreach ($xpath->query('//form') ?: [] as $form) {
            if (!$form instanceof \DOMElement) {
                continue;
            }
            $fields = 0;
            $dates = 0;
            $labels = [];
            foreach ($xpath->query('.//input|.//select|.//textarea', $form) ?: [] as $field) {
                if (!$field instanceof \DOMElement) {
                    continue;
                }
                $type = strtolower($field->getAttribute('type'));
                if (in_array($type, ['hidden', 'submit', 'button', 'image', 'reset'], true)) {
                    continue;
                }
                $fields++;
                $hint = strtolower($field->getAttribute('name') . ' ' . $field->getAttribute('id') . ' ' . $field->getAttribute('placeholder'));
                if ($type === 'date' || preg_match('/depart|return|check.?in|check.?out|travel.?date/', $hint)) {
                    $dates++;
                }
                $labels[] = $hint;
            }
            $forms[] = [
                'text' => mb_substr(self::clean($form->textContent . ' ' . implode(' ', $labels)), 0, 300),
                'fields' => $fields,
                'dates' => $dates,
            ];
        }

        foreach (['script', 'style', 'noscript', 'svg', 'template', 'iframe', 'head'] as $tag) {
            foreach (iterator_to_array($doc->getElementsByTagName($tag)) as $node) {
                $node->parentNode?->removeChild($node);
            }
        }
        // Put line breaks around block elements so words do not run together.
        foreach (['p', 'div', 'li', 'br', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'tr', 'section', 'article', 'header', 'footer', 'nav', 'td', 'th', 'a', 'span', 'button'] as $tag) {
            foreach (iterator_to_array($doc->getElementsByTagName($tag)) as $node) {
                $node->parentNode?->insertBefore($doc->createTextNode(' '), $node);
                $node->appendChild($doc->createTextNode(' '));
            }
        }
        $body = $doc->getElementsByTagName('body')->item(0);
        $text = self::clean($body !== null ? $body->textContent : $doc->textContent);

        return new Page($url, mb_substr($title, 0, 200), mb_substr($description, 0, 300), mb_substr($text, 0, self::MAX_TEXT), $links, $forms, $lang, $html);
    }

    public static function clean(mixed $text): string
    {
        $text = is_string($text) ? $text : '';
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = (string) preg_replace('/[\x{00A0}\s]+/u', ' ', $text);

        return trim($text);
    }

    /** Resolves a link against the page URL; returns null for non-http(s) links and fragments. */
    public static function resolve(string $base, string $href): ?string
    {
        if ($href === '' || $href[0] === '#') {
            return null;
        }
        if (preg_match('#^(mailto|tel|javascript|data|whatsapp|sms):#i', $href)) {
            return preg_match('#^(mailto|tel|whatsapp):#i', $href) ? $href : null;
        }
        $baseParts = parse_url($base);
        if (!is_array($baseParts) || !isset($baseParts['scheme'], $baseParts['host'])) {
            return null;
        }
        $origin = $baseParts['scheme'] . '://' . $baseParts['host'] . (isset($baseParts['port']) ? ':' . $baseParts['port'] : '');
        if (str_starts_with($href, '//')) {
            $href = $baseParts['scheme'] . ':' . $href;
        } elseif (!preg_match('#^[a-z][a-z0-9+.-]*:#i', $href)) {
            if ($href[0] === '/') {
                $href = $origin . $href;
            } else {
                $dir = isset($baseParts['path']) ? (string) preg_replace('#/[^/]*$#', '/', $baseParts['path']) : '/';
                if ($href[0] === '?') {
                    $href = $origin . ($baseParts['path'] ?? '/') . $href;
                } else {
                    $href = $origin . $dir . $href;
                }
            }
        }
        if (!preg_match('#^https?://#i', $href)) {
            return null;
        }
        $href = (string) preg_replace('/#.*$/', '', $href);
        // Collapse "/./" and "/x/../" segments.
        $parts = parse_url($href);
        if (!is_array($parts) || !isset($parts['host'])) {
            return null;
        }
        $segments = [];
        foreach (explode('/', $parts['path'] ?? '/') as $segment) {
            if ($segment === '..') {
                array_pop($segments);
            } elseif ($segment !== '.') {
                $segments[] = $segment;
            }
        }
        $path = implode('/', $segments);
        if ($path === '' || $path[0] !== '/') {
            $path = '/' . $path;
        }

        return strtolower($parts['scheme'] ?? 'http') . '://' . strtolower($parts['host']) . (isset($parts['port']) ? ':' . $parts['port'] : '') . $path . (isset($parts['query']) ? '?' . $parts['query'] : '');
    }
}
