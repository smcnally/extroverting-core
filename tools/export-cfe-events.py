#!/usr/bin/env python3
"""Export cfe-events D1 data to an extroverting-core import file.

Reads the local Cloudflare D1 database produced by the cfe-events research
pipeline (~/Documents/CFWorkers/cfe-events) and writes a normalized JSON
file into this plugin's data/ directory:

    python3 tools/export-cfe-events.py [path-to-d1.sqlite] [output.json]

Defaults:
    db  = first *.sqlite under ~/Documents/CFWorkers/cfe-events/workers/events-api/.wrangler/state/v3/d1/
    out = data/events-<today>.json (next to this script's repo root)

The output is consumed by extroverting_core_import_events() in
includes/class-ext-import.php. Re-running the export after a new cfe-events
research run and re-committing keeps the live site current:

    git pull && wp eval "print_r( extroverting_core_import_events() );"
"""

import json
import os
import re
import sqlite3
import sys
from datetime import date, datetime, timezone

# City display names + which cities are launched (shown in the home city grid).
LAUNCHED = {"san-francisco", "oakland", "berkeley", "oklahoma-city", "edmond"}

CITY_ALIASES = {
    "sf": "san francisco",
    "s.f.": "san francisco",
}

# cfe-events category labels -> extroverting ext_event_cat term.
# Events carry several labels; the highest-priority target present wins
# (Comedy before Music before Arts ...), not the first label in the list.
CATEGORY_PRIORITY = [
    ("Comedy", {"comedy"}),
    ("Music", {
        "music", "pop", "rock", "alternative", "concerts", "live music",
        "jazz", "hip hop", "hip-hop/rap", "r&b", "classical", "metal",
        "folk", "indie pop", "electro pop", "alternative rock", "blues",
    }),
    ("Arts", {
        "arts & theatre", "arts", "theatre", "performing-arts", "exhibitions",
        "museum", "opera", "dance",
    }),
    ("Food & Drink", {
        "food", "farmers market", "farmers' market", "dining", "brewery",
        "wine", "tasting",
    }),
    ("Outdoors", {"outdoors", "hiking", "running", "cycling"}),
    ("Community", {
        "community", "meetup", "workshop", "class", "classes", "yoga",
        "wellness", "physical", "festivals", "sports",
    }),
]

# Keyword fallback for free-form Meetup group names.
CATEGORY_KEYWORDS = [
    ("Food & Drink", ("food", "eat", "brew", "wine", "tast")),
    ("Community", ("dance", "salsa", "clean", "board game", "game night",
                   "yoga", "meditat", "healing", "senior", "support")),
]


def find_default_db():
    base = os.path.expanduser(
        "~/Documents/CFWorkers/cfe-events/workers/events-api/.wrangler/state/v3/d1"
    )
    for root, _dirs, files in os.walk(base):
        for f in files:
            if f.endswith(".sqlite"):
                return os.path.join(root, f)
    raise SystemExit("Could not find cfe-events D1 sqlite under " + base)


def normalize_city(raw):
    """'San Francisco, CA, CA' / 'SF, CA' -> ('san-francisco', 'San Francisco')."""
    city = (raw or "").strip()
    # Strip trailing ", XX" state fragments repeatedly.
    while True:
        m = re.match(r"^(.*?),\s*[A-Za-z]{2}$", city)
        if not m:
            break
        city = m.group(1).strip()
    low = city.lower().strip()
    low = CITY_ALIASES.get(low, low)
    slug = re.sub(r"[^a-z0-9]+", "-", low).strip("-")
    name = " ".join(w.capitalize() for w in low.split()) if low else ""
    return slug, name


def map_category(cats_json):
    try:
        cats = [str(c).strip().lower() for c in json.loads(cats_json or "[]")]
    except (ValueError, TypeError):
        cats = []
    # 1) Highest-priority target whose exact label is present.
    for target, labels in CATEGORY_PRIORITY:
        if any(c in labels for c in cats):
            return target
    # 2) Keyword fallback for free-form group names.
    joined = " ".join(cats)
    for target, needles in CATEGORY_KEYWORDS:
        if any(n in joined for n in needles):
            return target
    return "Other"


