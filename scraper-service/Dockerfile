# BacaKomik Scraper Service — image with Chrome for Botasaurus browser mode.
# Works on Railway, Fly.io, Render, atau VPS biasa.
FROM python:3.11-slim

ENV PYTHONDONTWRITEBYTECODE=1 \
    PYTHONUNBUFFERED=1 \
    PIP_NO_CACHE_DIR=1 \
    DEBIAN_FRONTEND=noninteractive

# Install Chrome + dependencies needed by Botasaurus' headless driver
RUN apt-get update && apt-get install -y --no-install-recommends \
    wget gnupg ca-certificates curl unzip xvfb \
    libnss3 libxss1 libasound2 libgbm1 libgtk-3-0 libxshmfence1 \
    fonts-liberation libappindicator3-1 libxcomposite1 libxdamage1 \
    libxrandr2 xdg-utils \
 && wget -q -O - https://dl.google.com/linux/linux_signing_key.pub | gpg --dearmor -o /usr/share/keyrings/google-chrome.gpg \
 && echo "deb [arch=amd64 signed-by=/usr/share/keyrings/google-chrome.gpg] http://dl.google.com/linux/chrome/deb/ stable main" > /etc/apt/sources.list.d/google-chrome.list \
 && apt-get update && apt-get install -y --no-install-recommends google-chrome-stable \
 && apt-get clean && rm -rf /var/lib/apt/lists/*

WORKDIR /app

COPY requirements.txt ./
RUN pip install --upgrade pip && pip install -r requirements.txt

COPY app/ ./app/

# Sensible defaults so platforms like Railway / Render can deploy this image
# with an empty Variables tab and still get a working service.
# SCRAPER_API_KEY is intentionally NOT set here -- the app auto-generates one
# on first start and prints it to the deploy logs.
ENV HOST=0.0.0.0 \
    PORT=8080 \
    SCRAPER_MODE=request \
    SCRAPER_WHITELIST="komiku.org,komiku.id,img.komiku.org,mangaku.top,img.mangaku.top,cover.mangaku.top" \
    SCRAPER_TIMEOUT=45 \
    SCRAPER_CACHE_TTL=3600 \
    CORS_ORIGINS="*"

EXPOSE 8080

# Railway/Render set $PORT dynamically; uvicorn picks it up via ${PORT:-8080}.
CMD ["sh", "-c", "uvicorn app.main:app --host ${HOST:-0.0.0.0} --port ${PORT:-8080} --workers 1"]
