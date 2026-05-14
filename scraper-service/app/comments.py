"""
Comments service for BacaKomik.

Stateless FastAPI router backed by SQLite. Designed to run on Railway as part
of the same scraper-service container so the shared-host (PHP) stays light.

Auth model:
- Guest browsing: GET endpoints open (anyone can read).
- Authoring (POST/DELETE/REACT): requires X-User-Token header issued by PHP
  side. Token format:  <base64url(json)>.<hex_hmac_sha256(secret, json)>
  payload: {"uid": int, "name": str, "exp": unix_ts, "role": "user"|"admin"}
- Guest comments allowed only if request also passes a valid CAPTCHA token
  (verified PHP side; PHP signs a guest token after captcha success).

CORS: open by default, set CORS_ORIGINS env in container.

DB lives at $COMMENTS_DB_PATH (default /data/comments.db with /tmp fallback).
Mount a Railway volume at /data for persistence — otherwise comments survive
only until container restart.
"""
from __future__ import annotations

import base64
import hashlib
import hmac
import json
import os
import re
import sqlite3
import time
from contextlib import contextmanager
from typing import Any, Optional

from fastapi import APIRouter, HTTPException, Header, Query, Request
from pydantic import BaseModel, Field, field_validator

router = APIRouter(prefix="/comments", tags=["comments"])

REACTIONS = ("like", "love", "happy", "sad", "dislike")
TARGET_RE = re.compile(r"^(comic|chapter):[A-Za-z0-9_\-:.]{1,200}$")
TEXT_MAX = 4000
NAME_MAX = 60


# ---------- DB ----------

def _db_path() -> str:
    p = os.getenv("COMMENTS_DB_PATH", "/data/comments.db")
    try:
        d = os.path.dirname(p) or "."
        os.makedirs(d, exist_ok=True)
        # writability check
        test = os.path.join(d, ".write_test")
        with open(test, "w") as f:
            f.write("x")
        os.remove(test)
        return p
    except Exception:
        return "/tmp/comments.db"


_DB_PATH: Optional[str] = None


def _conn() -> sqlite3.Connection:
    global _DB_PATH
    if _DB_PATH is None:
        _DB_PATH = _db_path()
    c = sqlite3.connect(_DB_PATH, timeout=10, isolation_level=None)
    c.row_factory = sqlite3.Row
    c.execute("PRAGMA journal_mode=WAL")
    c.execute("PRAGMA foreign_keys=ON")
    return c


@contextmanager
def db():
    c = _conn()
    try:
        yield c
    finally:
        c.close()


def init_db() -> None:
    with db() as c:
        c.executescript("""
        CREATE TABLE IF NOT EXISTS comments (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            target TEXT NOT NULL,
            parent_id INTEGER,
            user_id INTEGER NOT NULL,
            user_name TEXT NOT NULL,
            user_email TEXT,
            text TEXT NOT NULL,
            is_pinned INTEGER NOT NULL DEFAULT 0,
            approved INTEGER NOT NULL DEFAULT 1,
            deleted_at INTEGER,
            created_at INTEGER NOT NULL,
            ip TEXT
        );
        CREATE INDEX IF NOT EXISTS idx_comments_target
            ON comments(target, parent_id, created_at);
        CREATE INDEX IF NOT EXISTS idx_comments_parent
            ON comments(parent_id);

        CREATE TABLE IF NOT EXISTS reactions (
            comment_id INTEGER NOT NULL,
            user_id    INTEGER NOT NULL,
            type       TEXT    NOT NULL,
            created_at INTEGER NOT NULL,
            PRIMARY KEY(comment_id, user_id, type),
            FOREIGN KEY(comment_id) REFERENCES comments(id) ON DELETE CASCADE
        );
        """)


# ---------- HMAC user token ----------

def _b64u_decode(s: str) -> bytes:
    pad = "=" * (-len(s) % 4)
    return base64.urlsafe_b64decode(s + pad)


