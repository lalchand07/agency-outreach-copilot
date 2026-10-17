<?php

declare(strict_types=1);

namespace App\Leads;

use App\Net\HttpClientInterface;
use App\Net\NetException;

/**
 * Google Places API (New) Text Search, with the user's own API key.
 *
 * POST https://places.googleapis.com/v1/places:searchText
 * Headers: X-Goog-Api-Key, X-Goog-FieldMask. Body: textQuery, pageSize (1-20),
 * pageToken, languageCode, regionCode. At most 60 results across pages.
 *
 * Field mask (Text Search Pro SKU + Enterprise for websiteUri):
 *   places.id, places.displayName, places.formattedAddress, places.websiteUri,
 *   places.businessStatus, places.googleMapsUri, places.primaryTypeDisplayName, nextPageToken
 *
 * Storage (Places API policies): only the place ID may be stored indefinitely.
 * Everything else returned here is displayed transiently and never written to
 * the database; see LeadSourcing::addFromPlaces() for what we store instead.
 */
final class PlacesClient
{
    public const ENDPOINT = 'https://places.googleapis.com/v1/places:searchText';
    public const FIELD_MASK = 'places.id,places.displayName,places.formattedAddress,places.websiteUri,places.businessStatus,places.googleMapsUri,places.primaryTypeDisplayName,nextPageToken';

    public function __construct(private HttpClientInterface $http, private string $apiKey)
    {
    }

    public function isConfigured(): bool
    {
        return $this->apiKey !== '';
    }

    /**
     * @return array{places: list<array{id: string, name: string, address: string, website: string, status: string, maps_url: string, type: string}>, next_page_token: string|null, error: string|null}
     */
    public function textSearch(string $query, string $regionCode = '', string $languageCode = 'en', ?string $pageToken = null, int $pageSize = 20): array
    {
        if (!$this->isConfigured()) {
            return ['places' => [], 'next_page_token' => null, 'error' => 'Set GOOGLE_PLACES_API_KEY in .env to search Google Places.'];
        }
        $body = ['textQuery' => $query, 'pageSize' => max(1, min(20, $pageSize)), 'languageCode' => $languageCode];
        if (preg_match('/^[A-Z]{2}$/', strtoupper($regionCode))) {
            $body['regionCode'] = strtoupper($regionCode);
        }
        if ($pageToken !== null && $pageToken !== '') {
            $body['pageToken'] = $pageToken;
        }
        try {
            $response = $this->http->request('POST', self::ENDPOINT, [
                'Content-Type' => 'application/json',
                'X-Goog-Api-Key' => $this->apiKey,
                'X-Goog-FieldMask' => self::FIELD_MASK,
            ], (string) json_encode($body), 15.0);
        } catch (NetException $e) {
            return ['places' => [], 'next_page_token' => null, 'error' => 'Google Places request failed: ' . $e->getMessage()];
        }
        $data = $response->json() ?? [];
        if ($response->status !== 200) {
            $message = is_array($data['error'] ?? null) ? (string) ($data['error']['message'] ?? '') : '';

            return ['places' => [], 'next_page_token' => null, 'error' => sprintf('Google Places returned HTTP %d%s', $response->status, $message !== '' ? ': ' . $message : '')];
        }
        $places = [];
        foreach ((array) ($data['places'] ?? []) as $place) {
            if (!is_array($place) || !isset($place['id'])) {
                continue;
            }
            $places[] = [
                'id' => (string) $place['id'],
                'name' => (string) ($place['displayName']['text'] ?? ''),
                'address' => (string) ($place['formattedAddress'] ?? ''),
                'website' => (string) ($place['websiteUri'] ?? ''),
                'status' => (string) ($place['businessStatus'] ?? ''),
                'maps_url' => (string) ($place['googleMapsUri'] ?? ''),
                'type' => (string) ($place['primaryTypeDisplayName']['text'] ?? ''),
            ];
        }

        return ['places' => $places, 'next_page_token' => isset($data['nextPageToken']) ? (string) $data['nextPageToken'] : null, 'error' => null];
    }

    /** ISO 3166 region code for common campaign countries. */
    public static function regionCode(string $country): string
    {
        $map = ['UK' => 'GB', 'UNITED KINGDOM' => 'GB', 'ENGLAND' => 'GB', 'PAKISTAN' => 'PK', 'UAE' => 'AE', 'UNITED ARAB EMIRATES' => 'AE', 'SAUDI ARABIA' => 'SA', 'KSA' => 'SA', 'QATAR' => 'QA', 'KUWAIT' => 'KW', 'BAHRAIN' => 'BH', 'OMAN' => 'OM'];
        $key = strtoupper(trim($country));

        return $map[$key] ?? (preg_match('/^[A-Z]{2}$/', $key) ? $key : '');
    }
}
