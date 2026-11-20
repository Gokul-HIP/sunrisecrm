# Agent prompt: Content Calendar, phase 2 (UI alignment, Add client popup, Import)

Good work on phase 1. I looked at the screenshots of your build. This phase has three parts: (A) fix the UI alignment, (B) turn **Add client** into a popup, (C) add an **Import** feature that reads a CSV or spreadsheet, detects the columns automatically, creates them and adds the rows to the right dates for a client.

Same ground rules as phase 1: do not commit, do not touch `app/Config/Database.php`, `system/*`, `writable/logs`, do not run anything on live, do not change Tasks / Events / Timesheets / Messages / exports. Production is MySQL 8.0.46 strict, local is MariaDB 10.11, so all SQL must run on both. Check permissions on the server for every new endpoint. Escape all output. Soft delete.

Reference mock (interactive, the design source of truth for spacing and layout): https://claude.ai/artifact/8NsFwgzpVHrBtyWpqSvWRQ

---

## A. Fix the UI alignment

From your screenshots, these are the problems I can see. Compare your page with the mock at the **same window width** (1360 px and 390 px) and make the spacing, sizes and alignment match. Fix anything else that does not line up, not only this list.

1. **One page wrapper for every screen.** The calendar page, the Add content page and the Add client page start at different left edges and have different widths. Use one wrapper (same max-width, same left edge, same padding) for the whole module, so the title "Content Calendar" and the page titles of the other screens start at exactly the same x position. Page header action buttons align to the right edge of that wrapper.
2. **Month row.** The ‹ and › buttons are taller than the pills and sit at opposite ends of the row with a large empty gap before the right one. Make both arrows exactly 44 × 44, vertically centered with the pills, directly next to the scrolling strip. Show an arrow only when the strip actually overflows; otherwise hide both. All month pills must have the same height (76 px) and the same inner layout: small uppercase caption, month and year (bold), post count. Fix the grammar: "1 post", "0 posts", "2 posts".
3. **+ Add month** button: same height as the pills, dashed border `#78716c`, dark text, caption line showing the month it will add. It is too faint now.
4. **Filter bar.** Put the labels back as in the mock (Type, Goal, Status each with its select), all controls 44 px high, aligned on one baseline, wrapping neatly on narrow screens. Use "All types", "All goals", "All status".
5. **Day panel.** Heading row: small uppercase weekday and large date on the left, **+ Add content** on the right of the same row (not below it). Then the special-day input, then the item cards. Item cards: type pill and status pill on one row, title, the label / value list, Edit and Delete buttons at the bottom left, equal height.
6. **Month grid.** Weekday headers left-aligned with the same 10 px padding as the date numbers (not centered). All day cells the same height, date number top-left, today's number in a filled accent circle, chips full width inside the cell with no overflow.
7. **Add content form** is a single narrow column and very long. Make it a responsive grid like the mock: `display:grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 14px`. Short fields (date, selects, text, number, link, checkbox, file) sit side by side; long text and multi-select chips span the full width. Keep `align-items:start` so a taller cell (one that has the Add new / Delete option row) does not stretch its neighbours. The "Add new …" box, **+ Add** and **Delete option** share one row; the box is flexible, the two buttons are fixed; all three are exactly 44 px high and aligned with the select above them. The right-hand preview card stays beside the form on wide screens and moves below it on narrow screens. Replace the odd preview caption ("Type: Title is how the post looks on its day.") with: "Type, status and title drive the colored chip, the filters and the counters. Every other field shows in the day panel and the table."
8. **Required marks** (the red asterisk) stay on the same line as their label.
9. **Spacing scale.** Use one scale (4, 8, 12, 16, 24 px) for gaps and padding on all new screens and popups. Same card radius, same border color, same input height (44 px), same font sizes as the mock.
10. Check **focus states** (visible ring), disabled buttons (clearly dimmed, no pointer), and that nothing overflows horizontally at 390 px except the month grid and tables, which scroll inside their own box.

## B. Add client as a popup

Remove the separate Add client page. **+ Add client** (the dashed pill next to the client tabs) opens a **popup** over the calendar. Delete the page route and the `add_client` view once the popup works (or make the old URL redirect to the calendar).

