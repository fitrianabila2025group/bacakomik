<?php
namespace App\Services\Scraper;

/**
 * Returns the active scraper implementation:
 *   - {@see ApiScraper} when the admin enabled "Use Remote Scraper API".
 *   - {@see KomikuScraper} (in-process curl + DOMXPath) otherwise.
 */
class ScraperFactory
{
    public static function make(array $config = []): KomikuScraper
    {
        if (ApiClient::isEnabled()) {
            return new ApiScraper($config);
        }
        return new KomikuScraper($config);
    }
}
