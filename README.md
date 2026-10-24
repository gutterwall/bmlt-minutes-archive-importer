# BMLT Minutes Archive Importer

A WordPress companion plugin for [BMLT Minutes](https://wordpress.org/plugins/bmlt-minutes/): import years of
historical service committee minutes into the BMLT Minutes archive, one document or link at a time, or a whole batch
with its own committee, meeting date and title for every file.

| | |
|---|---|
| Plugin | [`bmlt-minutes-archive-importer/`](bmlt-minutes-archive-importer) — usage in its [readme](bmlt-minutes-archive-importer/readme.txt) |
| Release file | `bmlt-minutes-archive-importer-<version>.zip` from [Releases](../../releases) (Plugins → Add New → Upload Plugin) |
| Requires | WordPress 6.0+, PHP 8.0+, BMLT Minutes (active) |
| License | GPL-2.0-or-later ([LICENSE](LICENSE)) |
| Changes | [CHANGELOG.md](CHANGELOG.md) |

Open **Minutes → Archive Import**:

- **Single Import** — committee, meeting date, a document (PDF, Word, Excel, PowerPoint, OpenDocument, TXT, RTF, CSV)
  or a link (Google Docs, Dropbox, OneDrive…), optional title, published or draft.
- **Bulk Import** — choose many files; a review table gives each its committee, date and title (default
  `YYYY-MM-DD-Committee-File name`), with "apply to all" and a tick box to leave files out. The batch continues past
  failures and ends with a report per file.

Imported records are ordinary BMLT Minutes records (its post type, Committee taxonomy and meta keys), and documents go
to the Media Library. Duplicates (same committee and date) are refused unless allowed. The importer keeps to BMLT
Minutes' permissions: committee-limited Minutes Managers import only into their committees, users who may not publish
get drafts, and uploading needs the upload permission.

Each release is built by CI from the tagged commit, and the zip carries a GitHub build provenance attestation:

```sh
gh attestation verify bmlt-minutes-archive-importer-1.3.0.zip --repo gutterwall/bmlt-minutes-archive-importer
sha256sum -c bmlt-minutes-archive-importer-1.3.0.zip.sha256
```

## Tests

All tests run against the real BMLT Minutes: `bin/install-bmlt-minutes.sh` downloads it from wordpress.org (version
and SHA-256 pinned) into `tests/dependencies/`.

| Command | What it covers | Needs |
|---|---|---|
| `vendor/bin/phpunit -c tests/wordpress/phpunit.xml.dist` | 63 WordPress integration tests: single and bulk imports and what BMLT Minutes then shows (its list, its document links); titles, dates (leap days, future dates, range), duplicates; document types (real PDF/TXT/CSV/RTF accepted; JPEG, HTML, PHP refused, also when renamed `.pdf`); BMLT Minutes' upload size limit; PHP upload errors, files beyond `max_file_uploads`, requests over `post_max_size`; permissions (administrator, editor, Minutes Manager, committee-limited manager, edit-only role, author, subscriber), nonces; the admin screen; meta key detection, including a customized minutes plugin, and the mapping; notices that cannot be spoofed by a link; uninstall on single sites and networks | `WP_TESTS_DIR` (`bin/install-wp-tests.sh`), `composer install`, `bin/install-bmlt-minutes.sh`; `WP_MULTISITE=1` for a network |
| `bin/e2e.sh <dir>` | the release zip on Apache and on nginx, driven in headless Chrome with real multipart uploads: Single Import (PDF, link, refused JPEG, duplicate), Bulk Import (review table, apply to all, per-row changes, skipped row, report), PHP's limits (`max_file_uploads`, `upload_max_filesize`, `post_max_size`), a committee-limited manager and a forged request, the public `[bmlt_minutes]` page with every document downloaded, uninstall | Apache, nginx, PHP-FPM, WP-CLI, Chrome, MySQL (see the script's header) |
| `vendor/bin/phpcs`, `vendor/bin/phpstan analyse` | WordPress security rules (escaping, nonces, input, SQL) and PHP 8.0+ compatibility; static analysis at level 6 | `composer install`, `bin/install-bmlt-minutes.sh` |

PHP's `is_uploaded_file()` cannot be satisfied from a test, so the integration tests hand uploads to WordPress's
sideload handling through the importer's `bmai_pre_upload` filter (same file type and size checks); real uploads are
covered by the end-to-end test. `BMAI_PLUGIN_FILE=<path>` runs the integration tests against another copy of the
plugin, and `PLUGIN_ZIP=<zip>` the end-to-end test (for example 1.2.0, to see what they catch).

CI ([`.github/workflows/ci.yml`](.github/workflows/ci.yml)) runs all of them on every push: WordPress 6.0 on PHP 8.0,
the latest WordPress on PHP 8.1 to 8.5, single site and multisite, MySQL 5.7 and 8.0, MariaDB 10.6, 11.4 and 11.8.

## Releasing

`bin/build.sh` checks that every version string agrees and that both changelogs have an entry, then builds the zip
from the committed files (byte-for-byte reproducible). A release is tagged `v<version>`; CI builds, tests and attests
the zip, and that zip is published.
