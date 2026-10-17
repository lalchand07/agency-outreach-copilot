<?php

declare(strict_types=1);

namespace App\Outreach;

use App\Config\ProductProfile;
use App\Mail\Email;

/**
 * Adds the compliance footer to every email: who is sending, their physical
 * address, why the recipient is getting it and a unique unsubscribe link.
 */
final class EmailComposer
{
    public const FOOTER_TEXT = [
        'en' => ['why' => 'You are receiving this one-off message because %s is publicly listed as a business contact on your website.', 'unsubscribe' => 'Not interested? Unsubscribe and we will not contact you again: %s'],
        'ur' => ['why' => 'آپ کو یہ پیغام اس لیے ملا ہے کیونکہ %s آپ کی ویب سائٹ پر کاروباری رابطے کے طور پر درج ہے۔', 'unsubscribe' => 'دلچسپی نہیں؟ یہاں کلک کریں، ہم دوبارہ رابطہ نہیں کریں گے: %s'],
        'ar' => ['why' => 'تصلك هذه الرسالة لأن %s مدرج علنًا كجهة اتصال تجارية على موقعكم.', 'unsubscribe' => 'غير مهتم؟ ألغِ الاشتراك ولن نتواصل معك مرة أخرى: %s'],
    ];

    public function __construct(private ProductProfile $product, private string $appUrl, private string $replyTo = '')
    {
    }

    public function unsubscribeUrl(string $token): string
    {
        return rtrim($this->appUrl, '/') . '/u/' . rawurlencode($token);
    }

    public function footer(string $recipient, string $token, string $language = 'en'): string
    {
        $text = self::FOOTER_TEXT[$language] ?? self::FOOTER_TEXT['en'];
        $identity = array_filter([
            trim($this->product->sender('name') . ($this->product->sender('title') !== '' ? ', ' . $this->product->sender('title') : '')),
            $this->product->sender('company'),
            $this->product->sender('address'),
            implode(' · ', array_filter([$this->product->sender('website'), $this->product->sender('phone')])),
        ]);
        $lines = ['--', ...$identity, '', sprintf($text['why'], $recipient)];
        if ($language !== 'en') {
            $lines[] = sprintf(self::FOOTER_TEXT['en']['why'], $recipient);
        }
        $lines[] = sprintf($text['unsubscribe'], $this->unsubscribeUrl($token));

        return implode("\n", $lines);
    }

    public function composeBody(string $body, string $recipient, string $token, string $language = 'en'): string
    {
        return rtrim($body) . "\n\n" . $this->footer($recipient, $token, $language);
    }

    public function toEmail(string $recipient, string $subject, string $fullBody, string $token): Email
    {
        $replyTo = $this->replyTo !== '' ? $this->replyTo : $this->product->sender('email');

        return new Email(
            $recipient,
            $subject,
            $fullBody,
            $this->product->sender('email'),
            trim($this->product->sender('name') . ($this->product->sender('company') !== '' ? ' (' . $this->product->sender('company') . ')' : '')),
            $replyTo,
            [
                // RFC 2369 + RFC 8058 one-click unsubscribe.
                'List-Unsubscribe' => '<' . $this->unsubscribeUrl($token) . '>, <mailto:' . $replyTo . '?subject=unsubscribe>',
                'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click',
            ]
        );
    }
}
