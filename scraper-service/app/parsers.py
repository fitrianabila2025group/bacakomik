"""
HTML parsers for komiku.org / komiku.id / mangaku.top.

XPath selectors mirror the PHP KomikuScraper so the API contract is identical
to the in-process scraper that historically ran on shared hosting.
"""
from __future__ import annotations

import re
from typing import List, Optional
from urllib.parse import urljoin, urlparse

from lxml import html as lxml_html

# ---- Selectors (kept compatible with app/Services/Scraper/KomikuScraper.php) ----
SEL = {
    "cover": '//img[@itemprop="image"]/@src | //div[contains(@class,"thumb")]//img/@src',
    "title": '//h1//span[@itemprop="name"] | //h1',
    "alt_title": '//table[contains(@class,"inftable")]//tr[td[contains(.,"Judul Alternatif") or contains(.,"Alternative")]]/td[2]',
    "author": '//table[contains(@class,"inftable")]//tr[td[contains(.,"Author") or contains(.,"Pengarang")]]/td[2]',
    "artist": '//table[contains(@class,"inftable")]//tr[td[contains(.,"Ilustrator") or contains(.,"Artist")]]/td[2]',
    "type": '//table[contains(@class,"inftable")]//tr[td[contains(.,"Tipe") or contains(.,"Jenis") or contains(.,"Type")]]/td[2]',
    "status": '//table[contains(@class,"inftable")]//tr[td[contains(.,"Status")]]/td[2]',
    "rating": '//table[contains(@class,"inftable")]//tr[td[contains(.,"Rating")]]/td[2]',
    "synopsis": '//*[@itemprop="description"] | //div[contains(@class,"desc")] | //section[contains(@class,"sinopsis")] | //div[@id="Sinopsis"]',
    "genres": '//ul[contains(@class,"genre")]//a | //div[contains(@class,"genre")]//a',
    "views": '//table[contains(@class,"inftable")]//tr[td[contains(.,"Pembaca") or contains(.,"Dilihat") or contains(.,"Views")]]/td[2]',
    "chapter_rows": '//*[@id="Daftar_Chapter"]//tr[@itemprop="itemListElement"] | //tbody[@id="daftarChapter"]//tr[@itemprop="itemListElement"] | //table[contains(@class,"chapter")]//tr',
    "chapter_link": './/a/@href',
    "chapter_title": './/a',
    "chapter_date": './/td[contains(@class,"tanggalseries") or contains(@class,"date")]',
    "chapter_views": './/td[contains(@class,"views")]',
    "image": '//div[@id="Baca_Komik"]//img/@src | //section[@id="Baca_Komik"]//img/@src | //*[@id="Baca_Komik"]//img/@src',
    "listing_links": "//a/@href",
    "listing_next": '//a[contains(@class,"next") and not(contains(@class,"disabled"))]/@href | //link[@rel="next"]/@href | //a[normalize-space(.)="Next" or normalize-space(.)="»" or normalize-space(.)="Selanjutnya"]/@href',
}

DETAIL_PATTERN = re.compile(r"^https?://[^/]+/(manga|komik)/[^/]+/?$", re.IGNORECASE)
PAGINATION_PATTERN = re.compile(
    r"/(daftar-komik|pustaka|komik)/?(\?(halaman|page|huruf|tipe|genre|status|orderby)=[^&]+|page/\d+/?)",
    re.IGNORECASE,
)


def _abs(base: str, href: str) -> str:
    if href.startswith("//"):
        return "https:" + href
    return urljoin(base, href)


def _xtext(tree, expr: str) -> Optional[str]:
    nodes = tree.xpath(expr)
    if not nodes:
        return None
    n = nodes[0]
    text = n.text_content() if hasattr(n, "text_content") else str(n)
    return re.sub(r"\s+", " ", text).strip() or None


def _xvalue(tree, expr: str) -> Optional[str]:
    nodes = tree.xpath(expr)
    if not nodes:
        return None
    return str(nodes[0]).strip() or None


def _normalize_type(value: Optional[str]) -> str:
    t = (value or "").lower()
    if "manhwa" in t:
        return "Manhwa"
    if "manhua" in t:
        return "Manhua"
    return "Manga"


def _normalize_status(value: Optional[str]) -> str:
    t = (value or "").lower()
    if any(k in t for k in ("tamat", "complete", "end")):
        return "Completed"
    if "hiatus" in t:
        return "Hiatus"
    return "Ongoing"