def _hmac_secret() -> str:
    s = os.getenv("COMMENTS_HMAC_SECRET", "").strip()
    if not s:
        # No secret configured → reject all writes (safer than auto-generate).
        return ""
    return s


def verify_user_token(token: str) -> Optional[dict]:
    """Returns payload dict if valid + non-expired, else None."""
    if not token or "." not in token:
        return None
    secret = _hmac_secret()
    if not secret:
        return None
    try:
        payload_b64, sig_hex = token.split(".", 1)
        expected = hmac.new(secret.encode(), payload_b64.encode(), hashlib.sha256).hexdigest()
        if not hmac.compare_digest(expected, sig_hex):
            return None
        payload = json.loads(_b64u_decode(payload_b64).decode())
        if not isinstance(payload, dict):
            return None
        if int(payload.get("exp", 0)) < int(time.time()):
            return None
        return payload
    except Exception:
        return None


def _require_user(token: Optional[str]) -> dict:
    p = verify_user_token(token or "")
    if not p:
        raise HTTPException(status_code=401, detail="Invalid or expired user token")
    return p


# ---------- Models ----------

class CommentIn(BaseModel):
    target: str = Field(..., min_length=3, max_length=210)
    parent_id: Optional[int] = None
    text: str = Field(..., min_length=1, max_length=TEXT_MAX)

    @field_validator("target")
    @classmethod
    def _t(cls, v: str) -> str:
        if not TARGET_RE.match(v):
            raise ValueError("invalid target")
        return v

    @field_validator("text")
    @classmethod
    def _txt(cls, v: str) -> str:
        v = v.strip()
        if not v:
            raise ValueError("empty text")
        return v


class ReactionIn(BaseModel):
    type: str

    @field_validator("type")
    @classmethod
    def _ck(cls, v: str) -> str:
        if v not in REACTIONS:
            raise ValueError("invalid reaction type")
        return v


# ---------- Helpers ----------

def _sanitize_text(t: str) -> str:
    # Plain text only — strip HTML tags & control chars, normalize whitespace.
    t = re.sub(r"<[^>]*>", "", t)
    t = re.sub(r"[\x00-\x08\x0b\x0c\x0e-\x1f]", "", t)
    t = re.sub(r"\n{3,}", "\n\n", t).strip()
    return t[:TEXT_MAX]


def _client_ip(req: Request) -> str:
    return (
        req.headers.get("CF-Connecting-IP")
        or req.headers.get("X-Real-IP")
        or (req.client.host if req.client else "0.0.0.0")
    )


def _row_to_dict(r: sqlite3.Row, my_uid: int = 0) -> dict[str, Any]:
    return {
        "id": r["id"],
        "parent_id": r["parent_id"],
        "user_id": r["user_id"],
        "user_name": r["user_name"],
        "text": r["text"],
        "is_pinned": bool(r["is_pinned"]),
        "created_at": r["created_at"],
    }


def _attach_reactions(conn: sqlite3.Connection, comments: list[dict], my_uid: int) -> None:
    if not comments:
        return
    ids = [c["id"] for c in comments]
    placeholders = ",".join("?" * len(ids))
    rows = conn.execute(
        f"SELECT comment_id, type, COUNT(*) AS n FROM reactions "
        f"WHERE comment_id IN ({placeholders}) GROUP BY comment_id, type",
        ids,
    ).fetchall()
    by_id: dict[int, dict[str, int]] = {i: {r: 0 for r in REACTIONS} for i in ids}
    for r in rows:
        by_id[r["comment_id"]][r["type"]] = r["n"]
    mine: dict[int, list[str]] = {i: [] for i in ids}
    if my_uid:
        for r in conn.execute(
            f"SELECT comment_id, type FROM reactions "
            f"WHERE user_id=? AND comment_id IN ({placeholders})",
            [my_uid, *ids],
        ).fetchall():
            mine[r["comment_id"]].append(r["type"])
    for c in comments:
        c["reactions"] = by_id[c["id"]]
        c["my_reactions"] = mine[c["id"]]


