"""
Apify client for scraping Facebook and Instagram follower counts.

Actors used:
  - Facebook : unseenuser/fb-followers   (proven: 55 runs)
  - Instagram : apify/instagram-scraper  (proven: 71 runs)

Flow:
  1. run_actor()  → triggers the actor and returns a run_id
  2. poll_run()   → waits until run status == SUCCEEDED, then fetches dataset
  3. get_fb_followers() / get_ig_followers() → high-level helpers called from routes
"""

import time
import requests
from api.config import Config
from api.services.lookups import parse_scraped_number


APIFY_BASE = "https://api.apify.com/v2"

FB_ACTOR_ID  = "unseenuser~fb-followers"
IG_ACTOR_ID  = "apify~instagram-scraper"

# Max seconds to wait for an actor run before giving up
MAX_WAIT = 120
POLL_INTERVAL = 5


class ApifyClient:
    def __init__(self):
        self.token = Config.get_key("apify_api_token", "APIFY_API_TOKEN") or ""

    def _headers(self):
        return {"Authorization": f"Bearer {self.token}"}

    # ------------------------------------------------------------------
    # Low-level: run an actor and return run_id
    # ------------------------------------------------------------------
    def run_actor(self, actor_id: str, run_input: dict) -> dict:
        """Trigger an Apify actor run. Returns {'success', 'run_id'} or error."""
        if not self.token:
            return {"success": False, "message": "APIFY_API_TOKEN not configured"}

        url = f"{APIFY_BASE}/acts/{actor_id}/runs"
        try:
            r = requests.post(
                url,
                json=run_input,
                headers=self._headers(),
                params={"token": self.token},
                timeout=30,
            )
        except Exception as e:
            return {"success": False, "message": f"Apify request failed: {e}"}

        if r.status_code not in (200, 201):
            return {
                "success": False,
                "message": f"Apify HTTP {r.status_code}: {r.text[:300]}",
            }

        data = r.json().get("data", {})
        run_id = data.get("id")
        if not run_id:
            return {"success": False, "message": "Apify returned no run ID"}

        return {"success": True, "run_id": run_id}

    # ------------------------------------------------------------------
    # Low-level: poll a run until SUCCEEDED / FAILED / timeout
    # ------------------------------------------------------------------
    def poll_run(self, run_id: str, max_wait: int = MAX_WAIT) -> dict:
        """
        Polls run status every POLL_INTERVAL seconds.
        Returns {'success', 'dataset_id'} or error.
        """
        waited = 0
        while waited < max_wait:
            time.sleep(POLL_INTERVAL)
            waited += POLL_INTERVAL
            try:
                r = requests.get(
                    f"{APIFY_BASE}/actor-runs/{run_id}",
                    headers=self._headers(),
                    params={"token": self.token},
                    timeout=15,
                )
                info = r.json().get("data", {})
                status = info.get("status", "")
                if status == "SUCCEEDED":
                    dataset_id = info.get("defaultDatasetId")
                    if not dataset_id:
                        return {"success": False, "message": "Run succeeded but no dataset ID"}
                    return {"success": True, "dataset_id": dataset_id}
                if status in ("FAILED", "ABORTED", "TIMED-OUT"):
                    return {"success": False, "message": f"Apify run {status}"}
                # RUNNING / READY — keep polling
            except Exception as e:
                return {"success": False, "message": f"Poll error: {e}"}

        return {"success": False, "message": f"Apify run timed out after {max_wait}s"}

    # ------------------------------------------------------------------
    # Low-level: fetch items from a dataset
    # ------------------------------------------------------------------
    def fetch_dataset(self, dataset_id: str) -> dict:
        """Returns {'success', 'items': [...]} or error."""
        try:
            r = requests.get(
                f"{APIFY_BASE}/datasets/{dataset_id}/items",
                headers=self._headers(),
                params={"token": self.token, "clean": "true", "format": "json"},
                timeout=20,
            )
            if r.status_code != 200:
                return {"success": False, "message": f"Dataset HTTP {r.status_code}"}
            items = r.json()
            if not isinstance(items, list):
                items = [items]
            return {"success": True, "items": items}
        except Exception as e:
            return {"success": False, "message": f"Dataset fetch error: {e}"}

    # ------------------------------------------------------------------
    # High-level: Facebook follower count via unseenuser/fb-followers
    # ------------------------------------------------------------------
    def get_fb_followers(self, page_url: str) -> dict:
        """
        Returns {'success': True, 'followers': int}
              | {'success': False, 'message': str}
        """
        run_input = {
            "startUrls": [{"url": page_url}],
            "maxItems": 1,
        }

        trig = self.run_actor(FB_ACTOR_ID, run_input)
        if not trig["success"]:
            return trig

        poll = self.poll_run(trig["run_id"])
        if not poll["success"]:
            return poll

        ds = self.fetch_dataset(poll["dataset_id"])
        if not ds["success"]:
            return ds

        items = ds["items"]
        if not items:
            return {"success": False, "message": "Apify FB: no data returned"}

        row = items[0]
        # unseenuser/fb-followers typical keys: followers, followersCount, likes
        raw = (
            row.get("followers")
            or row.get("followersCount")
            or row.get("likes")
            or row.get("fan_count")
            or row.get("likesCount")
            or 0
        )
        followers = parse_scraped_number(raw)
        page_id = str(row.get("id") or row.get("pageId") or "")

        return {"success": True, "followers": followers, "page_id": page_id}

    # ------------------------------------------------------------------
    # High-level: Instagram follower count via apify/instagram-scraper
    # ------------------------------------------------------------------
    def get_ig_followers(self, profile_url: str) -> dict:
        """
        Returns {'success': True, 'followers': int}
              | {'success': False, 'message': str}
        """
        run_input = {
            "directUrls": [profile_url],
            "resultsType": "details",
            "resultsLimit": 1,
        }

        trig = self.run_actor(IG_ACTOR_ID, run_input)
        if not trig["success"]:
            return trig

        poll = self.poll_run(trig["run_id"])
        if not poll["success"]:
            return poll

        ds = self.fetch_dataset(poll["dataset_id"])
        if not ds["success"]:
            return ds

        items = ds["items"]
        if not items:
            return {"success": False, "message": "Apify IG: no data returned"}

        row = items[0]
        # apify/instagram-scraper typical keys: followersCount, followers
        raw = (
            row.get("followersCount")
            or row.get("followers")
            or row.get("follower_count")
            or 0
        )
        followers = parse_scraped_number(raw)

        return {"success": True, "followers": followers}
