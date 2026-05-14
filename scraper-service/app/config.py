"""
Centralised configuration loaded from environment variables.

Designed to *never* crash on startup so platforms like Railway / Render that
do not auto-import .env files still get a healthy container on first deploy.

Behaviour:
* Non-secret settings have sensible built-in defaults.
* If SCRAPER_API_KEY is missing, a random key is generated **once**, persisted
  to ``cache/api_key.txt`` and logged loudly so the operator can read it from
  the deploy logs and paste it into the BacaKomik admin panel.
"""
from __future__ import annotations

import logging
import os
import secrets
from functools import lru_cache
from typing import List

from dotenv import load_dotenv

load_dotenv()

log = logging.getLogger("config")

_KEY_FILE = os.path.join(os.path.dirname(os.path.dirname(__file__)), "cache", "api_key.txt")


def _split_csv(value: str) -> List[str]:
    return [v.strip() for v in (value or "").split(",") if v.strip()]


def _resolve_api_key() -> str:
    """Return the API key from env, or generate+persist a random one."""
    key = os.getenv("SCRAPER_API_KEY", "").strip()
    if key and key != "ganti-dengan-token-rahasia-anda":
        return key

    # Reuse a previously generated key if the cache volume survives restarts.
    try:
        if os.path.isfile(_KEY_FILE):
            with open(_KEY_FILE, "r", encoding="utf-8") as f:
                cached = f.read().strip()
            if cached:
                _announce_key(cached, generated=False)
                return cached
    except OSError:
        pass

    new_key = secrets.token_hex(32)
    try:
        os.makedirs(os.path.dirname(_KEY_FILE), exist_ok=True)
        with open(_KEY_FILE, "w", encoding="utf-8") as f:
            f.write(new_key + "\n")
    except OSError as e:
        log.warning("Tidak bisa menyimpan api_key.txt: %s", e)
    _announce_key(new_key, generated=True)
    return new_key


def _announce_key(key: str, *, generated: bool) -> None:
    banner = "=" * 72
    label = "AUTO-GENERATED" if generated else "LOADED FROM CACHE"
    msg = (
        f"\n{banner}\n"
        f"  SCRAPER_API_KEY ({label})\n"
        f"  -> {key}\n"
        f"  Salin nilai ini ke admin BacaKomik:\n"
        f"     Settings -> Scraper API -> API Key\n"
        f"  Untuk override permanen, set env var SCRAPER_API_KEY di Railway / VPS.\n"
        f"{banner}"
    )
    # Print + log so it's visible in both stdout and structured logs.
    print(msg, flush=True)
    log.warning(msg)


class Settings:
    api_key: str = ""  # filled in get_settings()
    mode: str = os.getenv("SCRAPER_MODE", "request").strip().lower()
    whitelist: List[str] = _split_csv(
        os.getenv(
            "SCRAPER_WHITELIST",
            "komiku.org,komiku.id,img.komiku.org,mangaku.top,img.mangaku.top,cover.mangaku.top",
        )
    )
    user_agent: str = os.getenv(
        "SCRAPER_USER_AGENT",
        "Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36",
    )
    timeout: int = int(os.getenv("SCRAPER_TIMEOUT", "45"))
    cache_ttl: int = int(os.getenv("SCRAPER_CACHE_TTL", "3600"))
    host: str = os.getenv("HOST", "0.0.0.0")
    port: int = int(os.getenv("PORT", "8080"))
    cors_origins: List[str] = _split_csv(os.getenv("CORS_ORIGINS", "*")) or ["*"]


@lru_cache
def get_settings() -> Settings:
    s = Settings()
    s.api_key = _resolve_api_key()
    if s.mode not in ("request", "browser"):
        log.warning("SCRAPER_MODE='%s' tidak valid, fallback ke 'request'", s.mode)
        s.mode = "request"
    return s