# ---------- Endpoints ----------

@router.get("/health")
def health():
    init_db()
    with db() as c:
        n = c.execute("SELECT COUNT(*) AS n FROM comments").fetchone()["n"]
    return {"ok": True, "db": _DB_PATH, "count": n, "secret_configured": bool(_hmac_secret())}


@router.get("")
def list_comments(
    target: str = Query(..., min_length=3, max_length=210),
    sort: str = Query("top", pattern="^(top|new|old)$"),
    page: int = Query(1, ge=1, le=1000),
    per_page: int = Query(15, ge=1, le=50),
    x_user_token: Optional[str] = Header(None),
):
    if not TARGET_RE.match(target):
        raise HTTPException(status_code=400, detail="invalid target")
    init_db()
    me = verify_user_token(x_user_token or "")
    my_uid = int(me["uid"]) if me else 0
    order = {
        "new": "is_pinned DESC, created_at DESC",
        "old": "is_pinned DESC, created_at ASC",
        "top": "is_pinned DESC, (SELECT COUNT(*) FROM reactions r WHERE r.comment_id=comments.id) DESC, created_at DESC",
    }[sort]
    offset = (page - 1) * per_page
    with db() as c:
        total = c.execute(
            "SELECT COUNT(*) AS n FROM comments WHERE target=? AND parent_id IS NULL AND deleted_at IS NULL",
            [target],
        ).fetchone()["n"]
        top_rows = c.execute(
            f"SELECT * FROM comments WHERE target=? AND parent_id IS NULL AND deleted_at IS NULL "
            f"ORDER BY {order} LIMIT ? OFFSET ?",
            [target, per_page, offset],
        ).fetchall()
        tops = [_row_to_dict(r, my_uid) for r in top_rows]
        # Replies for the page (flat)
        if tops:
            ids = [t["id"] for t in tops]
            ph = ",".join("?" * len(ids))
            rep_rows = c.execute(
                f"SELECT * FROM comments WHERE parent_id IN ({ph}) AND deleted_at IS NULL "
                f"ORDER BY created_at ASC LIMIT 500",
                ids,
            ).fetchall()
            replies = [_row_to_dict(r, my_uid) for r in rep_rows]
        else:
            replies = []
        _attach_reactions(c, tops + replies, my_uid)
    by_parent: dict[int, list[dict]] = {}
    for r in replies:
        by_parent.setdefault(r["parent_id"], []).append(r)
    for t in tops:
        t["replies"] = by_parent.get(t["id"], [])
    return {
        "ok": True,
        "target": target,
        "page": page,
        "per_page": per_page,
        "total": total,
        "comments": tops,
        "me": {"uid": my_uid, "name": me["name"] if me else None, "role": (me or {}).get("role", "guest")},
    }


