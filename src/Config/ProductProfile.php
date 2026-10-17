<?php

declare(strict_types=1);

namespace App\Config;

/**
 * The product being sold, loaded from config/product.php (or the example).
 */
final class ProductProfile
{
    /**
     * @param list<string>          $keyBenefits
     * @param list<string>          $proofPoints
     * @param array<string, string> $demoLinks
     * @param array<string, string> $sender
     * @param list<string>          $languages
     */
    public function __construct(
        public readonly string $productName,
        public readonly string $pitch,
        public readonly array $keyBenefits,
        public readonly string $idealCustomer,
        public readonly array $proofPoints,
        public readonly string $offer,
        public readonly array $demoLinks,
        public readonly array $sender,
        public readonly array $languages,
        public readonly bool $isExample = false,
    ) {
    }

    public static function load(string $configDir): self
    {
        $file = $configDir . '/product.php';
        $example = !is_file($file);
        /** @var array<string, mixed> $data */
        $data = require $example ? $configDir . '/product.example.php' : $file;

        return self::fromArray($data, $example);
    }

    /** @param array<string, mixed> $d */
    public static function fromArray(array $d, bool $isExample = false): self
    {
        $strings = static fn (mixed $v): array => array_values(array_map('strval', array_filter((array) $v, 'is_scalar')));
        $map = static function (mixed $v): array {
            $out = [];
            foreach ((array) $v as $k => $item) {
                if (is_scalar($item)) {
                    $out[(string) $k] = (string) $item;
                }
            }

            return $out;
        };

        return new self(
            (string) ($d['product_name'] ?? ''),
            (string) ($d['one_line_pitch'] ?? ''),
            $strings($d['key_benefits'] ?? []),
            (string) ($d['ideal_customer'] ?? ''),
            $strings($d['proof_points'] ?? []),
            (string) ($d['offer'] ?? ''),
            $map($d['demo_links'] ?? []),
            $map($d['sender'] ?? []),
            $strings($d['languages'] ?? ['en']),
            $isExample,
        );
    }

    public function sender(string $key): string
    {
        return trim($this->sender[$key] ?? '');
    }

    /**
     * What is missing before email may be sent (sender identity and a physical address).
     *
     * @return list<string>
     */
    public function missingSenderFields(): array
    {
        $missing = [];
        foreach (['name', 'company', 'email', 'address'] as $field) {
            if ($this->sender($field) === '') {
                $missing[] = $field;
            }
        }

        return $missing;
    }

    /** Plain-text summary used in prompts. */
    public function toPromptText(): string
    {
        $lines = [
            'Product: ' . $this->productName,
            'Pitch: ' . $this->pitch,
            'Key benefits:',
        ];
        foreach ($this->keyBenefits as $b) {
            $lines[] = '- ' . $b;
        }
        if ($this->proofPoints !== []) {
            $lines[] = 'Proof points (true, may be mentioned):';
            foreach ($this->proofPoints as $p) {
                $lines[] = '- ' . $p;
            }
        }
        $lines[] = 'Offer: ' . $this->offer;
        foreach ($this->demoLinks as $label => $url) {
            $lines[] = 'Demo link (' . $label . '): ' . $url;
        }
        $lines[] = 'Sender: ' . trim($this->sender('name') . ', ' . $this->sender('title') . ', ' . $this->sender('company'), ', ');

        return implode("\n", $lines);
    }
}
