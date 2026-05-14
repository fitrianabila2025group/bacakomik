"""
Cloudflare-bypassing fetcher.

Two backends:
  * "request" -> botasaurus_requests.Session (lightweight, no headless browser).
  * "browser" -> Chrome via botasaurus.browser (heavier, CF JS-challenge).

A small filesystem cache keyed by URL avoids hammering origin during testing.
"""
from __future__ import annotations

import hashlib
import logging
import os
import threading
import time
from typing import Optional, Tuple
from urllib.parse import urlparse

from .config import get_settings

log = logging.getLogger("fetcher")

_CACHE_DIR = os.path.join(os.path.dirname(os.path.dirname(__file__)), "cache")
os.makedirs(_CACHE_DIR, exist_ok=True)

_session = None  # botasaurus_requests session (lazy)
_session_lock = threading.Lock()


def _cache_key(url: str) -> str:
    return os.path.join(_CACHE_DIR, hashlib.md5(url.encode()).hexdigest() + ".html")


def _read_cache(url: str) -> Optional[str]:
    s = get_settings()
    if s.cache_ttl <= 0:
        return None
    path = _cache_key(url)
    if not os.path.isfile(path):
        return None
    if (time.time() - os.path.getmtime(path)) > s.cache_ttl:
        return None
    try:
        with open(path, "r", encoding="utf-8", errors="replace") as f:
            return f.read()
    except OSError:
        return None


def _write_cache(url: str, body: str) -> None:
    s = get_settings()
    if s.cache_ttl <= 0:
        return
    try:
        with open(_cache_key(url), "w", encoding="utf-8", errors="replace") as f:
            f.write(body)
    except OSError:
        pass


def host_allowed(url: str) -> bool:
    s = get_settings()
    host = urlparse(url).hostname or ""
    for w in s.whitelist:
        if host == w or host.endswith("." + w):
            return True
    return False


def _get_request_session():
    """Lazy-create a botasaurus_requests Session with CF bypass enabled."""
    global _session
    with _session_lock:
        if _session is None:
            from botasaurus_requests import Session  # type: ignore

            _session = Session(browser="chrome", os="lin")
        return _session


def _fetch_request(url: str, referer: Optional[str] = None) -> Tuple[str, int]:
    s = get_settings()
    sess = _get_request_session()
    headers = {
        "Accept": "text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8",
        "Accept-Language": "en-US,en;q=0.9,id;q=0.8",
    }
    if referer:
        headers["Referer"] = referer
    resp = sess.get(url, headers=headers, timeout=s.timeout)
    return resp.text, resp.status_code


def _fetch_browser(url: str, referer: Optional[str] = None) -> Tuple[str, int]:
    """Use botasaurus.browser (Chromium) to render and bypass CF JS-challenge."""
    from botasaurus.browser import browser, Driver  # type: ignore

    s = get_settings()

    @browser(
        headless=True,
        block_images=True,
        reuse_driver=True,
        wait_for_complete_page_load=False,
    )
    def _scrape(driver: "Driver", data):
        target = data["url"]
        driver.google_get(target, bypass_cloudflare=True)
        # Give CF a beat to settle if it injected a challenge
        try:
            driver.short_random_sleep()
        except Exception:
            pass
        return driver.page_html

    html = _scrape({"url": url})
    return html, 200


def fetch_html(url: str, referer: Optional[str] = None, *, force: bool = False) -> str:
    """Fetch a URL with Cloudflare bypass; cached when configured."""
    if not host_allowed(url):
        raise PermissionError(f"Host tidak ada di whitelist: {url}")

    if not force:
        cached = _read_cache(url)
        if cached is not None:
            return cached

    s = get_settings()
    last_err: Optional[Exception] = None
    for attempt in range(1, 4):
        try:
            if s.mode == "browser":
                body, code = _fetch_browser(url, referer)
            else:
                body, code = _fetch_request(url, referer)
            if 200 <= code < 400 and body:
                _write_cache(url, body)
                return body
            last_err = RuntimeError(f"HTTP {code}")
        except Exception as e:  # noqa: BLE001
            last_err = e
            log.warning("fetch attempt %s for %s failed: %s", attempt, url, e)
        time.sleep(0.6 * attempt)
    raise RuntimeError(f"Gagal fetch {url}: {last_err}")


def fetch_bytes(url: str, referer: Optional[str] = None) -> Tuple[bytes, str]:
    """
    Fetch raw bytes (for image proxy). Returns (content, content_type).
    Uses the request backend even when MODE=browser (image CDNs rarely need CF bypass).
    """
    if not host_allowed(url):
        raise PermissionError(f"Host tidak ada di whitelist: {url}")

    s = get_settings()
    sess = _get_request_session()
    headers = {
        "Accept": "image/avif,image/webp,image/apng,image/*,*/*;q=0.8",
        "Accept-Language": "en-US,en;q=0.9,id;q=0.8",
    }
    if referer:
        headers["Referer"] = referer
    resp = sess.get(url, headers=headers, timeout=s.timeout)
    if resp.status_code >= 400:
        raise RuntimeError(f"HTTP {resp.status_code} fetching {url}")
    ctype = resp.headers.get("Content-Type", "application/octet-stream")
    return resp.content, ctype
