#!/usr/bin/env python3
"""
Garmin Connect sync helper for Runalyze.

Uses the unofficial `garminconnect` package (same login flow as the Garmin
Connect app/website) to fetch newly recorded activities and download them
as .fit files, so they can be handed to Runalyze's existing bulk-import
command (runalyze:activity:bulk-import).

This is NOT an officially supported Garmin API. Garmin can change their
internal login/API behaviour at any time, which would break this script
until the `garminconnect` package is updated upstream.

Usage:
    garmin_sync.py login <session_dir>
        Reads email (line 1) and password (line 2) from stdin, logs in and
        stores the resulting session tokens in <session_dir> for reuse by
        later "sync" calls (no password is stored on disk).

    garmin_sync.py sync <session_dir> <since_epoch> <target_dir>
        Resumes the saved session, fetches activities started after
        <since_epoch> (unix timestamp, UTC), downloads each as a .fit file
        into <target_dir>, and prints a JSON summary to stdout.

All output is a single JSON object on stdout:
    {"status": "ok", ...}
    {"status": "error", "message": "..."}
"""

import datetime
import io
import json
import sys
import zipfile
from pathlib import Path


def fail(message):
    print(json.dumps({"status": "error", "message": message}))
    sys.exit(1)


def import_garmin():
    try:
        from garminconnect import Garmin
        return Garmin
    except ImportError:
        fail("Python package 'garminconnect' is not installed. Install it with: pip3 install garminconnect")


def cmd_login(session_dir):
    Garmin = import_garmin()

    lines = sys.stdin.read().splitlines()
    if len(lines) < 2:
        fail("Expected email on stdin line 1 and password on stdin line 2.")
        return

    email, password = lines[0], lines[1]

    Path(session_dir).mkdir(parents=True, exist_ok=True)

    try:
        client = Garmin(email=email, password=password)
        client.login(tokenstore=session_dir)
    except Exception as e:
        fail(f"Login failed: {e}")
        return

    print(json.dumps({"status": "ok"}))


def parse_start_time(activity):
    for key in ("startTimeGMT", "startTimeLocal"):
        value = activity.get(key)
        if value:
            try:
                return int(
                    datetime.datetime.strptime(value, "%Y-%m-%d %H:%M:%S")
                    .replace(tzinfo=datetime.timezone.utc)
                    .timestamp()
                )
            except ValueError:
                continue
    return None


def extract_fit_from_zip(zip_bytes):
    with zipfile.ZipFile(io.BytesIO(zip_bytes)) as z:
        fit_names = [n for n in z.namelist() if n.lower().endswith('.fit')]
        if not fit_names:
            raise ValueError("downloaded archive did not contain a .fit file")
        return z.read(fit_names[0])


def cmd_sync(session_dir, since_epoch, target_dir):
    Garmin = import_garmin()

    try:
        since_epoch = int(since_epoch)
    except ValueError:
        fail("since_epoch must be an integer unix timestamp.")
        return

    try:
        client = Garmin()
        client.login(tokenstore=session_dir)
    except Exception as e:
        fail(f"No valid saved session, please log in again: {e}")
        return

    target = Path(target_dir)
    target.mkdir(parents=True, exist_ok=True)

    downloaded = []
    errors = []
    latest_epoch = since_epoch

    start = 0
    batch_size = 20

    try:
        while True:
            activities = client.get_activities(start=start, limit=batch_size)

            if not activities:
                break

            stop = False

            for activity in activities:
                activity_id = activity.get("activityId")
                activity_epoch = parse_start_time(activity)

                if activity_id is None or activity_epoch is None:
                    continue

                if activity_epoch <= since_epoch:
                    stop = True
                    break

                try:
                    zip_bytes = client.download_activity(
                        activity_id,
                        dl_fmt=Garmin.ActivityDownloadFormat.ORIGINAL,
                    )
                    fit_bytes = extract_fit_from_zip(zip_bytes)
                    fit_path = target / f"{activity_id}.fit"
                    fit_path.write_bytes(fit_bytes)
                    downloaded.append(fit_path.name)
                    latest_epoch = max(latest_epoch, activity_epoch)
                except Exception as e:
                    errors.append(f"activity {activity_id}: {e}")

            if stop or len(activities) < batch_size:
                break

            start += batch_size
    except Exception as e:
        fail(f"Fetching activity list failed: {e}")
        return

    print(json.dumps({
        "status": "ok",
        "downloaded": downloaded,
        "errors": errors,
        "latest_epoch": latest_epoch,
    }))


def main():
    if len(sys.argv) < 3:
        fail("Usage: garmin_sync.py login|sync <session_dir> [since_epoch] [target_dir]")
        return

    mode = sys.argv[1]
    session_dir = sys.argv[2]

    if mode == "login":
        cmd_login(session_dir)
    elif mode == "sync":
        if len(sys.argv) < 5:
            fail("Usage: garmin_sync.py sync <session_dir> <since_epoch> <target_dir>")
            return
        cmd_sync(session_dir, sys.argv[3], sys.argv[4])
    else:
        fail(f"Unknown mode '{mode}'.")


if __name__ == "__main__":
    main()
