"""
High-level scraper combining fetcher + parsers.
"""
from __future__ import annotations

import logging
import time
from concurrent.futures import ThreadPoolExecutor
from typing import List, Optional

from . import parsers
from .config import get_settings
from .fetcher import fetch_html, host_allowed

log = logging.getLogger("scraper")


DEFAULT_LISTING_SEEDS = [
    "https://komiku.org/daftar-komik/",
    "https://komiku.org/pustaka/",
    "https://komiku.org/pustaka/?tipe=manga",
    "https://komiku.org/pustaka/?tipe=manhwa",
    "https://komiku.org/pustaka/?tipe=manhua",
    "https://mangaku.top/komik/",
]
DEFAULT_SITEMAPS = ["https://komiku.org/sitemapL5yutt5/series/"]


def get_comic(url: str) -> dict:
    html = fetch_html(url)
    return parsers.parse_comic_metadata(html, url)


def get_chapters(url: str) -> List[dict]:
    html = fetch_html(url)
    return parsers.parse_chapter_list(html, url)


def get_chapter_images(url: str) -> List[str]:
    html = fetch_html(url)
    return parsers.parse_chapter_images(html, url)


def get_comic_full(url: str, concurrency: int = 6, include_images: bool = True) -> dict:
    """
    One-shot: scrape metadata + chapter list + (optionally) every chapter's
    image URLs concurrently. Membuat shared-hosting hanya butuh 1 HTTP call
    per komik (bukan 1 + 1 + N call) sehingga PHP-FPM worker tidak hang.
    """
    html = fetch_html(url)
    meta = parsers.parse_comic_metadata(html, url)
    chapters = parsers.parse_chapter_list(html, url)

    if include_images and chapters:
        max_workers = max(1, min(int(concurrency or 6), 16))

        def _scrape(ch: dict) -> tuple:
            try:
                imgs = get_chapter_images(ch["url"])
                return ch["url"], imgs, None
            except Exception as e:  # noqa: BLE001
                return ch["url"], [], str(e)

        results: dict = {}
        with ThreadPoolExecutor(max_workers=max_workers) as ex:
            for ch_url, imgs, err in ex.map(_scrape, chapters):
                results[ch_url] = (imgs, err)

        for ch in chapters:
            imgs, err = results.get(ch["url"], ([], "missing"))
            ch["images"] = imgs
            if err:
                ch["error"] = err

    return {"meta": meta, "chapters": chapters}


def discover_via_sitemap(sitemap_urls: Optional[List[str]] = None, max_depth: int = 4) -> List[str]:
    sitemaps = list(sitemap_urls or DEFAULT_SITEMAPS)
    seen_sm: set = set()
    found: set = set()
    depth = 0
    while sitemaps and depth < max_depth:
        depth += 1
        next_round: List[str] = []
        for sm in sitemaps:
            if sm in seen_sm or not host_allowed(sm):
                continue
            seen_sm.add(sm)
            try:
                xml = fetch_html(sm)
            except Exception as e:  # noqa: BLE001
                log.warning("sitemap fetch failed %s: %s", sm, e)
                continue
            children, comics = parsers.parse_sitemap(xml)
            found.update(comics)
            next_round.extend(children)
        sitemaps = next_round
    return sorted(found)


def discover_via_listing(
    seeds: Optional[List[str]] = None,
    max_pages: int = 200,
    max_comics: int = 0,
) -> List[str]:
    seeds = list(seeds or DEFAULT_LISTING_SEEDS)
    found: set = set()
    visited: set = set()
    s = get_settings()

    for seed in seeds:
        if not parsers.whitelist_host(seed, s.whitelist):
            continue
        queue: List[str] = [seed]
        pages = 0
        while queue and pages < max_pages:
            url = queue.pop(0).split("#", 1)[0]
            if url in visited:
                continue
            visited.add(url)
            pages += 1
            try:
                html = fetch_html(url)
            except Exception as e:  # noqa: BLE001
                log.warning("listing fetch failed %s: %s", url, e)
                continue
            details, next_pages = parsers.parse_listing(html, url)
            for d in details:
                if not parsers.whitelist_host(d, s.whitelist):
                    continue
                found.add(d)
                if 0 < max_comics <= len(found):
                    return sorted(found)
            for n in next_pages:
                if parsers.whitelist_host(n, s.whitelist) and n not in visited:
                    queue.append(n)
            time.sleep(0.2)
    return sorted(found)


def discover(
    seeds: Optional[List[str]] = None,
    sitemap_urls: Optional[List[str]] = None,
    max_pages: int = 200,
    max_comics: int = 0,
) -> List[str]:
    """Sitemap-first, fallback to listing crawl. Mirrors PHP crawlSite()."""
    urls: List[str] = []
    if not seeds:
        try:
            urls = discover_via_sitemap(sitemap_urls)
        except Exception as e:  # noqa: BLE001
            log.warning("sitemap discovery failed: %s", e)
    if len(urls) < 5:
        listing = discover_via_listing(seeds, max_pages=max_pages, max_comics=max_comics)
        urls = sorted(set(urls).union(listing))
    if 0 < max_comics < len(urls):
        urls = urls[:max_comics]
    return urls