def parse_comic_metadata(html: str, source_url: str) -> dict:
    tree = lxml_html.fromstring(html)
    title = _xtext(tree, '//h1//span[@itemprop="name"]') or _xtext(tree, SEL["title"]) or ""
    title = re.sub(r"^\s*Komik\s+", "", title, flags=re.IGNORECASE).strip()

    cover = _xvalue(tree, SEL["cover"])
    cover_url = _abs(source_url, cover) if cover else None

    genres = []
    for n in tree.xpath(SEL["genres"]):
        g = (n.text_content() if hasattr(n, "text_content") else str(n)).strip()
        if g and g not in genres:
            genres.append(g)

    rating_text = _xtext(tree, SEL["rating"]) or ""
    views_text = _xtext(tree, SEL["views"]) or ""

    return {
        "title": title,
        "alt_title": _xtext(tree, SEL["alt_title"]),
        "author": _xtext(tree, SEL["author"]),
        "artist": _xtext(tree, SEL["artist"]),
        "type": _normalize_type(_xtext(tree, SEL["type"])),
        "status": _normalize_status(_xtext(tree, SEL["status"])),
        "rating": float(re.sub(r"[^0-9.]", "", rating_text) or 0) if rating_text else 0.0,
        "views": int(re.sub(r"[^0-9]", "", views_text) or 0) if views_text else 0,
        "synopsis": _xtext(tree, SEL["synopsis"]),
        "cover_url": cover_url,
        "genres": genres,
        "source_url": source_url,
    }


def parse_chapter_list(html: str, source_url: str) -> List[dict]:
    tree = lxml_html.fromstring(html)
    chapters: List[dict] = []
    for row in tree.xpath(SEL["chapter_rows"]):
        link_nodes = row.xpath(SEL["chapter_link"])
        if not link_nodes:
            continue
        link = _abs(source_url, str(link_nodes[0]).strip())
        title_nodes = row.xpath(SEL["chapter_title"])
        title = title_nodes[0].text_content().strip() if title_nodes else ""
        date_nodes = row.xpath(SEL["chapter_date"])
        date = date_nodes[0].text_content().strip() if date_nodes else ""
        view_nodes = row.xpath(SEL["chapter_views"])
        views = 0
        if view_nodes:
            views = int(re.sub(r"[^0-9]", "", view_nodes[0].text_content() or "0") or 0)
        m = re.search(r"(\d+(?:\.\d+)?)", title)
        number = m.group(1) if m else str(len(chapters) + 1)
        chapters.append(
            {"number": number, "title": title, "url": link, "date": date, "views": views}
        )
    chapters.sort(key=lambda c: float(c["number"]) if re.fullmatch(r"\d+(\.\d+)?", c["number"]) else 0)
    return chapters


def parse_chapter_images(html: str, source_url: str) -> List[str]:
    tree = lxml_html.fromstring(html)
    images = []
    for src in tree.xpath(SEL["image"]):
        s = str(src).strip()
        if s:
            images.append(_abs(source_url, s))
    return images


def parse_listing(html: str, source_url: str):
    """Returns (detail_urls_set, next_listing_urls_set)."""
    tree = lxml_html.fromstring(html)
    details: set = set()
    next_pages: set = set()
    for raw in tree.xpath(SEL["listing_links"]):
        h = str(raw).strip()
        if not h or h.startswith(("javascript:", "mailto:")):
            continue
        href = _abs(source_url, h).split("#", 1)[0]
        if DETAIL_PATTERN.match(href) and "?" not in href:
            details.add(href)
            continue
        if PAGINATION_PATTERN.search(href):
            next_pages.add(href)
    for raw in tree.xpath(SEL["listing_next"]):
        href = _abs(source_url, str(raw).strip()).split("#", 1)[0]
        next_pages.add(href)
    return details, next_pages


SITEMAP_LOC_RE = re.compile(r"<loc>([^<]+)</loc>", re.IGNORECASE)
SITEMAP_INDEX_RE = re.compile(r"<sitemap>\s*<loc>([^<]+)</loc>", re.IGNORECASE)


def parse_sitemap(xml: str) -> List[str]:
    """Return (child_sitemaps, comic_detail_urls)."""
    children = [m.strip() for m in SITEMAP_INDEX_RE.findall(xml)]
    locs = [m.strip() for m in SITEMAP_LOC_RE.findall(xml)]
    comics = []
    for loc in locs:
        norm = re.sub(r"/sitemap[^/]*/(manga|komik)/", r"/\1/", loc, flags=re.IGNORECASE)
        if DETAIL_PATTERN.match(norm):
            comics.append(norm)
    return children, comics  # type: ignore[return-value]


def whitelist_host(url: str, whitelist: List[str]) -> bool:
    host = urlparse(url).hostname or ""
    for w in whitelist:
        if host == w or host.endswith("." + w):
            return True
    return False
