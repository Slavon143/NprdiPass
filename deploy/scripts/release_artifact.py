#!/usr/bin/env python3
"""Build, verify and retain credential-free NordiPass executable releases."""
import argparse
import hashlib
import io
import json
import os
from pathlib import Path, PurePosixPath
import re
import shutil
import tarfile
import tempfile

ROOT = "nordipass-release"
DIRECTORIES = {"app", "bootstrap", "config", "database", "lang", "public", "resources", "routes", "vendor"}
FILES = {"artisan", "composer.json", "composer.lock", "package.json", "package-lock.json"}
EMPTY = ("bootstrap/cache", "storage/app/private", "storage/app/public", "storage/framework/cache/data", "storage/framework/sessions", "storage/framework/views", "storage/logs")


def canonical(value):
    return json.dumps(value, sort_keys=True, ensure_ascii=False, separators=(",", ":")).encode("utf-8")


def stream_digest(source):
    result = hashlib.sha256()
    for chunk in iter(lambda: source.read(1024 * 1024), b""):
        result.update(chunk)
    return result.hexdigest()


def digest(path):
    with open(path, "rb") as source:
        return stream_digest(source)


def allowed(name):
    parts = PurePosixPath(name).parts
    return (bool(parts) and "\\" not in name and not name.startswith("/")
            and all(p not in (".", "..", ".git", "auth.json") and not p.startswith(".env") for p in parts)
            and name not in ("public/hot", "public/storage") and not name.startswith("public/storage/")
            and not name.startswith("bootstrap/cache/")
            and not (parts[0] == "database" and re.search(r"\.(?:sqlite3?|db)(?:-(?:wal|shm|journal))?$", parts[-1], re.IGNORECASE))
            and (parts[0] in DIRECTORIES or name in FILES))


def add_bytes(archive, name, data):
    entry = tarfile.TarInfo(ROOT + "/" + name)
    entry.size = len(data)
    entry.mode = 0o644
    archive.addfile(entry, io.BytesIO(data))


def build(source, artifact, commit, ref, php_version, node_version, built_at):
    source = Path(source).resolve(strict=True)
    artifact = Path(artifact).absolute()
    if not re.fullmatch(r"[a-f0-9]{40}", commit):
        raise ValueError("A full lowercase Git commit SHA is required")
    if not re.fullmatch(r"8\.4\.\d+", php_version):
        raise ValueError("Build with the supported PHP 8.4 runtime")
    if not re.fullmatch(r"22\.\d+\.\d+", node_version):
        raise ValueError("The exact observed Node 22 build version is required")
    for required in ("artisan", "composer.lock", "app", "vendor", "public/build/manifest.json"):
        if not (source / required).exists():
            raise ValueError("Missing executable release input: " + required)
    members = {}
    for directory, dirs, files in os.walk(source, followlinks=False):
        for name in list(dirs):
            path = Path(directory) / name
            key = (path.relative_to(source)).as_posix()
            if not allowed(key):
                dirs.remove(name)
            elif path.is_symlink():
                raise ValueError("Symlinks are not executable release inputs")
        for name in files:
            path = Path(directory) / name
            key = path.relative_to(source).as_posix()
            if allowed(key):
                if path.is_symlink() or not path.is_file():
                    raise ValueError("Only regular files may enter a release")
                members[key] = digest(path)
    application = {key[4:]: sha for key, sha in members.items() if key.startswith("app/") and key.endswith(".php")}
    manifest = {"application": "NordiPass", "format_version": 2, "commit": commit, "ref": ref,
                "built_at": built_at, "php_version": php_version, "node_version": node_version,
                "catalog_capacity_schema": "table-v1",
                "application_sha256": hashlib.sha256(canonical(application)).hexdigest(),
                "dependencies_sha256": members["composer.lock"]}
    # Exclusive creation makes an accidental overwrite fail before losing an artifact.
    with artifact.open("xb") as output, tarfile.open(fileobj=output, mode="w:gz", format=tarfile.PAX_FORMAT) as archive:
        for key in sorted(members):
            archive.add(source / key, arcname=ROOT + "/" + key, recursive=False)
        for directory in EMPTY:
            entry = tarfile.TarInfo(ROOT + "/" + directory)
            entry.type = tarfile.DIRTYPE
            entry.mode = 0o755
            archive.addfile(entry)
        add_bytes(archive, "RELEASE.json", canonical(manifest))
        add_bytes(archive, "FILES.json", canonical(members))
    checksum = digest(artifact)
    with Path(str(artifact) + ".sha256").open("x", encoding="utf-8", newline="\n") as output:
        output.write(checksum + "  " + artifact.name + "\n")
    verify(artifact, Path(str(artifact) + ".sha256"))
    return manifest


