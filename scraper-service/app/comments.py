"""
Comments service for BacaKomik.

Auth model (v2): server-to-server only. Authenticated by the SAME X-API-Key
the scraper uses (SCRAPER_API_KEY env). The PHP shared host proxies every
browser request through /api/comments/* so:

  Browser -> bacakomik.io/api/comments/* (CSRF + session-auth on PHP side)
          -> Railway /comments/*       (X-API-Key header)

Because PHP is the only caller, we trust the user identity it sends in the
JSON body (`actor_id`, `actor_name`, `actor_role`). Browsers cannot forge
these because they cannot reach Railway directly without the API key.
"""
from __future__ import annotations

import os
import re
import sqlite3
import time
from contextlib import contextmanager
from typing import Any, Optional

from fastapi import APIRouter, Body, Depends, HTTPException, Query, Request
from pydantic import BaseModel, Field, field_validator

router = APIRouter(prefix="/comments", tags=["comments"])

REACTIONS = ("like", "love", "happy", "sad", "dislike")
TARGET_RE = re.compile(r"^(comic|chapter):[A-Za-z0-9_\-:.]{1,200}$")
TEXT_MAX = 4000
NAME_MAX = 60


# ---------- API key auth (same key as scraper) ----------

def require_key(request: Request) -> None:
    expected = os.getenv("SCRAPER_API_KEY", "").strip()
    if not expected:
        try:
            from .config import get_settings  # type: ignore
            expected = (get_settings().api_key or "").strip()
        except Exception:
            expected = ""
    got = request.headers.get("X-API-Key") or request.query_params.get("key") or ""
    if not expected or got != expected:
        raise HTTPException(status_code=401, detail="Invalid API key")


# ---------- DB ----------

def _db_path() -> str:
    p = os.getenv("COMMENTS_DB_PATH", "/data/comments.db")
    try:
        d = os.path.dirname(p) or "."
        os.makedirs(d, exist_ok=True)
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


# ---------- Models ----------

class Actor(BaseModel):
    actor_id: int = Field(..., ge=0)
    actor_name: Optional[str] = None
    actor_role: Optional[str] = "user"


class CommentIn(Actor):
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


class ReactionIn(Actor):
    type: str

    @field_validator("type")
    @classmethod
    def _ck(cls, v: str) -> str:
        if v not in REACTIONS:
            raise ValueError("invalid reaction type")
        return v


class ActorBody(Actor):
    pass


# ---------- Helpers ----------

def _sanitize_text(t: str) -> str:
    t = re.sub(r"<[^>]*>", "", t)
    t = re.sub(r"[\x00-\x08\x0b\x0c\x0e-\x1f]", "", t)
    t = re.sub(r"\n{3,}", "\n\n", t).strip()
    return t[:TEXT_MAX]


def _client_ip(req: Request) -> str:
    xf = req.headers.get("X-Forwarded-For", "").split(",")[0].strip()
    return xf or req.headers.get("CF-Connecting-IP") or (req.client.host if req.client else "0.0.0.0")


def _row_to_dict(r: sqlite3.Row) -> dict[str, Any]:
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
    return {"ok": True, "db": _DB_PATH, "count": n, "auth": "api-key"}


@router.get("", dependencies=[Depends(require_key)])
def list_comments(
    target: str = Query(..., min_length=3, max_length=210),
    sort: str = Query("top", pattern="^(top|new|old)$"),
    page: int = Query(1, ge=1, le=1000),
    per_page: int = Query(15, ge=1, le=50),
    actor_id: int = Query(0, ge=0),
):
    if not TARGET_RE.match(target):
        raise HTTPException(status_code=400, detail="invalid target")
    init_db()
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
        tops = [_row_to_dict(r) for r in top_rows]
        if tops:
            ids = [t["id"] for t in tops]
            ph = ",".join("?" * len(ids))
            rep_rows = c.execute(
                f"SELECT * FROM comments WHERE parent_id IN ({ph}) AND deleted_at IS NULL "
                f"ORDER BY created_at ASC LIMIT 500",
                ids,
            ).fetchall()
            replies = [_row_to_dict(r) for r in rep_rows]
        else:
            replies = []
        _attach_reactions(c, tops + replies, actor_id)
    by_parent: dict[int, list[dict]] = {}
    for r in replies:
        by_parent.setdefault(r["parent_id"], []).append(r)
    for t in tops:
        t["replies"] = by_parent.get(t["id"], [])
    return {"ok": True, "target": target, "page": page, "per_page": per_page,
            "total": total, "comments": tops}