def fmt_time(hhmm):
    """'15:30' -> '3:30 PM'."""
    if not hhmm or len(hhmm) < 5:
        return None
    h, m = int(hhmm[:2]), int(hhmm[3:5])
    suffix = "PM" if h >= 12 else "AM"
    h12 = h % 12 or 12
    return f"{h12}:{m:02d} {suffix}"


def fmt_time_range(start, end, all_day, multi_day):
    if all_day:
        return "All day"
    s = fmt_time(start)
    e = fmt_time(end)
    if s and e and e != s:
        rng = f"{s} \u2013 {e}"
    elif s:
        rng = s
    else:
        return "All day"
    if multi_day:
        rng += " (multi-day)"
    return rng


def truncate(text, limit=600):
    text = re.sub(r"\s+", " ", (text or "")).strip()
    if len(text) <= limit:
        return text
    cut = text[:limit]
    sp = cut.rfind(" ")
    return cut[:sp].rstrip(",.;:") + " \u2026" if sp > 0 else cut


def main():
    db_path = sys.argv[1] if len(sys.argv) > 1 else find_default_db()
    out_dir = os.path.join(os.path.dirname(os.path.abspath(__file__)), "..", "data")
    out_path = (
        sys.argv[2]
        if len(sys.argv) > 2
        else os.path.join(out_dir, "events-" + date.today().isoformat() + ".json")
    )

    con = sqlite3.connect(db_path)
    con.row_factory = sqlite3.Row
    today = date.today().isoformat()

    rows = con.execute(
        """SELECT name, description, summary, categories_json, start_date,
                  start_time, end_time, is_all_day, is_multi_day, venue_name,
                  city, state, source_id, source_url, ticket_url, dedupe_key
           FROM events WHERE status != 'cancelled' AND status != 'deleted'"""
    ).fetchall()

    cities = {}
    events = []
    skipped_past = 0
    for r in rows:
        if (r["start_date"] or "") < today:
            skipped_past += 1
            continue
        slug, name = normalize_city(r["city"])
        if not slug:
            continue
        c = cities.setdefault(slug, {"slug": slug, "name": name, "state": r["state"] or "", "events": 0})
        c["events"] += 1

        summary = (r["summary"] or "").strip()
        desc = truncate(summary) if summary else truncate(r["description"])
        events.append({
            "key": r["dedupe_key"],
            "title": (r["name"] or "").strip(),
            "city": slug,
            "date": r["start_date"],
            "time": fmt_time_range(r["start_time"], r["end_time"], bool(r["is_all_day"]), bool(r["is_multi_day"])),
            "venue": (r["venue_name"] or "").strip(),
            "category": map_category(r["categories_json"]),
            "description": desc,
            "source": r["source_id"] or "",
            "source_url": r["source_url"] or "",
            "ticket_url": r["ticket_url"] or "",
        })

    for c in cities.values():
        c["launched"] = c["slug"] in LAUNCHED
        del c["events"]

    payload = {
        "_meta": {
            "source": "cfe-events D1 export",
            "db": db_path,
            "exported_at": datetime.now(timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ"),
            "window_note": "upcoming events only (start_date >= %s)" % today,
        },
        "cities": sorted(cities.values(), key=lambda x: -LAUNCHED.__contains__(x["slug"])),
        "events": sorted(events, key=lambda e: (e["date"], e["title"])),
    }

    os.makedirs(os.path.dirname(out_path), exist_ok=True)
    with open(out_path, "w") as f:
        json.dump(payload, f, indent=1, ensure_ascii=False)

    by_city = {}
    for e in events:
        by_city[e["city"]] = by_city.get(e["city"], 0) + 1
    print(f"db:            {db_path}")
    print(f"exported:      {len(events)} events, {len(cities)} cities (skipped {skipped_past} past)")
    for s in sorted(by_city, key=lambda k: -by_city[k]):
        flag = "*" if s in LAUNCHED else " "
        print(f"  {flag}{s}: {by_city[s]}")
    print(f"output:        {out_path}")


if __name__ == "__main__":
    main()