Popup contents, same look as the Add column popup (480–520 px wide, 16 px radius, 22 px padding, backdrop `rgba(28,25,23,.5)`):
- Title "Add client calendar".
- **Client name** (required, unique among non-deleted calendars, case-insensitive, max 120 chars).
- **Start with the columns of**: Blank (core columns only) or any existing client.
- A **"Columns this calendar will start with"** list below, as small chips (column name and type), updating when the dropdown changes (this is the preview you built on the page, now inside the popup).
- Inline error line (`role="alert"`), **Create calendar** and **Cancel** buttons.
- Behaviour: focus goes to the name field on open; Esc and the backdrop click close it (ask before closing if the name field has text); focus is trapped inside; page scroll is locked while it is open; focus returns to the **+ Add client** pill on close. After creating, switch to the new client's tab without a page reload.
- Admin only, as before.

Use one shared modal component for Add client, Add / Edit column, Copy columns, and the new Import wizard, so they all look and behave the same.

## C. Import (CSV and spreadsheets)

### C1. Where and who
- A new **Import** button in the page header, left of **Columns** (secondary button style).
- Permission: the existing `add` permission is required to import rows. **Creating new columns** during import needs admin. **Creating new options** (new status / content type / goal values) needs `manage_options` or admin. If the user lacks one of these, that part is shown in the preview as locked ("Needs admin") and those values / columns are skipped, never silently created. Do not add a new permission card; tell me in the report if you think one is needed.

### C2. The wizard (popup, 3 steps, wide popup up to 960 px, scrolls inside)

**Step 1: Choose**
- **Client**: dropdown of all calendars the user can see, preselected to the active client, plus the choice **"Create a new client from this file"** (admin only; shows a name field).
- **File type**: dropdown **Auto-detect (recommended)**, CSV, Excel (.xlsx, .xls), OpenDocument (.ods). Always verify the real type from the file content on the server; never trust the extension or the dropdown alone. If the chosen type does not match the file, say so clearly and let the user continue with the detected type.
- **File**: a drop zone and a Browse button. Limits: 5 MB and 5,000 data rows per sheet (make both constants easy to change). Allowed extensions: `.csv .tsv .txt .xlsx .xls .ods`.
- A short help line: "Export your Google Sheet as Excel (.xlsx) or CSV, then upload it here."
- **Next** parses the file on the server and goes to Step 2 (show a progress indicator).

**Step 2: Review (this is a dry run, nothing is saved yet)**
- If the workbook has several sheets, show a sheet picker. Two modes:
  - **One sheet into the chosen client** (default): pick the sheet.
  - **Each sheet is a different client** (offered when there are 2+ sheets): a list of sheets, each with a checkbox, a client dropdown (auto-matched to an existing client by name, ignoring case, spaces and punctuation, otherwise "Create new client named <sheet name>"). This matches my Google Sheet, where every tab is one client. Sheets that are not content calendars (for example a tab with only special days) can be unchecked.
- **Header row detection** (automatic, adjustable): my sheets often have a block above the table ("Client Name", "Total Design", "Stories Daily 2*31=62"), then month title rows ("MAY MONTH CONTENT CALENDAR"), then the header row, and the header row can repeat for each month. Detect the header row as the first row within the first 30 rows that has at least 3 non-empty cells and looks like a header (mostly short text, includes a date-like header such as "date" or "publishing", or has the most non-empty text cells). Later rows that repeat the same labels are skipped. Rows with a single non-empty cell (month titles, notes) are skipped and used as month / year context for dates without a year. Offer a "Header is on row N" number input to override and re-parse.
- **Column detection.** For every column of the header row, show: the header text, 3 sample values, the detected type, and a **"Maps to"** dropdown with: Date (publishing date), Content type, Title, Status, an existing column of this client (matched automatically by normalized name, so "captions" matches "Captions"), **New column** (with a type dropdown, pre-filled by detection), or **Skip**. Auto-map rules:
  - Date: header matches `date|publish`, otherwise the column with the most parseable dates.
  - Content type: header matches `content type|type|format`.
  - Title: header matches `topic|title|subject|post name`.
  - Status: header matches `status|post update|update`.
  - Everything else: an existing column with the same normalized name, else a new column named from the header.
  - Type detection per column, from the non-empty values: **date** (≥ 80 % parse as dates), **number**, **link** (≥ 80 % start with http), **checkbox** (only true/false/yes/no/1/0), **multi-select** (cells hold several short values split by comma, slash or newline, with low variety), **select** (at most 12 distinct values and at most 40 % of the rows, each under 40 characters), **long text** (average length over 80 or contains line breaks), otherwise **text**. File columns are never created by import.
  - Header cleanup: trim, collapse spaces, max 60 characters; duplicate headers get " 2", " 3"; a non-empty column with no header is named "Column C" (its letter); entirely empty columns are ignored.