@router.post("", dependencies=[Depends(require_key)])
def create_comment(body: CommentIn, request: Request):
    if body.actor_id <= 0:
        raise HTTPException(status_code=401, detail="login required")
    init_db()
    text = _sanitize_text(body.text)
    if not text:
        raise HTTPException(status_code=400, detail="empty text")
    ip = _client_ip(request)
    cutoff = int(time.time()) - 60
    with db() as c:
        recent = c.execute(
            "SELECT COUNT(*) AS n FROM comments WHERE created_at>=? AND user_id=?",
            [cutoff, body.actor_id],
        ).fetchone()["n"]
        if recent >= 6:
            raise HTTPException(status_code=429, detail="Too many comments, slow down")

        parent_id = None
        if body.parent_id:
            parent = c.execute(
                "SELECT id, target, parent_id FROM comments WHERE id=? AND deleted_at IS NULL",
                [body.parent_id],
            ).fetchone()
            if not parent or parent["target"] != body.target:
                raise HTTPException(status_code=400, detail="invalid parent")
            parent_id = parent["parent_id"] or parent["id"]

        cur = c.execute(
            "INSERT INTO comments(target, parent_id, user_id, user_name, text, created_at, ip) "
            "VALUES (?,?,?,?,?,?,?)",
            [
                body.target, parent_id, body.actor_id,
                (body.actor_name or "User")[:NAME_MAX],
                text, int(time.time()), ip,
            ],
        )
        new_id = cur.lastrowid
        row = c.execute("SELECT * FROM comments WHERE id=?", [new_id]).fetchone()
    out = _row_to_dict(row)
    out["reactions"] = {r: 0 for r in REACTIONS}
    out["my_reactions"] = []
    out["replies"] = []
    return {"ok": True, "comment": out}


@router.delete("/{comment_id}", dependencies=[Depends(require_key)])
def delete_comment(comment_id: int, body: ActorBody = Body(...)):
    with db() as c:
        row = c.execute("SELECT user_id FROM comments WHERE id=? AND deleted_at IS NULL", [comment_id]).fetchone()
        if not row:
            raise HTTPException(status_code=404, detail="not found")
        if int(row["user_id"]) != int(body.actor_id) and (body.actor_role or "") != "admin":
            raise HTTPException(status_code=403, detail="forbidden")
        c.execute("UPDATE comments SET deleted_at=? WHERE id=?", [int(time.time()), comment_id])
    return {"ok": True}


@router.post("/{comment_id}/react", dependencies=[Depends(require_key)])
def react(comment_id: int, body: ReactionIn):
    if body.actor_id <= 0:
        raise HTTPException(status_code=401, detail="login required")
    with db() as c:
        if not c.execute("SELECT 1 FROM comments WHERE id=? AND deleted_at IS NULL", [comment_id]).fetchone():
            raise HTTPException(status_code=404, detail="not found")
        existing = c.execute(
            "SELECT 1 FROM reactions WHERE comment_id=? AND user_id=? AND type=?",
            [comment_id, body.actor_id, body.type],
        ).fetchone()
        if existing:
            c.execute("DELETE FROM reactions WHERE comment_id=? AND user_id=? AND type=?",
                      [comment_id, body.actor_id, body.type])
            action = "removed"
        else:
            c.execute("INSERT INTO reactions(comment_id, user_id, type, created_at) VALUES (?,?,?,?)",
                      [comment_id, body.actor_id, body.type, int(time.time())])
            action = "added"
        rows = c.execute(
            "SELECT type, COUNT(*) AS n FROM reactions WHERE comment_id=? GROUP BY type",
            [comment_id],
        ).fetchall()
        counts = {r: 0 for r in REACTIONS}
        for r in rows:
            counts[r["type"]] = r["n"]
    return {"ok": True, "action": action, "counts": counts}


@router.post("/{comment_id}/pin", dependencies=[Depends(require_key)])
def pin(comment_id: int, body: ActorBody = Body(...)):
    if (body.actor_role or "") != "admin":
        raise HTTPException(status_code=403, detail="admin only")
    with db() as c:
        row = c.execute("SELECT is_pinned FROM comments WHERE id=? AND deleted_at IS NULL", [comment_id]).fetchone()
        if not row:
            raise HTTPException(status_code=404, detail="not found")
        new_val = 0 if row["is_pinned"] else 1
        c.execute("UPDATE comments SET is_pinned=? WHERE id=?", [new_val, comment_id])
    return {"ok": True, "is_pinned": bool(new_val)}