def verify(artifact, checksum_file):
    artifact = Path(artifact)
    text = Path(checksum_file).read_text(encoding="utf-8").strip()
    match = re.fullmatch(r"([a-f0-9]{64}) [ *]([^\r\n/\\]+)", text)
    if not match or match[2] != artifact.name or digest(artifact) != match[1]:
        raise ValueError("Artifact checksum is missing, mismatched or bound to another filename")
    actual, metadata, seen = {}, {}, set()
    with tarfile.open(artifact, "r:gz") as archive:
        for entry in archive:
            if not entry.name.startswith(ROOT + "/") or "\\" in entry.name or any(p in (".", "..") for p in entry.name.split("/")):
                raise ValueError("Unsafe archive path")
            key = entry.name[len(ROOT) + 1:].rstrip("/")
            if key in seen or not (entry.isfile() or entry.isdir()) or entry.mode & 0o7000:
                raise ValueError("Duplicate or nonregular archive entry")
            seen.add(key)
            if entry.isdir():
                if not (allowed(key) or key in EMPTY):
                    raise ValueError("Unexpected archive directory")
                continue
            if not allowed(key) and key not in ("RELEASE.json", "FILES.json"):
                raise ValueError("Runtime data or credentials in release")
            stream = archive.extractfile(entry)
            if key in ("RELEASE.json", "FILES.json"):
                if entry.size > 16 * 1024 * 1024:
                    raise ValueError("Oversized release metadata")
                metadata[key] = json.load(stream)
            else:
                actual[key] = stream_digest(stream)
    manifest = metadata.get("RELEASE.json", {})
    application = {key[4:]: sha for key, sha in actual.items() if key.startswith("app/") and key.endswith(".php")}
    if (metadata.get("FILES.json") != actual or manifest.get("application") != "NordiPass"
            or manifest.get("format_version") not in (1, 2) or manifest.get("catalog_capacity_schema") != "table-v1"
            or (manifest.get("format_version") == 1 and manifest.get("node_version") != "22.x")
            or (manifest.get("format_version") == 2 and not re.fullmatch(r"22\.\d+\.\d+", str(manifest.get("node_version"))))
            or not re.fullmatch(r"[a-f0-9]{40}", str(manifest.get("commit")))
            or not re.fullmatch(r"8\.4\.\d+", str(manifest.get("php_version")))
            or manifest.get("application_sha256") != hashlib.sha256(canonical(application)).hexdigest()
            or manifest.get("dependencies_sha256") != actual.get("composer.lock")
            or not {"artisan", "composer.lock", "vendor/autoload.php", "public/build/manifest.json"}.issubset(actual)
            or not application):
        raise ValueError("Incomplete or inconsistent executable release manifest")
    return manifest, match[1]


def retain(artifact, checksum_file, archive_root):
    manifest, sha = verify(artifact, checksum_file)
    archive_root = Path(archive_root)
    if archive_root.is_symlink():
        raise ValueError("Archive root must not be a symlink")
    archive_root.mkdir(parents=True, exist_ok=True)
    target = archive_root / sha
    if target.exists():
        if target.is_symlink() or digest(target / "release.tar.gz") != sha or json.loads((target / "RELEASE.json").read_text()) != manifest:
            raise ValueError("Existing archive is inconsistent")
        return target
    temporary = Path(tempfile.mkdtemp(prefix=".retaining-", dir=archive_root))
    try:
        shutil.copyfile(artifact, temporary / "release.tar.gz")
        (temporary / "RELEASE.json").write_bytes(canonical(manifest))
        (temporary / "release.tar.gz.sha256").write_text(sha + "  release.tar.gz\n", encoding="utf-8")
        for path in temporary.iterdir():
            with path.open("r+b") as stream:
                stream.flush()
                os.fsync(stream.fileno())
        verify(temporary / "release.tar.gz", temporary / "release.tar.gz.sha256")
        temporary.rename(target)
        # Directory fsync makes the rename durable on the Linux deployment host.
        if os.name == "posix":
            descriptor = os.open(archive_root, os.O_RDONLY | os.O_DIRECTORY)
            try:
                os.fsync(descriptor)
            finally:
                os.close(descriptor)
    finally:
        if temporary.exists():
            shutil.rmtree(temporary)
    return target


