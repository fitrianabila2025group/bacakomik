"""
Centralised configuration loaded from environment variables.
"""
from __future__ import annotations

import os
from functools import lru_cache
from typing import List

from dotenv import load_dotenv

load_dotenv()


def _split_csv(value: str) -> List[str]:
    return [v.strip() for v in (value or "").split(",") if v.strip()]


class Settings:
    api_key: str = os.getenv("SCRAPER_API_KEY", "").strip()
    mode: str = os.getenv("SCRAPER_MODE", "request").strip().lower()  # "request" | "browser"
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
    if not s.api_key:
        raise RuntimeError(
            "SCRAPER_API_KEY belum diset. Tambahkan di environment / .env sebelum start service."
        )
    if s.mode not in ("request", "browser"):
        raise RuntimeError("SCRAPER_MODE harus 'request' atau 'browser'")
    return s
