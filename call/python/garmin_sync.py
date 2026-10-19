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

    garmin_sync.py sync <session_dir> <since_epoch> <target_dir> <max_activities> [max_seconds]
        Resumes the saved session, lists all activities started after
        <since_epoch> (unix timestamp, UTC) and downloads them OLDEST first
        (.fit, or .tcx/.gpx for older uploads) into <target_dir>, stopping
        after <max_activities> or <max_seconds> (default 45). "resume_epoch"
        in the result is the start time of the newest processed activity -
        use it as since_epoch for the next call to continue seamlessly.

All output is a single JSON object on stdout:
    {"status": "ok", ...}
    {"status": "error", "message": "..."}
"""

import datetime
import io
import json
import sys
import time
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


IMPORTABLE_EXTENSIONS = ('.fit', '.tcx', '.gpx')


def extract_activity_file(zip_bytes):
    """Return (extension, bytes) of the activity file inside Garmin's 'original' zip.

    Newer devices upload .fit, older uploads (or imports from other platforms)
    can be .tcx or .gpx - Runalyze imports all of them.
    """
    with zipfile.ZipFile(io.BytesIO(zip_bytes)) as z:
        for ext in IMPORTABLE_EXTENSIONS:
            names = [n for n in z.namelist() if n.lower().endswith(ext)]
            if names:
                return ext, z.read(names[0])
    raise ValueError("archive contains no .fit/.tcx/.gpx file (manually entered activity?)")


def list_activities_since(client, since_epoch):
    """All activities started after since_epoch, oldest first (only metadata, cheap)."""
    result = []
    start = 0
    batch_size = 100

    while True:
        activities = client.get_activities(start=start, limit=batch_size)

        if not activities:
            break

        reached_boundary = False

        for activity in activities:
            activity_id = activity.get("activityId")
            activity_epoch = parse_start_time(activity)

            if activity_id is None or activity_epoch is None:
                continue

            if activity_epoch <= since_epoch:
                reached_boundary = True
                break

            result.append((activity_epoch, activity_id))

        if reached_boundary or len(activities) < batch_size:
            break

        start += batch_size

    result.sort()
    return result


def cmd_sync(session_dir, since_epoch, target_dir, max_activities, max_seconds="45"):
    Garmin = import_garmin()

    try:
        since_epoch = int(since_epoch)
        max_activities = int(max_activities)
        max_seconds = float(max_seconds)
    except ValueError:
        fail("since_epoch, max_activities and max_seconds must be numbers.")
        return

    started = time.monotonic()

    try:
        client = Garmin()
        client.login(tokenstore=session_dir)
    except Exception as e:
        fail(f"No valid saved session, please log in again: {e}")
        return

    target = Path(target_dir)
    target.mkdir(parents=True, exist_ok=True)

    try:
        pending = list_activities_since(client, since_epoch)
    except Exception as e:
        fail(f"Fetching activity list failed: {e}")
        return

    downloaded = []
    errors = []
    # Activities are processed OLDEST first, so the newest processed start time
    # is exactly where the next run continues - nothing is skipped and nothing
    # is downloaded twice, however many clicks it takes.
    newest_processed_epoch = None
    aborted = None

    for activity_epoch, activity_id in pending:
        if len(downloaded) + len(errors) >= max_activities or time.monotonic() - started > max_seconds:
            break

        label = f"{datetime.datetime.fromtimestamp(activity_epoch, datetime.timezone.utc):%d.%m.%Y} (Garmin-ID {activity_id})"

        try:
            zip_bytes = client.download_activity(
                activity_id,
                dl_fmt=Garmin.ActivityDownloadFormat.ORIGINAL,
            )
            ext, data = extract_activity_file(zip_bytes)
            path = target / f"{activity_id}{ext}"
            path.write_bytes(data)
            downloaded.append(path.name)
        except (ValueError, zipfile.BadZipFile) as e:
            # permanent: there simply is no importable file for this activity
            errors.append(f"{label}: {e}")
        except Exception as e:
            if "404" in str(e):
                errors.append(f"{label}: no file on Garmin Connect (404)")
            else:
                # temporary (rate limit, network, ...): stop here WITHOUT skipping
                # this activity, the next click continues exactly at it
                aborted = f"{label}: {e}"
                break

        newest_processed_epoch = activity_epoch

    processed = len(downloaded) + len(errors)

    print(json.dumps({
        "status": "ok",
        "downloaded": downloaded,
        "errors": errors,
        "total": len(pending),
        "remaining": len(pending) - processed,
        "more_available": processed < len(pending),
        "aborted": aborted,
        # Only meaningful when something was actually processed; the caller
        # keeps the previous since_epoch otherwise.
        "resume_epoch": newest_processed_epoch,
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
        if len(sys.argv) < 6:
            fail("Usage: garmin_sync.py sync <session_dir> <since_epoch> <target_dir> <max_activities> [max_seconds]")
            return
        cmd_sync(session_dir, sys.argv[3], sys.argv[4], sys.argv[5], *sys.argv[6:7])
    else:
        fail(f"Unknown mode '{mode}'.")


if __name__ == "__main__":
    main()
