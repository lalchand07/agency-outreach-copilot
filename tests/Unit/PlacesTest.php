<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Leads\PlacesClient;
use App\Tests\Support\TestCase;

final class PlacesTest extends TestCase
{
    private function places(): PlacesClient
    {
        return new PlacesClient($this->web, 'test-places-key');
    }

    public function testTextSearchRequestFollowsTheOfficialApi(): void
    {
        $this->web->json(PlacesClient::ENDPOINT, ['places' => [
            ['id' => 'ChIJ0000000001', 'displayName' => ['text' => 'Crescent Travel', 'languageCode' => 'en'], 'formattedAddress' => '1 Road, London', 'websiteUri' => 'https://crescent.example/', 'businessStatus' => 'OPERATIONAL', 'googleMapsUri' => 'https://maps.google.com/?cid=1', 'primaryTypeDisplayName' => ['text' => 'Travel Agency']],
            ['id' => 'ChIJ0000000002', 'displayName' => ['text' => 'No Site Tours']],
        ], 'nextPageToken' => 'NEXT']);

        $result = $this->places()->textSearch('Umrah travel agency in London', 'GB');

        $request = $this->web->requests[0];
        self::assertSame('POST', $request['method']);
        self::assertSame('https://places.googleapis.com/v1/places:searchText', $request['url']);
        self::assertSame('test-places-key', $request['headers']['X-Goog-Api-Key']);
        self::assertSame(PlacesClient::FIELD_MASK, $request['headers']['X-Goog-FieldMask']);
        self::assertStringNotContainsString('test-places-key', $request['url'], 'the key never goes in the URL');
        self::assertSame(['textQuery' => 'Umrah travel agency in London', 'pageSize' => 20, 'languageCode' => 'en', 'regionCode' => 'GB'], json_decode($request['body'], true));

        self::assertNull($result['error']);
        self::assertSame('NEXT', $result['next_page_token']);
        self::assertSame('Crescent Travel', $result['places'][0]['name']);
        self::assertSame('https://crescent.example/', $result['places'][0]['website']);
        self::assertSame('', $result['places'][1]['website']);
    }

    public function testFieldMaskAsksOnlyForWhatWeShow(): void
    {
        $fields = explode(',', PlacesClient::FIELD_MASK);
        foreach (['places.nationalPhoneNumber', 'places.internationalPhoneNumber', 'places.rating', 'places.reviews', 'places.photos'] as $unwanted) {
            self::assertNotContains($unwanted, $fields);
        }
    }

    public function testApiErrorsAreReadable(): void
    {
        $this->web->json(PlacesClient::ENDPOINT, ['error' => ['code' => 403, 'message' => 'API key not valid.']], 403);

        self::assertSame('Google Places returned HTTP 403: API key not valid.', $this->places()->textSearch('x')['error']);
        self::assertStringContainsString('GOOGLE_PLACES_API_KEY', (string) (new PlacesClient($this->web, ''))->textSearch('x')['error']);
    }

    public function testAddingAPlaceStoresOnlyPlaceIdPlusWhatTheBusinessesOwnSiteConfirms(): void
    {
        $this->agencySite();
        $campaign = $this->app->campaigns()->find($this->campaign()) ?? [];

        $report = $this->app->sourcing()->addFromPlaces([
            ['place_id' => 'ChIJ0000000001', 'website' => 'https://crescent.example/?utm_source=google'],
            ['place_id' => 'ChIJ0000000002', 'website' => ''],
        ], $campaign, 'London');

        self::assertSame(1, $report['imported']);
        self::assertCount(1, $report['skipped']);
        $lead = $this->db->one('SELECT * FROM leads') ?? [];
        self::assertSame('ChIJ0000000001', $lead['place_id']);
        self::assertSame('Crescent Travel', $lead['name'], 'from the <title> of the business\'s own home page');
        self::assertSame('https://crescent.example/', $lead['website']);
        self::assertSame('', $lead['phone'], 'Google phone numbers are never stored');
        self::assertSame('London', $lead['city'], 'the city the user searched for');
        self::assertSame('places', $lead['source']);

        $again = $this->app->sourcing()->addFromPlaces([['place_id' => 'ChIJ0000000001', 'website' => 'https://crescent.example/']], $campaign);
        self::assertSame(0, $again['imported']);
        self::assertCount(1, $again['duplicates']);
    }

    public function testRegionCodes(): void
    {
        self::assertSame('GB', PlacesClient::regionCode('United Kingdom'));
        self::assertSame('PK', PlacesClient::regionCode('pakistan'));
        self::assertSame('AE', PlacesClient::regionCode('UAE'));
        self::assertSame('', PlacesClient::regionCode('Atlantis'));
    }
}