- **Date parsing.** Accept ISO, `dd/mm/yyyy`, `dd-mm-yyyy`, `dd.mm.yyyy`, `d MMM yyyy`, `1st Nov`, `Nov 1`, Excel serial numbers and real Excel date cells. Day first, not month first. If a date has no year, use the month-title context if present, otherwise a **"Year for dates without year"** input (default: current year). A date cell that is **blank** while the row has content means "same date as the row above" (my sheets put several posts on one date and only write the date once). Make this a checkbox, on by default: "Blank date = same date as the row above".
- **Values.** For select-type columns, match values to existing options **case-insensitively** ("POSTED" matches "Posted"). Values with no match are shown as "N new options" with the list; they are created if the user may, otherwise those cells are left empty and counted in the report. Keep all text exactly as written (Kannada and other Unicode text must survive, including from CSV).
- **Row rules.** A row without a valid date (and no way to infer one) is skipped with a reason. A row with no title uses the first non-empty text value, shortened to 255 characters; if there is none the row is skipped with a reason. Completely empty rows are ignored silently.
- **Options** (checkboxes): "Skip rows that already exist (same date and same title)" on by default; "Blank date = same date as the row above" on.
- **Preview table**: the first 10 data rows after mapping, using the target column names, so I see what will be saved. Horizontal scroll inside the box.
- **Summary strip** (counts come from the same server function that will run the import, so they match exactly): rows to import, rows skipped (with a "see why" expander listing row numbers and reasons, first 50), new columns, new options, date range (first to last date), months that will be added to the month row.
- Buttons: **Back**, **Import N rows** (disabled when N is 0 or the user may not import).

**Step 3: Result**
- Show: imported, skipped, new columns created, new options created, months added. A "Skipped rows" list with row number and reason, and a **Download skipped rows (CSV)** link.
- **Undo this import** button (see C4) available straight away, and **View in calendar** that closes the popup, switches to that client, selects the first imported month and the first imported date.
- If "each sheet is a different client" was used, show one result block per client.

### C3. Server side

- **Parsing.** Use the spreadsheet library already in `vendor/` (the one used for the Excel exports). Do **not** add new composer packages. If the library cannot read `.xls` or `.ods` here, tell me in the report and support what it can. For CSV: detect encoding (UTF-8 with or without BOM, UTF-16 from Excel, Windows-1252) and the delimiter (comma, semicolon, tab) automatically. For Excel, read **calculated values**, never formulas, and ignore cell colors.
- **Upload handling.** Save the upload to `writable/files/content_calendar/imports/` under a random name, verify type and size, parse once, store the parsed rows in a temp JSON file keyed by a random token tied to the user and the client, expire it after 30 minutes, delete the temp files after import or expiry. Never send the whole file back to the browser; send only the detection result and the 10-row preview. Never execute or include the uploaded file.
- **Endpoints** (all permission-checked, CSRF-protected, return JSON): `import/parse` (upload and detect), `import/preview` (re-run with changed mapping or options, no writes), `import/run` (apply), `import/undo`, `import/skipped_csv`. `preview` and `run` must use the same function so counts match.
- **Apply** in one database transaction per client: create the new client if asked, create new columns in sheet order (use the same slug / unique-key logic as the column designer), add new options (case-insensitive, trimmed, max 60 characters), insert items in batches of 200, add every month that has an item to `rise_cc_months`, write one `rise_cc_import_logs` row. If anything fails, roll back that client completely and show a clear message. Validate everything again on the server (types, option membership, dates, URL scheme, lengths); do not trust the preview payload.
- Imported items are normal items: they show in the month view, day panel, table and filters, and respect all existing rules.
- Performance: parse and import 5,000 rows in a few seconds; no per-row queries inside the loop for column or option lookups.

