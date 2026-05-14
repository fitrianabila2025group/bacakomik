"""
FastAPI HTTP API exposing the scraper.

Auth: every request must carry an `X-API-Key` header equal to SCRAPER_API_KEY,
or `?key=...` query param (so cron URLs work too).

Endpoints:
  GET  /health
  GET  /discover                       (?max_pages, ?max_comics, ?seeds=...comma)
  GET  /discover/sitemap               (?urls=comma)
  GET  /discover/listing               (?seeds=comma, ?max_pages, ?max_comics)
  POST /scrape/comic       { url }
  POST /scrape/chapters    { url }
  POST /scrape/images      { url }
  GET  /proxy?url=...&referer=...      (streams image bytes)
"""
from __future__ import annotations

import logging
import os
from typing import List, Optional

from fastapi import Depends, FastAPI, HTTPException, Query, Request
from fastapi.middleware.cors import CORSMiddleware
from fastapi.responses import Response
from pydantic import BaseModel, Field

from . import scraper
from .config import get_settings
from .fetcher import fetch_bytes

logging.basicConfig(level=logging.INFO, format="%(asctime)s %(levelname)s %(name)s: %(message)s")
log = logging.getLogger("api")

app = FastAPI(title="BacaKomik Scraper API", version="1.3.0")

_settings = get_settings()
app.add_middleware(
    CORSMiddleware,
    allow_origins=_settings.cors_origins,
    allow_methods=["GET", "POST", "OPTIONS"],
    allow_headers=["*"],
)


def require_key(request: Request) -> None:
    s = get_settings()
    key = request.headers.get("X-API-Key") or request.query_params.get("key") or ""
    if key != s.api_key:
        raise HTTPException(status_code=401, detail="Invalid API key")


class UrlBody(BaseModel):
    url: str = Field(..., min_length=1)


class ComicFullBody(BaseModel):
    url: str = Field(..., min_length=1)
    concurrency: int = Field(6, ge=1, le=16)
    include_images: bool = True


def _split_csv_param(value: Optional[str]) -> Optional[List[str]]:
    if not value:
        return None
    out = [v.strip() for v in value.split(",") if v.strip()]
    return out or None


@app.get("/health")
def health():
    s = get_settings()
    return {
        "ok": True,
        "mode": s.mode,
        "whitelist": s.whitelist,
        "proxy_public": s.proxy_public,
        # Flag supaya admin tahu key masih bawaan auto-generate (cek di deploy log).
        "api_key_source": "env" if os.getenv("SCRAPER_API_KEY", "").strip() else "auto-generated",
    }


@app.get("/discover", dependencies=[Depends(require_key)])
def discover(
    seeds: Optional[str] = Query(None, description="Custom seed listing URLs (comma-separated)"),
    sitemap: Optional[str] = Query(None, description="Custom sitemap URLs (comma-separated)"),
    max_pages: int = Query(200, ge=1, le=2000),
    max_comics: int = Query(0, ge=0),
):
    urls = scraper.discover(
        seeds=_split_csv_param(seeds),
        sitemap_urls=_split_csv_param(sitemap),
        max_pages=max_pages,
        max_comics=max_comics,
    )
    return {"count": len(urls), "urls": urls}


@app.get("/discover/sitemap", dependencies=[Depends(require_key)])
def discover_sitemap(urls: Optional[str] = Query(None)):
    out = scraper.discover_via_sitemap(_split_csv_param(urls))
    return {"count": len(out), "urls": out}


@app.get("/discover/listing", dependencies=[Depends(require_key)])
def discover_listing(
    seeds: Optional[str] = Query(None),
    max_pages: int = Query(200, ge=1, le=2000),
    max_comics: int = Query(0, ge=0),
):
    out = scraper.discover_via_listing(_split_csv_param(seeds), max_pages, max_comics)
    return {"count": len(out), "urls": out}


@app.post("/scrape/comic", dependencies=[Depends(require_key)])
def scrape_comic(body: UrlBody):
    try:
        return scraper.get_comic(body.url)
    except PermissionError as e:
        raise HTTPException(status_code=403, detail=str(e)) from e
    except Exception as e:  # noqa: BLE001
        raise HTTPException(status_code=502, detail=str(e)) from e


@app.post("/scrape/chapters", dependencies=[Depends(require_key)])
def scrape_chapters(body: UrlBody):
    try:
        chapters = scraper.get_chapters(body.url)
        return {"count": len(chapters), "chapters": chapters}
    except PermissionError as e:
        raise HTTPException(status_code=403, detail=str(e)) from e
    except Exception as e:  # noqa: BLE001
        raise HTTPException(status_code=502, detail=str(e)) from e


@app.post("/scrape/images", dependencies=[Depends(require_key)])
def scrape_images(body: UrlBody):
    try:
        images = scraper.get_chapter_images(body.url)
        return {"count": len(images), "images": images}
    except PermissionError as e:
        raise HTTPException(status_code=403, detail=str(e)) from e
    except Exception as e:  # noqa: BLE001
        raise HTTPException(status_code=502, detail=str(e)) from e


@app.post("/scrape/comic-full", dependencies=[Depends(require_key)])
def scrape_comic_full(body: ComicFullBody):
    """
    Bulk: 1 HTTP call -> metadata + chapter list + semua image URLs.
    Dipakai oleh shared hosting agar 1 import komik = 1 round-trip
    (bukan 1 + 1 + N) sehingga website tidak ter-blok PHP-FPM worker.
    """
    try:
        return scraper.get_comic_full(body.url, body.concurrency, body.include_images)
    except PermissionError as e:
        raise HTTPException(status_code=403, detail=str(e)) from e
    except Exception as e:  # noqa: BLE001
        raise HTTPException(status_code=502, detail=str(e)) from e


@app.get("/proxy")
def proxy(request: Request, url: str = Query(...), referer: Optional[str] = Query(None)):
    """
    Stream an image (or any whitelisted asset) through the scraper.

    Bila ``SCRAPER_PROXY_PUBLIC=1`` (default), endpoint ini terbuka tanpa
    API key supaya URL gambar yang disimpan di database shared-hosting bisa
    di-render langsung oleh browser pengunjung tanpa membocorkan key.
    Pengaman: hanya domain di ``SCRAPER_WHITELIST`` yang dilayani.
    """
    s = get_settings()
    if not s.proxy_public:
        require_key(request)
    try:
        body, ctype = fetch_bytes(url, referer)
    except PermissionError as e:
        raise HTTPException(status_code=403, detail=str(e)) from e
    except Exception as e:  # noqa: BLE001
        raise HTTPException(status_code=502, detail=str(e)) from e
    headers = {
        "Cache-Control": f"public, max-age={s.proxy_cache_age}, immutable",
        "Access-Control-Allow-Origin": "*",
        "X-Content-Type-Options": "nosniff",
    }
    return Response(content=body, media_type=ctype, headers=headers)
