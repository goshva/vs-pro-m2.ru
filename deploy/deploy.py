#!/usr/bin/env python3
"""
Deploy changed files to the reg.ru hosting over SFTP (secure FTP, over the
same SSH access the panel calls "SSH, SFTP" — there is no separate plain-FTP
account on this hosting).

What it considers "the project": every file `git ls-files` tracks, plus
whatever is listed under "extra_files" in deploy.config.json (for files that
must exist on the server but are intentionally kept out of git, such as
includes/config.local.php with the SMTP password).

How it decides what's "changed": it hashes (sha256) every one of those files
locally, asks the server (over the existing SSH connection, one `find` +
`sha256sum` call) to hash its own copy of the site, and uploads only the
files whose hash differs or that don't exist remotely yet. Nothing is ever
deleted on the server unless you pass --prune, and --prune only *lists* the
remote-only files by default — add --prune --yes to actually delete them.

Requires: pip install --user paramiko   (already installed in this project's
working environment; on a fresh machine run that once first).

Usage:
    python deploy/deploy.py                  # diff + upload changed files
    python deploy/deploy.py --dry-run         # only show what would change
    python deploy/deploy.py --prune           # also list remote-only files
    python deploy/deploy.py --prune --yes     # ...and delete them
    python deploy/deploy.py --file path/to/one/file.php   # force-upload one file

Config: copy deploy.config.example.json to deploy.config.json (git-ignored)
and fill in the real host/user/password (or an SSH key path).
"""
from __future__ import annotations

import argparse
import hashlib
import json
import subprocess
import sys
from pathlib import Path

try:
    import paramiko
except ImportError:
    in_venv = sys.prefix != getattr(sys, "base_prefix", sys.prefix)
    pip_cmd = "python -m pip install paramiko" if in_venv else "python -m pip install --user paramiko"
    sys.exit(f"Missing dependency 'paramiko'. Install it once with:\n  {pip_cmd}")

SCRIPT_DIR = Path(__file__).resolve().parent
PROJECT_ROOT = SCRIPT_DIR.parent
CONFIG_PATH = SCRIPT_DIR / "deploy.config.json"

# Always excluded even if somehow tracked by git — never wanted on the server.
HARD_EXCLUDE_DIRS = {".git", "build", "node_modules", "deploy"}


def load_config() -> dict:
    if not CONFIG_PATH.exists():
        sys.exit(
            f"Missing {CONFIG_PATH}.\n"
            f"Copy deploy.config.example.json to deploy.config.json and fill it in."
        )
    with open(CONFIG_PATH, encoding="utf-8") as f:
        return json.load(f)


def local_project_files(extra_files: list[str]) -> list[str]:
    """Every git-tracked file, plus the configured extras — relative, forward-slash paths."""
    out = subprocess.run(
        ["git", "-C", str(PROJECT_ROOT), "ls-files"],
        capture_output=True, text=True, check=True, encoding="utf-8",
    ).stdout
    tracked = [line.strip() for line in out.splitlines() if line.strip()]
    files = set(tracked)
    for rel in extra_files:
        rel = rel.replace("\\", "/")
        if (PROJECT_ROOT / rel).is_file():
            files.add(rel)
        else:
            print(f"  (skipping extra_files entry, not found locally: {rel})")

    def excluded(rel: str) -> bool:
        parts = rel.split("/")
        return any(p in HARD_EXCLUDE_DIRS for p in parts[:-1]) or parts[0] in HARD_EXCLUDE_DIRS

    return sorted(f for f in files if not excluded(f))


def sha256_of(path: Path) -> str:
    h = hashlib.sha256()
    with open(path, "rb") as f:
        for chunk in iter(lambda: f.read(1 << 20), b""):
            h.update(chunk)
    return h.hexdigest()


def local_hashes(files: list[str]) -> dict[str, str]:
    return {rel: sha256_of(PROJECT_ROOT / rel) for rel in files}


def remote_hashes(ssh: "paramiko.SSHClient", remote_path: str) -> dict[str, str]:
    """One remote `find | sha256sum` call — cheap, no per-file round trips."""
    cmd = (
        f"cd {shquote(remote_path)} && "
        "find . -type f "
        "-not -path './.git/*' -not -path './build/*' -not -path './node_modules/*' "
        "-not -path './deploy/*' "
        "-exec sha256sum {} +"
    )
    stdin, stdout, stderr = ssh.exec_command(cmd, timeout=60)
    out = stdout.read().decode("utf-8", "replace")
    err = stderr.read().decode("utf-8", "replace")
    code = stdout.channel.recv_exit_status()
    if code not in (0, 1):  # find/sha256sum exit 1 just means "no files matched" on some systems
        raise RuntimeError(f"remote hashing failed (exit {code}): {err.strip()}")
    hashes = {}
    for line in out.splitlines():
        line = line.rstrip("\n")
        if not line or "  " not in line:
            continue
        digest, _, relpath = line.partition("  ")
        relpath = relpath.strip()
        if relpath.startswith("./"):
            relpath = relpath[2:]
        hashes[relpath] = digest
    return hashes


