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
_IMG_CACHE_DIR = os.path.join(_CACHE_DIR, "img")
os.makedirs(_CACHE_DIR, exist_ok=True)
os.makedirs(_IMG_CACHE_DIR, exist_ok=True)

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

    Strategy:
      1. Disk-cache by URL hash on Railway -> ulang request gambar yg sama tidak
         mengetuk komiku/Cloudflare lagi (anti-403, hemat bandwidth).
      2. Browser-like headers (Sec-Fetch-*, Accept, Accept-Language) supaya
         hotlink protection lebih jarang menolak.
      3. Bila 403/429: warm-up sesi dgn GET ke referer (bawa cookie CF), retry.
    """
    if not host_allowed(url):
        raise PermissionError(f"Host tidak ada di whitelist: {url}")

    # 1) disk cache
    h = hashlib.md5(url.encode()).hexdigest()
    bin_path = os.path.join(_IMG_CACHE_DIR, h + ".bin")
    ct_path = os.path.join(_IMG_CACHE_DIR, h + ".ct")
    if os.path.isfile(bin_path) and os.path.isfile(ct_path):
        try:
            with open(bin_path, "rb") as f:
                body = f.read()
            with open(ct_path, "r", encoding="utf-8") as f:
                ctype = (f.read().strip() or "application/octet-stream")
            if body:
                return body, ctype
        except OSError:
            pass

    s = get_settings()
    sess = _get_request_session()
    headers = {
        "Accept": "image/avif,image/webp,image/apng,image/svg+xml,image/*,*/*;q=0.8",
        "Accept-Language": "en-US,en;q=0.9,id;q=0.8",
        "Accept-Encoding": "gzip, deflate, br",
        "Sec-Fetch-Dest": "image",
        "Sec-Fetch-Mode": "no-cors",
        "Sec-Fetch-Site": "cross-site",
        "Cache-Control": "no-cache",
    }
    # Default referer: pakai homepage host gambar -> sering lolos hotlink check.
    if not referer:
        host = urlparse(url).hostname or ""
        if host:
            # turunkan ke domain root (komiku.org untuk thumbnail.komiku.org)
            parts = host.split(".")
            root = ".".join(parts[-2:]) if len(parts) >= 2 else host
            referer = f"https://{root}/"
    headers["Referer"] = referer

    last_err: Optional[Exception] = None
    for attempt in range(1, 4):
        try:
            resp = sess.get(url, headers=headers, timeout=s.timeout)
            if 200 <= resp.status_code < 300 and resp.content:
                body = resp.content
                ctype = resp.headers.get("Content-Type", "application/octet-stream")
                # tulis disk cache (best effort)
                try:
                    with open(bin_path, "wb") as f:
                        f.write(body)
                    with open(ct_path, "w", encoding="utf-8") as f:
                        f.write(ctype)
                except OSError:
                    pass
                return body, ctype
            # Hotlink / CF block -> warm-up via referer page lalu retry.
            if resp.status_code in (401, 403, 429) and attempt < 3:
                try:
                    sess.get(
                        referer,
                        headers={
                            "Accept": "text/html,application/xhtml+xml,*/*;q=0.8",
                            "Accept-Language": headers["Accept-Language"],
                        },
                        timeout=s.timeout,
                    )
                except Exception:  # noqa: BLE001
                    pass
                time.sleep(0.4 * attempt)
                continue
            last_err = RuntimeError(f"HTTP {resp.status_code} fetching {url}")
        except Exception as e:  # noqa: BLE001
            last_err = e
            log.warning("fetch_bytes attempt %s for %s failed: %s", attempt, url, e)
        time.sleep(0.4 * attempt)
    raise last_err or RuntimeError(f"Gagal fetch {url}")