### C4. Undo and audit (small database change)
- New table `rise_cc_import_logs` (id, calendar_id, user_id, file_name, sheet_name, rows_total, rows_imported, rows_skipped, columns_created, options_created, batch_id, undone (0/1), created_at).
- New nullable column `import_batch_id` on `rise_cc_items`, set on every imported item.
- **Undo this import** soft-deletes the items of that batch only. It does not remove the columns or options that the import created (tell the user that on the button's confirm text). Only the importing user or an admin can undo, and only once.

## D. SQL

Update `docs/content_calendar.sql` and `docs/content_calendar_sql.md`. My local database already has the phase 1 tables, so give me both:
1. the updated `CREATE TABLE IF NOT EXISTS` for a fresh install (with `import_batch_id` in `rise_cc_items` and the new `rise_cc_import_logs` table), and
2. an **idempotent** upgrade block for databases that already have phase 1: `CREATE TABLE IF NOT EXISTS rise_cc_import_logs …` and the `import_batch_id` column added with the INFORMATION_SCHEMA + PREPARE/EXECUTE pattern (no `ADD COLUMN IF NOT EXISTS`, which MySQL 8 does not have), with the index on `import_batch_id`.
Keep the verification queries and the rollback section up to date, and say which statements are safe to run twice. I run it on live myself.

## E. Testing

Create fixtures under `tests/_tmp_*` (never uploaded):
1. A CSV in UTF-8 with Kannada text in one column and a BOM.
2. A semicolon-delimited CSV saved from Excel (Windows-1252, with `dd/mm/yyyy` dates).
3. An `.xlsx` with: a header block above the table, month title rows, the header repeated for each month, several posts per date with the date written only on the first row, a status column with POSTED / scheduled / upload done / NOT GET in mixed case, a link column, a long caption column, empty trailing columns, and a column with no header.
4. A multi-sheet `.xlsx` where the sheets are named like clients (include one sheet that should be unchecked) to test "each sheet is a different client".
5. An `.ods` if the library supports it.
6. Bad inputs: wrong extension, empty file, file over 5 MB, a file with 5,001 rows, rows with no date.

Then verify and show me the results (screenshots or counts):
- Every fixture: detected header row, detected column types, mapping, summary counts, and the final items in the right months and dates (list a few).
- New options appear in the Type / Goal / Status filters straight away. New columns appear in the Add content form, day panel and table.
- Months are added to the month row for imported dates.
- Duplicate skip works (run the same file twice: second run imports 0 and skips all).
- **Undo** removes only that batch.
- Permissions with separate logins: view only (no Import button, endpoint refuses with 403), add only (can import into existing columns, new columns and new options are locked), `manage_options` (can create options), admin (everything). Direct calls to the endpoints for forbidden actions are refused.
- The Add client popup: open, validation errors, create blank, create by copying, Esc, backdrop click, focus trap, focus return.
- Layout at 1360 px and 390 px: **actually capture screenshots** of the calendar (month and table), Add content, the Add client popup, each Import step, and the Columns designer with its popup. If you cannot capture screenshots, say so plainly instead of calling it verified.
- `php -l` on every changed file; Tasks, Events, Timesheets, Messages and the settings pages still load.

## F. Deliverables

1. Code, uncommitted.
2. Updated `docs/content_calendar.sql` and `docs/content_calendar_sql.md` (section D).
3. The **exact list of files to upload**: new, changed (with what changed), and the files that must **not** be uploaded (`tests/*`, `_tmp_*`, `database/*.sql`, `writable/*`, `Database.php`, `system/*`). Include the folder to create on the server: `writable/files/content_calendar/imports/`.
4. A short report: what you built, what you could not do and why, what you were unsure about, and every existing file you changed. List anything the library could not read.

## G. Not in this phase

Importing cell colors, importing images, scheduled or repeated imports, exporting the calendar, renaming options, a separate permission card for import. Do not build these; tell me if something suggests one is needed.