def shquote(s: str) -> str:
    return "'" + s.replace("'", "'\\''") + "'"


def ensure_remote_dir(sftp: "paramiko.SFTPClient", remote_dir: str, cache: set[str]) -> None:
    if remote_dir in ("", ".", "/") or remote_dir in cache:
        return
    parent = remote_dir.rsplit("/", 1)[0] if "/" in remote_dir else ""
    if parent:
        ensure_remote_dir(sftp, parent, cache)
    try:
        sftp.stat(remote_dir)
    except IOError:
        sftp.mkdir(remote_dir)
    cache.add(remote_dir)


def connect(cfg: dict) -> "paramiko.SSHClient":
    ssh = paramiko.SSHClient()
    ssh.set_missing_host_key_policy(paramiko.AutoAddPolicy())
    auth = cfg.get("auth", {})
    kwargs = dict(
        hostname=cfg["host"],
        port=cfg.get("port", 22),
        username=cfg["username"],
        timeout=20,
        allow_agent=False,
        look_for_keys=False,
    )
    method = auth.get("method", "password")
    if method == "key":
        key_path = Path(auth["key_path"]).expanduser()
        kwargs["pkey"] = paramiko.Ed25519Key.from_private_key_file(str(key_path))
    else:
        kwargs["password"] = auth["password"]
    ssh.connect(**kwargs)
    return ssh


def main() -> int:
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument("--dry-run", action="store_true", help="show what would change, upload nothing")
    ap.add_argument("--prune", action="store_true", help="list files present on the server but not locally")
    ap.add_argument("--yes", action="store_true", help="with --prune, actually delete the remote-only files")
    ap.add_argument("--file", action="append", default=[], help="force-check/upload one extra path (repeatable)")
    args = ap.parse_args()

    cfg = load_config()
    extra_files = list(cfg.get("extra_files", [])) + args.file

    print(f"Project root: {PROJECT_ROOT}")
    files = local_project_files(extra_files)
    print(f"Tracking {len(files)} local files (git-tracked + extras).")

    print("Hashing local files...")
    lhash = local_hashes(files)

    print(f"Connecting to {cfg['username']}@{cfg['host']}...")
    ssh = connect(cfg)
    try:
        print("Hashing remote files (one `find | sha256sum` call)...")
        rhash = remote_hashes(ssh, cfg["remote_path"])

        changed = [f for f in files if lhash[f] != rhash.get(f)]
        unchanged = len(files) - len(changed)
        remote_only = sorted(set(rhash) - set(files))

        print(f"\n{len(changed)} changed, {unchanged} unchanged.")
        if changed:
            for f in changed:
                tag = "new   " if f not in rhash else "modify"
                print(f"  [{tag}] {f}")
        else:
            print("Nothing to deploy.")

        if args.prune and remote_only:
            print(f"\n{len(remote_only)} file(s) on the server not tracked locally:")
            for f in remote_only:
                print(f"  [remote-only] {f}")
            if args.yes:
                sftp = ssh.open_sftp()
                for f in remote_only:
                    remote_file = cfg["remote_path"].rstrip("/") + "/" + f
                    sftp.remove(remote_file)
                    print(f"  deleted {f}")
                sftp.close()
            else:
                print("  (pass --prune --yes to delete these)")

        if not changed:
            return 0
        if args.dry_run:
            print("\n--dry-run: not uploading.")
            return 0

        print("\nUploading...")
        sftp = ssh.open_sftp()
        dir_cache: set[str] = set()
        remote_root = cfg["remote_path"].rstrip("/")
        for f in changed:
            remote_file = remote_root + "/" + f
            remote_dir = remote_file.rsplit("/", 1)[0]
            ensure_remote_dir(sftp, remote_dir, dir_cache)
            sftp.put(str(PROJECT_ROOT / f), remote_file)
            print(f"  uploaded {f}")
        sftp.close()
        print(f"\nDone — {len(changed)} file(s) uploaded.")
        return 0
    finally:
        ssh.close()


if __name__ == "__main__":
    sys.exit(main())
