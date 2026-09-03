import time
import json
import requests
from api.config import Config

class BrightDataClient:
    def __init__(self):
        self.base_url = 'https://api.brightdata.com/datasets/v3'

    def _headers(self):
        token = Config.get_key('brightdata_api_token', 'BRIGHTDATA_API_TOKEN')
        if not token:
            return None
        return {
            'Authorization': f'Bearer {token}',
            'Content-Type': 'application/json'
        }

    def _normalize_records(self, data):
        if not data:
            return []
        if isinstance(data, list):
            return data
        return [data]

    def _parse_json_response(self, text: str):
        text = text.strip() if text else ''
        if not text:
            return []
        try:
            return json.loads(text)
        except Exception:
            records = []
            for line in text.splitlines():
                line = line.strip()
                if line:
                    try:
                        records.append(json.loads(line))
                    except Exception:
                        pass
            return records

    def trigger_dataset(self, dataset_id: str, inputs: list) -> dict:
        """Triggers an asynchronous Bright Data dataset scraping job and returns snapshot_id in ~0.5s."""
        headers = self._headers()
        if not headers:
            return {'success': False, 'message': 'Bright Data API Token is missing.'}

        url = f"{self.base_url}/trigger?dataset_id={dataset_id}&include_errors=true"
        try:
            res = requests.post(url, headers=headers, json={'input': inputs}, timeout=10)
            if res.status_code in (200, 202):
                data = res.json()
                snapshot_id = data.get('snapshot_id')
                if snapshot_id:
                    return {'success': True, 'snapshot_id': snapshot_id}
                return {'success': False, 'message': 'No snapshot_id returned by Bright Data.'}
            return {'success': False, 'message': f"Bright Data HTTP {res.status_code}: {res.text[:200]}"}
        except Exception as e:
            return {'success': False, 'message': str(e)}

    def check_snapshot(self, snapshot_id: str) -> dict:
        """Checks progress of snapshot_id and downloads data if ready in ~0.3s."""
        headers = self._headers()
        if not headers:
            return {'success': False, 'message': 'Bright Data API Token is missing.'}

        try:
            progress_url = f"{self.base_url}/progress/{snapshot_id}"
            res = requests.get(progress_url, headers=headers, timeout=10)
            if res.status_code == 200:
                status = res.json().get('status')
                if status == 'ready':
                    snapshot_url = f"{self.base_url}/snapshot/{snapshot_id}?format=json"
                    snap_res = requests.get(snapshot_url, headers=headers, timeout=15)
                    if snap_res.status_code == 200:
                        records = self._parse_json_response(snap_res.text)
                        return {'success': True, 'status': 'done', 'data': self._normalize_records(records)}
                    return {'success': False, 'status': 'error', 'message': f"Snapshot download failed (HTTP {snap_res.status_code})"}
                elif status == 'failed':
                    return {'success': False, 'status': 'failed', 'message': 'Bright Data Scraping Job Failed.'}
                else:
                    return {'success': True, 'status': 'building', 'message': 'Scraping in progress...'}
            return {'success': False, 'status': 'error', 'message': f"Progress check HTTP {res.status_code}"}
        except Exception as e:
            return {'success': False, 'status': 'error', 'message': str(e)}

    def scrape(self, dataset_id: str, inputs: list, max_wait_seconds: int = 45) -> dict:
        """
        Scrapes a Bright Data dataset using the /scrape endpoint.
        Tries synchronous first, then falls back to polling if HTTP 202 is returned.
        Waits up to 45 seconds for Bright Data to complete web scraping.
        """
        headers = self._headers()
        if not headers:
            return {'success': False, 'message': 'Bright Data API Token is missing or not configured. Please enter your BRIGHTDATA_API_TOKEN in Dashboard Scraper Settings.'}

        url = f"{self.base_url}/scrape?dataset_id={dataset_id}&notify=false&include_errors=true"
        try:
            response = requests.post(
                url,
                headers=headers,
                json={'input': inputs},
                timeout=45
            )
        except requests.exceptions.RequestException as e:
            return {'success': False, 'message': f"Request Exception: {str(e)}"}

        if response.status_code == 200:
            try:
                data = self._parse_json_response(response.text)
                return {'success': True, 'data': self._normalize_records(data)}
            except Exception as e:
                return {'success': False, 'message': f"JSON parsing failed: {str(e)}"}

        if response.status_code == 202:
            try:
                decoded = response.json()
                snapshot_id = decoded.get('snapshot_id')
                if not snapshot_id:
                    return {'success': False, 'message': 'Bright Data Queued This Job But Returned No snapshot_id.'}
                return self.poll_and_download(snapshot_id, max_wait_seconds)
            except Exception as e:
                return {'success': False, 'message': f"Failed parsing 202 response: {str(e)}"}

        detail = response.text[:300]
        return {'success': False, 'message': f"Bright Data HTTP {response.status_code}: {detail}"}

    def poll_and_download(self, snapshot_id: str, max_wait_seconds: int = 45) -> dict:
        """Polls for progress and downloads snapshot when ready."""
        waited = 0
        interval = 3
        while waited < max_wait_seconds:
            time.sleep(interval)
            waited += interval

            try:
                progress_url = f"{self.base_url}/progress/{snapshot_id}"
                progress_res = requests.get(progress_url, headers=self._headers(), timeout=10)
                if progress_res.status_code == 200:
                    status = progress_res.json().get('status')
                    if status == 'ready':
                        snapshot_url = f"{self.base_url}/snapshot/{snapshot_id}?format=json"
                        snapshot_res = requests.get(snapshot_url, headers=self._headers(), timeout=20)
                        if snapshot_res.status_code == 200:
                            data = self._parse_json_response(snapshot_res.text)
                            return {'success': True, 'data': self._normalize_records(data)}
                        return {'success': False, 'message': f"Failed downloading snapshot (HTTP {snapshot_res.status_code})"}
                    elif status == 'failed':
                        return {'success': False, 'message': 'Bright Data Job Failed.'}
            except Exception:
                pass

        return {'success': False, 'message': f"Timed Out Waiting For Bright Data Scraper After {max_wait_seconds}s."}