@router.post("")
def create_comment(
    body: CommentIn,
    request: Request,
    x_user_token: Optional[str] = Header(None),
):
    me = _require_user(x_user_token)
    init_db()
    text = _sanitize_text(body.text)
    if not text:
        raise HTTPException(status_code=400, detail="empty text")

    # Per-user/IP simple flood guard (max 6 / 60s).
    ip = _client_ip(request)
    cutoff = int(time.time()) - 60
    with db() as c:
        recent = c.execute(
            "SELECT COUNT(*) AS n FROM comments WHERE created_at>=? AND (user_id=? OR ip=?)",
            [cutoff, int(me["uid"]), ip],
        ).fetchone()["n"]
        if recent >= 6:
            raise HTTPException(status_code=429, detail="Too many comments, slow down")

        # Validate parent (must exist and belong to same target)
        parent_id = None
        if body.parent_id:
            parent = c.execute(
                "SELECT id, target, parent_id FROM comments WHERE id=? AND deleted_at IS NULL",
                [body.parent_id],
            ).fetchone()
            if not parent or parent["target"] != body.target:
                raise HTTPException(status_code=400, detail="invalid parent")
            # only 1 level deep — replies to replies attach to top
            parent_id = parent["parent_id"] or parent["id"]

        cur = c.execute(
            "INSERT INTO comments(target, parent_id, user_id, user_name, text, created_at, ip) "
            "VALUES (?,?,?,?,?,?,?)",
            [
                body.target,
                parent_id,
                int(me["uid"]),
                str(me.get("name") or "User")[:NAME_MAX],
                text,
                int(time.time()),
                ip,
            ],
        )
        new_id = cur.lastrowid
        row = c.execute("SELECT * FROM comments WHERE id=?", [new_id]).fetchone()
    out = _row_to_dict(row, int(me["uid"]))
    out["reactions"] = {r: 0 for r in REACTIONS}
    out["my_reactions"] = []
    out["replies"] = []
    return {"ok": True, "comment": out}


@router.delete("/{comment_id}")
def delete_comment(comment_id: int, x_user_token: Optional[str] = Header(None)):
    me = _require_user(x_user_token)
    with db() as c:
        row = c.execute("SELECT user_id FROM comments WHERE id=? AND deleted_at IS NULL", [comment_id]).fetchone()
        if not row:
            raise HTTPException(status_code=404, detail="not found")
        if int(row["user_id"]) != int(me["uid"]) and me.get("role") != "admin":
            raise HTTPException(status_code=403, detail="forbidden")
        c.execute("UPDATE comments SET deleted_at=? WHERE id=?", [int(time.time()), comment_id])
    return {"ok": True}


@router.post("/{comment_id}/react")
def react(
    comment_id: int,
    body: ReactionIn,
    x_user_token: Optional[str] = Header(None),
):
    me = _require_user(x_user_token)
    with db() as c:
        if not c.execute("SELECT 1 FROM comments WHERE id=? AND deleted_at IS NULL", [comment_id]).fetchone():
            raise HTTPException(status_code=404, detail="not found")
        # Toggle: if same reaction exists, remove it; else upsert.
        existing = c.execute(
            "SELECT 1 FROM reactions WHERE comment_id=? AND user_id=? AND type=?",
            [comment_id, int(me["uid"]), body.type],
        ).fetchone()
        if existing:
            c.execute(
                "DELETE FROM reactions WHERE comment_id=? AND user_id=? AND type=?",
                [comment_id, int(me["uid"]), body.type],
            )
            action = "removed"
        else:
            c.execute(
                "INSERT INTO reactions(comment_id, user_id, type, created_at) VALUES (?,?,?,?)",
                [comment_id, int(me["uid"]), body.type, int(time.time())],
            )
            action = "added"
        # Return fresh counts
        rows = c.execute(
            "SELECT type, COUNT(*) AS n FROM reactions WHERE comment_id=? GROUP BY type",
            [comment_id],
        ).fetchall()
        counts = {r: 0 for r in REACTIONS}
        for r in rows:
            counts[r["type"]] = r["n"]
    return {"ok": True, "action": action, "counts": counts}


@router.post("/{comment_id}/pin")
def pin(comment_id: int, x_user_token: Optional[str] = Header(None)):
    me = _require_user(x_user_token)
    if me.get("role") != "admin":
        raise HTTPException(status_code=403, detail="admin only")
    with db() as c:
        row = c.execute("SELECT is_pinned FROM comments WHERE id=? AND deleted_at IS NULL", [comment_id]).fetchone()
        if not row:
            raise HTTPException(status_code=404, detail="not found")
        new_val = 0 if row["is_pinned"] else 1
        c.execute("UPDATE comments SET is_pinned=? WHERE id=?", [new_val, comment_id])
    return {"ok": True, "is_pinned": bool(new_val)}
