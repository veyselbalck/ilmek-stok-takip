"""Optional cPanel cron entry: runs a daily backup at most once per 22 hours."""
from pathlib import Path
import time
import server

server.DATA.mkdir(parents=True,exist_ok=True)
last=max((p.stat().st_mtime for p in server.BACKUPS.glob('stok-*.zip')),default=0)
if time.time()-last>22*3600:
    server.backup()
