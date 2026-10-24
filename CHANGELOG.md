# Changelog

## 1.3.1 (2026-10-24)

Documented
- On multisite, the network's allowed upload types apply to imports. Networks created before WordPress 6.6 do not
  allow TXT, RTF or CSV files (and keep that list after upgrading), so those imports fail with "Sorry, you are not
  allowed to upload this file type." until a network administrator adds `txt rtf csv` under Network Admin > Settings >
  Upload file types. The readme's Notes now say so.

Tests
- The integration tests set the network's upload types explicitly, and check that a type the network does not allow
  is refused cleanly; they passed on a WordPress 6.0 network only by luck of its age before.
- The end-to-end test works with nginx older than 1.19.5, and no longer races WordPress's login page script.

No code changes: the plugin behaves exactly as 1.3.0.

## 1.3.0 (2026-10-22)

Fixed
- Minutes dated later than the moment of import (today's meeting imported before noon, or a date later this year)
  were saved with a future post date, so WordPress **scheduled** them instead of publishing them.
- A Minutes Manager limited to some committees (BMLT Minutes' committee scope) could import into any committee.
  BMLT Minutes then stripped the committee, leaving a **published record with no committee**, and the importer
  reported success. The importer now refuses committees outside the user's scope, and refuses any record whose
  committee could not be assigned.
- Users who may edit but not publish minutes could publish through the importer; they now get drafts, and are told.
- Users without the `upload_files` capability could add files to the Media Library through the importer.
- Any file type WordPress allows (images, audio, video, and HTML for administrators) could be uploaded; only the
  document types BMLT Minutes accepts are stored now, checked on the server.
- Bulk import: files beyond PHP's `max_file_uploads` were dropped without a word; each is now reported as failed.
- An upload larger than `post_max_size` ended on WordPress's "The link you followed has expired"; the importer now
  explains the limit.
- Result messages were taken from the page address (`bmai_message`), so a crafted link could show any text on the
  Archive Import page. They are now kept server-side for the user who imported.
- A mapping key with capital letters was saved as typed but written in lower case.
- When BMLT Minutes was inactive, the reason an import failed was not shown.

Changed
- BMLT Minutes' own meta keys (`_bmlt_minutes_date`, `_bmlt_minutes_attachment_id`, `_bmlt_minutes_url`) are used
  directly when BMLT Minutes provides the post type; the fallback keys now match them.
- `Requires Plugins: bmlt-minutes`; uninstall removes the importer's settings and pending notices (imported minutes and
  documents are kept).
- Filters `bmai_upload_overrides` and `bmai_pre_upload`.

## 1.2.0

- Bulk imports default post titles to YYYY-MM-DD-CommitteeName-Filename.