def verify_tree(release_dir, archive_root):
    directory = Path(release_dir).resolve(strict=True)
    sha = (directory / 'ARTIFACT_SHA256').read_text().strip()
    if not re.fullmatch(r'[a-f0-9]{64}', sha):
        raise ValueError('Missing executable archive identity')
    retained = Path(archive_root) / sha
    manifest, actual_sha = verify(retained / 'release.tar.gz', retained / 'release.tar.gz.sha256')
    if actual_sha != sha or json.loads((directory / 'RELEASE.json').read_text()) != manifest:
        raise ValueError('Installed release differs from retained executable')
    with tarfile.open(retained / 'release.tar.gz') as archive:
        expected = json.load(archive.extractfile(ROOT + '/FILES.json'))
    actual = {}
    for parent, dirs, files in os.walk(directory, followlinks=False):
        for name in list(dirs):
            path = Path(parent) / name
            key = path.relative_to(directory).as_posix()
            if not allowed(key):
                dirs.remove(name)
            elif path.is_symlink():
                raise ValueError('Executable directory replaced by symlink')
        for name in files:
            path = Path(parent) / name
            key = path.relative_to(directory).as_posix()
            if allowed(key):
                if path.is_symlink():
                    raise ValueError('Executable file replaced by symlink')
                actual[key] = digest(path)
    if actual != expected:
        raise ValueError('Installed executable file set or content changed')
    return manifest


def prepare_public(release_dir, archive_root):
    """Expose only verified public bytes, independent of the operator's umask."""
    directory = Path(release_dir).absolute()
    if directory.is_symlink() or directory.resolve(strict=True) != directory:
        raise ValueError('Public preparation requires an explicit nonsymlink release')
    verify_tree(directory, archive_root)
    public = directory / 'public'
    if not public.is_dir() or public.is_symlink():
        raise ValueError('Missing regular public directory')
    directories, files = [public], []
    # Validate the complete public walk before changing any permissions. Never
    # follow or chmod runtime public/storage, shared storage or private inputs.
    for parent, dirs, names in os.walk(public, followlinks=False):
        for name in list(dirs):
            path = Path(parent) / name
            key = path.relative_to(directory).as_posix()
            if key == 'public/storage':
                dirs.remove(name)
                continue
            if not allowed(key) or path.is_symlink():
                raise ValueError('Unreviewed public directory')
            directories.append(path)
        for name in names:
            path = Path(parent) / name
            key = path.relative_to(directory).as_posix()
            if key == 'public/storage':
                continue
            if not allowed(key) or path.is_symlink() or not path.is_file() or path.stat().st_nlink != 1:
                raise ValueError('Unreviewed public file')
            files.append(path)
    # Static workers can traverse the release, but cannot list its private root.
    # Application directories, .env, cache, backups and storage retain their modes.
    directory.chmod(0o711)
    for path in directories:
        path.chmod(0o755)
    for path in files:
        path.chmod(0o644)
    return {'public_permissions_prepared': True, 'directories': len(directories), 'files': len(files)}


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    sub = parser.add_subparsers(dest="command", required=True)
    create = sub.add_parser("build")
    for name in ("source", "artifact", "commit", "ref", "php-version", "node-version", "built-at"):
        create.add_argument("--" + name, required=True)
    for command in ("verify", "retain"):
        child = sub.add_parser(command)
        child.add_argument("--artifact", required=True)
        child.add_argument("--checksum-file", required=True)
        if command == "retain":
            child.add_argument("--archive-root", required=True)
    tree = sub.add_parser('verify-tree')
    tree.add_argument('--release-dir', required=True)
    tree.add_argument('--archive-root', required=True)
    public = sub.add_parser('prepare-public')
    public.add_argument('--release-dir', required=True)
    public.add_argument('--archive-root', required=True)
    arguments = vars(parser.parse_args())
    command = arguments.pop("command")
    try:
        result = globals()[command.replace('-', '_')](**arguments)
        print(str(result) if isinstance(result, Path) else json.dumps(result, ensure_ascii=False))
    except (ValueError, OSError, tarfile.TarError, KeyError, TypeError) as error:
        parser.exit(1, "Release operation failed: " + str(error) + "\n")


if __name__ == "__main__":
    main()
