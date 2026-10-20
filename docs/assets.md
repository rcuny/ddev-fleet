---
Author: Claude Code
Reviewer: none
Last updated: 2026-10-19
Type: documentation
---

# Assets (1.x)

A project's **assets** are the seed data its instances start from: database dumps, a tarball of the public files,
`.env` templates. They live as plain files in one directory per project on the host,
`/srv/fleet/assets/<project>/` (owned by the `fleet` user), and you manage them from the project's **Assets** tab in
the browser, with the `fleet asset:*` commands, or with `scp` and `rsync`. There is no index: what the tab shows is what
is on disk.

A deploy seeds the new instance from these files ([deploying from the assets](#deploying-from-the-assets)), and a
snapshot writes a running instance's database into `dumps/`.

Contents: [the screen](#the-screen) · [deploying from the assets](#deploying-from-the-assets) · [uploading in the browser](#uploading-in-the-browser) ·
[copying over SSH](#copying-over-ssh) · [names, paths and symbolic links](#names-paths-and-symbolic-links) ·
[the command line](#the-command-line) · [limits](#limits) · [permissions](#permissions) · [troubleshooting](#troubleshooting)

## The screen

Open **Projects**, pick a project, then the **Assets** tab (`/admin/projects/<project>/assets`).

| Part | What it does |
|---|---|
| Folder path | Where you are (`Assets / dumps / 2026`); every part is a link. |
| **Upload files** | Choose one or more files; they are sent one after another into the folder you are in. |
| **Create folder** | Makes a folder inside the current one (up to 8 folders deep). |
| **Rescan** | Reloads the list from the disk, to show files copied in over SSH. The list is read from the filesystem on every visit; there is no cache to refresh. |
| The list | Folders first, then files: name, size, modified time (UTC). On a phone the size and time sit under the name. A folder opens when you click its name. |
| **Download** | Sends the file as an attachment. Folders cannot be downloaded. |
| **Delete** | Asks for confirmation in a dialog, then removes the file. A folder is deleted only when it is empty; hidden leftovers of an upload assembly that was killed (older than an hour) do not count and go with it. |

Entries the screen cannot handle are listed but have no buttons: a **symbolic link** is shown with its target and
whether a deploy seeds it (see [symbolic links](#names-paths-and-symbolic-links)); the screen never follows it, and a file copied in with a name outside the allowed characters is shown as "name not
supported here" (rename it on the host). A folder with more than 2000 items shows the first 2000.

## Deploying from the assets

Every deploy and redeploy copies the project's assets into the new checkout **before `ddev start`**, as 0.x did, so the
project's own `post_deploy` lines find them (the `AssetsSeedStep`, phase `before_start`, tag priority 100). The job
log shows `assets: seeded N file(s) ...`.

| What | Rule |
|---|---|
| The tree | Copied below the checkout with the same relative paths (`.env`, `web/sites/default/settings.local.php`, ...). A file already there is replaced. |
| Text files | A regular file of at most 1 MiB that has no NUL byte and is valid UTF-8 has its `[[token]]` placeholders substituted (the context tokens of [secrets](secrets.md)). An unresolved placeholder stays as written and is logged by name, never by value. |
| Other files | A larger file, a binary file or a file that is not UTF-8 is copied byte for byte. |
| `dumps/` | Never substituted. Each file is **hard-linked** into the checkout (a copy when the link is refused, for example across filesystems), so a big dump costs no disk. A stale file at the destination is replaced, and a second pass changes nothing. |
| Symbolic links | Honoured when they end at a regular file inside the same project's assets, see [symbolic links](#names-paths-and-symbolic-links). Others are skipped and logged with the reason. |
| Skipped | Special files (logged), names the browser could not address, upload temporaries and anything named `.git`. Nothing is ever written into the checkout's `.git`. |
| Git status | Everything copied is added to `.git/info/exclude` (`dumps/` as a whole), so the project's `git status` stays clean. |
| Failure | An unreadable file, or a checkout folder that is a symbolic link where the assets have a folder, fails the deploy: the instance is `failed` and the log says which path. A project without an assets directory is a no-op with one log line. |

Importing the database stays the project's own business: put the import in the template's `post_deploy` (the project's own import command, reading the
file under `dumps/`). fleet does not run `ddev import-db` itself, and a files tarball
(`files.tar.gz`) is **not extracted**; both are as in 0.x.

## Uploading in the browser

The browser cuts each file into **chunks of up to 8 MiB** and sends them one request at a time, so neither php-fpm nor
Caddy ever sees a request bigger than a chunk. The server keeps the chunks in a staging area, checks every one, and
when the last has arrived it **assembles** them into a hidden temporary file next to the target and moves that into
place with one atomic rename. A half-finished file never appears in the list. When it is done the screen shows the
file's **sha256**, computed on the server while assembling, so you can compare it with `sha256sum` on your side.

- **Limit per file:** 2 GiB by default (`asset_upload_max_bytes` in the host settings table, in bytes). A bigger file is
  refused before anything is sent, with a message naming the limit and the directory to copy it into instead (see the
  next section). Change it on the **Uploads** tab of the host settings page (`/admin/host?tab=uploads`), in MiB (1 to 1 048 576); it applies to the next upload.
- **Resuming:** if the connection drops or the page is closed, choose **the same file again** (same name, size and
  modified time): the server tells the browser which chunks it already holds and only the missing ones are sent.
  A chunk that fails is retried three times before the upload is reported as failed.
- **Existing file:** if a file with that name exists, the browser asks before replacing it; without a yes nothing is
  touched. The replacement is atomic as well. A folder (or link) with that name is never replaced: the upload fails
  with a message and nothing is asked.
- **Staging area:** `/srv/fleet/uploads/<id>/` (owner only, mode 0700). Each chunk is written to a temporary file and
  renamed, so sending a chunk twice is harmless and out-of-order chunks are fine. Assembling deletes each chunk as it is
  copied, so the disk needs room for the file plus one chunk on top of the staged chunks. The check at the start of an
  upload looks at the filesystem of `uploads/` and at the one of the project's assets directory (they may differ) and
  refuses the upload when either is too full.
- **Abandoned uploads:** an upload nobody touched for 24 hours is deleted by the next upload that starts, and by
  `fleet asset:gc`. Run `fleet asset:gc` from cron or by hand if uploads are rare and a big failed one is sitting there.

Downloads go through php-fpm like every other page, so a download that runs longer than php-fpm's
`request_terminate_timeout` (240 seconds in the installed pool) is cut. For a multi-gigabyte file use `scp` or `rsync`.

Known gaps, kept on purpose: a download has no `Range` support (an interrupted one starts over), and an empty file
cannot be uploaded (create it over SSH).

## Copying over SSH

For anything above the limit, or whenever you already have the files on another machine, copy them straight into the
project's directory. The directory belongs to `fleet`, and php-fpm runs as `fleet`, so the files must be readable by
that user. With an administrator account that may use `sudo`:

```bash
# once: the project's directory (the browser creates it on the first upload, too)
ssh admin@fleet.example.com sudo -u fleet mkdir -p /srv/fleet/assets/blog

# a file
rsync -av --rsync-path="sudo rsync" --chown=fleet:fleet --chmod=D750,F640 \
  ./blog-db.sql.gz admin@fleet.example.com:/srv/fleet/assets/blog/

# a tree (keep the trailing slashes)
rsync -av --rsync-path="sudo rsync" --chown=fleet:fleet --chmod=D750,F640 \
  ./dumps/ admin@fleet.example.com:/srv/fleet/assets/blog/dumps/
```

Then press **Rescan** on the Assets tab, or run `fleet asset:list blog`. Do not copy symbolic links in (`rsync -a`
keeps them; add `--no-links`): the screen ignores them. Use file names with letters, digits, spaces and
`. _ - ( ) + @ , = ~` so the browser can download and delete them.

## Names, paths and symbolic links

Every path the browser or a command takes is relative to the project's directory and is checked before any file is
touched. A path is refused when it:

- is absolute, or has an empty, `.` or `..` part, or a backslash;
- contains a NUL, a control character, or an invisible or direction-changing character (such as a right-to-left override);
- has a part longer than 255 bytes, more than 8 parts, or more than 1024 bytes in total;
- uses a character outside the allowed set: letters and digits of any script (with combining marks), space, and
  `. _ - ( ) + @ , = ~`; a name may start with a dot (`.env`) but not with a space, may not end with a space, and may not
  start with `.fleet-upload-` (the hidden temporary files).

The same rules apply to names on disk that you copied in: a name outside the set is listed but cannot be addressed.

**Symbolic links are never followed.** The project's directory itself may not be a link (mount what you need instead);
a link anywhere on the way to a folder or file makes the request fail with "A symbolic link is in the way"; nothing is
ever written through one. A link inside the directory is listed, can be deleted (the link, not what it points at) and is
otherwise ignored by the screen.

**Deploy seeding honours links that stay inside the project's assets** (typically `dumps/default.sql` pointing at
`dump-prod-2026-10-15.sql`, so the project's `ddev init` finds its seed DB). The link is resolved without being
followed: each hop is read with `readlink`, resolved against the link's folder, and must stay below `assets/<project>/`
(an absolute target is accepted only when it is below it too); the folders on the way must be real folders; at most 4
hops. The result must be a regular file with a name that is seeded. What the instance gets:

- a link in `dumps/` to another file in `dumps/`: a **hard link** to the same inode as the target (a copy when the
  filesystem refuses), so `dumps/default.sql` is a plain file, nothing is stored twice and `ddev import-db` needs no
  link support;
- any other link: a **relative symbolic link** with the same name to the seeded target (the checkout mirrors the
  assets, and `git status` stays clean as for the other seeded files).

Skipped, with the reason in the deploy log (`assets: skipped "dumps/x.sql" (symbolic link to "...": <reason>)`) and on the
Assets tab and in `fleet asset:list`: a target outside the project's assets (`../..`, absolute), a dangling link, a link
to a folder or a special file, a loop or a chain over the cap, a link behind a folder that is a link, and a target with
a name that is never seeded (`.git`, unsupported characters).

A target that climbs out of the project's directory and back in (`../<project>/dumps/x.sql`) is refused as well: the
check is on the path as written, and a `..` above the assets directory is outside it, even when it lands in the same
project again. Use a path below the link's own folder (`../dumps/x.sql` from `web/`).

**Verified when it is opened.** The checks run while the deploy walks the tree, and the file is then opened and
verified: the opened file must be the regular file that was checked (by device and inode, before and after the open),
and the real path of the open descriptor (`/proc/self/fd`, so Linux only) must be inside the project's assets
directory. A link or another file swapped in between, or a folder on the way replaced by a link to somewhere else,
fails the deploy ("changed while it was being seeded", or "is not inside the project's assets") and nothing is copied
from it, so a deploy **cannot be made to read outside** the project's assets. The file is opened without blocking, so a
named pipe or another special file swapped in does not hang the deploy: it is refused the same way.

**Hard links.** A hard link is the same inode under another name, so it is a regular file like any other and is
copied. A hard link inside `assets/<project>/` to a file outside it is possible only for someone who can already write
in the assets and read the target, so it grants nothing new; a test pins this behaviour.

**The remaining limit:** whoever can write to `assets/<project>/` while a deploy runs can still make a deploy fail or
choose which file in the assets is seeded. Keep write access to the assets to the people who manage the project (the
Assets tab and `scp` as the fleet user are the intended ways in).

## Snapshot a running instance

The **Snapshot a running instance** card on the Assets tab lists the project's running instances. **Take snapshot** queues
a job that runs `ddev export-db` in the instance's checkout and saves the dump as
`dumps/default-<instance-id>-<UTC time>.sql.gz` (for example `dumps/default-blog--main-20261020T090000Z.sql.gz`), so it can
seed later instances. The dump is written to a hidden temporary file first and only then linked into place: a partial file
never shows under its name, and a snapshot never replaces an earlier one (two in the same second get a `-2` suffix). The job
needs 512 MiB free on the assets filesystem and its output and any failure are in the job log. A stopped, unknown or busy
instance is refused with a message. The card is for administrators, like the rest of the tab; press **Rescan** to see the
new file once the job is done.

## The command line

Run on the host as `fleet asset:<verb>` (the wrapper switches to the `fleet` user). All accept
`--format=table|json` (`--json` is the short form).

### `fleet asset:list <project> [<path>]`

Lists a folder of the project's assets (the top by default): type (`dir`, `file`, `link`), name, size and modified time.
With `--format=json` the output is one document:

```json
{"project": "blog", "path": "dumps", "entries": [
  {"name": "db.sql.gz", "path": "dumps/db.sql.gz", "type": "file", "size": 2048,
   "modified": "2026-10-19T08:15:00Z", "usable": true}
], "truncated": false}
```

`usable` is false for a link or a name the browser cannot address.

### `fleet asset:gc`

Deletes uploads that nobody touched for 24 hours and the hidden temporary files of an assembly that was killed (also
older than 24 hours). Prints what it removed; with `--format=json`:
`{"uploads": 1, "temporaryFiles": 0, "bytes": 52428800}`.

### `fleet asset:snapshot <instance-id>`

Dumps a running instance's database into its project's assets, the same job as the Assets tab's **Take snapshot** button
(see "Snapshot a running instance" below). The command queues a `snapshot` job and prints its number; a worker runs it.
With `--format=json`: `{"job": 12, "instance": "blog--main", "project": "blog", "status": "queued"}`. A stopped, unknown
or busy instance (it has a queued or running job) is refused with a message and the exit code of the table below, and
nothing is queued.

### Exit codes

| Code | Meaning |
|---|---|
| `0` | Done. |
| `1` | A value was refused: the path breaks the rules above (reported as `<path>: message`). |
| `2` | Not allowed now: a symbolic link is in the way, or (`asset:snapshot`) the instance is not running or is busy. |
| `3` | The project, the folder or the instance does not exist. |

In JSON mode a failure is `{"error": "...", "fields": {...}}` on stdout, like the project commands.

## Limits

| Limit | Value |
|---|---|
| One browser upload | 2 GiB by default (`asset_upload_max_bytes`); at most 65 536 chunks |
| One chunk | 64 KiB to 8 MiB (the browser sends 8 MiB) |
| Folder depth, name, whole path | 8, 255 bytes, 1024 bytes |
| Items shown in a folder | 2000 |
| Staged upload kept | 24 hours after its last chunk |
| Mode of what is created | folders 0750, files 0640, staging 0700 |

## Permissions

The Assets tab and every request behind it are for **administrators** (`ProjectVoter::SETTINGS`): a signed-in member
gets 403 and an anonymous request 401, like the other project pages. The upload requests are made by the page with a
CSRF token in the `X-CSRF-Token` header; forms (create folder, delete) carry it as `_token`; a cross-site request is
refused. Assets can hold database dumps with personal data: the files are mode 0640 and the staging area 0700, and
nothing here is served by Caddy directly.

## Troubleshooting

| Symptom | Cause and fix |
|---|---|
| "The file is X, more than the upload limit of Y" | Over the cap. Copy it in over SSH (the message names the directory). |
| "There is not enough free disk space for that file" | The disk holding `/srv/fleet` is nearly full. Free space, or copy the file to a bigger disk and mount it. |
| An upload stops at "The connection was lost" | Choose the same file again: it carries on from the last stored chunk. |
| "The upload has expired" | Nothing arrived for 24 hours; start it again. |
| A file copied in over SSH does not show | Press **Rescan**; check the owner (`fleet`) and that the path is under `/srv/fleet/assets/<project>/`. |
| "A symbolic link is in the way" | A link on the path. Replace it with a real directory, or copy the data instead of linking it. |
| "name not supported here" | The file name has characters outside the allowed set. Rename it on the host. |
| Download cut after four minutes | php-fpm's request time limit. Use `scp` or `rsync` for big files. |
