=== BMLT Minutes Archive Importer ===
Contributors: gutterwall
Tags: bmlt, minutes, archive, importer, documents, bulk import
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 8.0
Requires Plugins: bmlt-minutes
Stable tag: 1.3.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Companion importer for the BMLT Minutes WordPress plugin.

== Description ==

BMLT Minutes Archive Importer adds an Archive Import screen beneath the BMLT Minutes admin menu.

It is designed for historical minutes that need to be entered into an existing BMLT Minutes archive.

Features:

* Single-document archive importer.
* Bulk importer for multiple historical minutes documents in one batch.
* Bulk review table with an individual committee, month, day, year, title, and include/exclude checkbox for every file.
* Apply-to-all control for quickly assigning one committee/date to all rows, followed by per-row edits.
* Committee pull-downs populated from the committees already configured in BMLT Minutes.
* Upload PDF, DOC/DOCX, spreadsheet, presentation, ODT/ODS/ODP, TXT, RTF, or CSV minutes documents.
* Optional external URL on the single importer for Google Docs, Dropbox, OneDrive, and similar archives.
* Uploaded files are stored in the normal WordPress Media Library.
* Imported records are created in the existing BMLT Minutes post type and assigned to its Committee taxonomy.
* Duplicate protection for an existing committee/date combination, with an override option.
* Publish or Draft option.
* Bulk import continues after a failed row and shows an end-of-batch success/failure report.
* Automatic metadata detection with an Advanced Mapping panel for compatibility with customized/future BMLT Minutes versions.

== Installation ==

1. Install and activate BMLT Minutes first.
2. Upload the `bmlt-minutes-archive-importer` folder to `/wp-content/plugins/`, or upload the ZIP from Plugins -> Add Plugin -> Upload Plugin.
3. Activate BMLT Minutes Archive Importer.
4. Open Minutes -> Archive Import.
5. Use Single Import for one archive item, or Bulk Import for a batch.

== Bulk Import ==

1. Open Minutes -> Archive Import -> Bulk Import.
2. Select multiple archive documents at once.
3. Review the generated table.
4. Assign a Committee and Month / Day / Year to each row.
5. Optionally use Apply to all rows to set a common committee/date first, then edit exceptions.
6. Uncheck any files you do not want to import.
7. Choose Published or Draft and decide whether committee/date duplicates are permitted.
8. Click Import Selected Files.
9. Review the batch results notice for imported, failed, and skipped files.

The maximum number of files that can be selected in one HTTP request depends on the server's PHP `max_file_uploads` setting. The Bulk Import screen displays the current limit.

== Permissions ==

The importer follows BMLT Minutes' own permissions:

* Anyone who may edit minutes (administrators, editors, Minutes Managers) can open Archive Import.
* A user limited to some committees on their profile can import only into those committees (and their sub-committees).
* A user who may edit but not publish minutes gets drafts.
* Uploading documents needs the "upload_files" capability; without it only external links can be imported.
* Only the document types BMLT Minutes accepts are stored (PDF, Word, Excel, PowerPoint, OpenDocument, TXT, RTF, CSV),
  checked on the server whatever the browser allowed, within BMLT Minutes' upload size limit.

== Notes ==

This plugin does not create a second minutes archive. It imports records into BMLT Minutes.

If your BMLT Minutes installation uses nonstandard meta keys, open Compatibility / advanced mapping on the Archive Import screen and select the appropriate meeting-date, file-ID, and external-URL meta keys.

On multisite, the network's allowed upload types also apply. Networks created before WordPress 6.6 do not allow TXT, RTF or CSV files unless a network administrator adds `txt rtf csv` under Network Admin > Settings > Upload file types; until then those imports fail with "Sorry, you are not allowed to upload this file type."

== Changelog ==

= 1.3.1 =
* Documented: on multisite networks created before WordPress 6.6, TXT, RTF and CSV imports need those types added
  to the network's upload file types (see Notes). No code changes.

= 1.3.0 =
* Fixed: minutes for a date later than now (today's meeting imported in the morning, or later this year) were scheduled
  instead of published.
* Fixed: a Minutes Manager limited to some committees could import into any committee; the record was published
  without a committee and the import reported success.
* Fixed: users who may edit but not publish minutes could publish through the importer; they now get drafts.
* Fixed: users without upload permission could add files to the Media Library through the importer.
* Fixed: any file type WordPress allows (images, audio, video) could be uploaded; only minutes document types are
  accepted now.
* Fixed: in a bulk import, files beyond the server's max_file_uploads limit were silently dropped; they are now
  listed as failed.
* Fixed: an upload larger than post_max_size ended on "The link you followed has expired"; it now explains the limit.
* Fixed: result messages were read from the page address, so a link could show any text on the Archive Import page.
* Fixed: a mapping key with capital letters was saved but written in lower case.
* Fixed: when BMLT Minutes was inactive, the reason an import failed was not shown.
* Uses BMLT Minutes' own meta keys directly; the fallback keys now match BMLT Minutes.
* Uninstall removes the importer's settings (imported minutes and documents are kept).
* New filters: bmai_upload_overrides, bmai_pre_upload.

= 1.2.0 =
* Bulk imports now default post titles to YYYY-MM-DD-CommitteeName-Filename.
* The source document extension is removed from the generated title.
* A manually entered per-row title still overrides the generated title.

= 1.1.0 =
* Added Bulk Import tab.
* Added multiple-file upload and per-file review table.
* Added individual committee/date/title assignment per file.
* Added apply-to-all committee/date controls.
* Added include/exclude checkbox per row.
* Added batch duplicate checking and duplicate override.
* Added resilient batch processing that continues after individual failures.
* Added detailed batch result reporting.

= 1.0.0 =
* Initial release.
