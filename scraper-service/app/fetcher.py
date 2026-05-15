"""
Cloudflare-bypassing fetcher.

Backends:
  * "request"   -> curl_cffi.requests.Session(impersonate="chrome131")
                   (DEFAULT) — TLS-fingerprint Chrome, jauh lebih stabil
                   dari botasaurus_requests.
  * "botasaurus"-> botasaurus_requests.Session (kalau curl_cffi ditolak).
  * "browser"   -> Chrome via botasaurus.browser (heavyweight, CF JS-challenge).

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

# curl_cffi.Session is NOT thread-safe; gunakan satu session per thread.
_thread_local = threading.local()
_session_lock = threading.Lock()
_session_kind: Optional[str] = None  # "curl_cffi" | "botasaurus" (resolved sekali)
_logged_session_kind = False


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


def _resolve_session_kind() -> str:
    """Decide which library to use (process-wide). Logged once."""
    global _session_kind, _logged_session_kind
    if _session_kind is not None:
        return _session_kind
    with _session_lock:
        if _session_kind is not None:
            return _session_kind
        s = get_settings()
        if s.mode != "botasaurus":
            try:
                import curl_cffi  # type: ignore  # noqa: F401
                _session_kind = "curl_cffi"
            except Exception as e:  # noqa: BLE001
                log.warning("curl_cffi tidak tersedia (%s) — fallback ke botasaurus", e)
                _session_kind = "botasaurus"
        else:
            _session_kind = "botasaurus"
        if not _logged_session_kind:
            log.info("HTTP session backend: %s", _session_kind)
            _logged_session_kind = True
        return _session_kind


def _get_request_session():
    """
    Return a session bound to the *current thread*.

    curl_cffi (libcurl) handles are NOT safe to share across threads —
    melakukannya menyebabkan crash diam2/'connection reset' yang muncul
    sebagai 502 di /proxy ketika browser request banyak gambar paralel.
    """
    kind = _resolve_session_kind()
    sess = getattr(_thread_local, "session", None)
    sess_kind = getattr(_thread_local, "kind", None)
    if sess is not None and sess_kind == kind:
        return sess
    if kind == "curl_cffi":
        from curl_cffi import requests as cc_requests  # type: ignore
        sess = cc_requests.Session(impersonate="chrome131")
    else:
        from botasaurus_requests import Session  # type: ignore
        sess = Session(browser="chrome", os="lin")
    _thread_local.session = sess
    _thread_local.kind = kind
    return sess


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
    # Minimal headers — biarkan curl_cffi/chrome131 inject Sec-Fetch-*,
    # User-Agent, sec-ch-ua, dst sesuai sidik jari Chrome asli.
    # Override manual sebelumnya bertabrakan dgn impersonation -> 403.
    headers = {
        "Accept": "image/avif,image/webp,image/apng,image/svg+xml,image/*,*/*;q=0.8",
        "Accept-Language": "en-US,en;q=0.9,id;q=0.8",
    }
    img_host = urlparse(url).hostname or ""
    img_host_root = f"https://{img_host}/" if img_host else None

    # Bangun daftar kandidat Referer. Komiku sering menolak referer chapter URL
    # tertentu (pattern blacklist) tapi terima referer homepage. Cycle melalui
    # beberapa kandidat supaya hotlink check yg ketat tetap lolos.
    ref_candidates: list[Optional[str]] = []
    if referer:
        ref_candidates.append(referer)
    # Homepage komiku.id + komiku.org (urutan ini cocok utk img.komiku.org).
    for root in ("https://komiku.id/", "https://komiku.org/"):
        if root not in ref_candidates:
            ref_candidates.append(root)
    # Root host gambar.
    if img_host_root and img_host_root not in ref_candidates:
        ref_candidates.append(img_host_root)
    # Terakhir: tanpa Referer sama sekali (bbrp CDN justru izinkan no-referer).
    ref_candidates.append(None)

    last_err: Optional[Exception] = None
    max_attempts = max(3, len(ref_candidates))
    for attempt in range(1, max_attempts + 1):
        cur_ref = ref_candidates[(attempt - 1) % len(ref_candidates)]
        cur_headers = dict(headers)
        if cur_ref:
            cur_headers["Referer"] = cur_ref
        else:
            cur_headers.pop("Referer", None)
        try:
            resp = sess.get(url, headers=cur_headers, timeout=s.timeout)
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
            # Hotlink / CF block -> warm-up: hit referer page DAN root host
            # gambar utk set cookie CF, lalu retry dgn kandidat referer
            # berikutnya pada loop berikut.
            if resp.status_code in (401, 403, 429) and attempt < max_attempts:
                warm_targets = []
                if attempt == 1 and img_host_root:
                    warm_targets.append(img_host_root)
                if cur_ref:
                    warm_targets.append(cur_ref)
                for w in warm_targets:
                    try:
                        sess.get(
                            w,
                            headers={
                                "Accept": "text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8",
                                "Accept-Language": headers["Accept-Language"],
                            },
                            timeout=s.timeout,
                        )
                    except Exception:  # noqa: BLE001
                        pass
                log.info("fetch_bytes attempt %s got HTTP %s for %s (ref=%s) — warmed up, retrying",
                         attempt, resp.status_code, url, cur_ref)
                time.sleep(0.4 * attempt)
                continue
            last_err = RuntimeError(f"HTTP {resp.status_code} fetching {url}")
        except Exception as e:  # noqa: BLE001
            last_err = e
            log.warning("fetch_bytes attempt %s for %s failed: %s", attempt, url, e)
        time.sleep(0.3 * attempt)
    raise last_err or RuntimeError(f"Gagal fetch {url}")
