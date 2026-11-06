FROM python:3.12-slim

ENV PYTHONDONTWRITEBYTECODE=1 PYTHONUNBUFFERED=1
RUN apt-get update && apt-get install -y --no-install-recommends ca-certificates \
    && rm -rf /var/lib/apt/lists/* \
    && useradd --system --uid 10001 --create-home --home-dir /home/ilmek ilmek
WORKDIR /app
COPY --chown=ilmek:ilmek server.py index.html app.js style.css ./
USER 10001:10001
EXPOSE 8000
CMD ["python", "-u", "server.py"]
