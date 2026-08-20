## 2026-04-12 - Chat Controller Attachment Validation and Metric Counters

**Vulnerability:**
The `/chat/send` endpoint accepted file uploads without validating mime types, extensions, or file sizes, allowing arbitrary file uploads. Furthermore, metric counter updates constructed SQL strings in `updateOrCreate` raw expressions.

**Learning:**
`updateOrCreate` with raw expressions referencing table columns fails during initial insert because values clause expressions cannot reference existing table column names before the row is created. Refactoring to `firstOrCreate` with default values followed by `increment()` is safer and portable across database drivers.

**Prevention:**
Always validate uploaded files on array parameters in request inputs (`attachments.* => file|mimes:...|max:...`), sanitize original filenames with `basename()`, and use Eloquent's `firstOrCreate` + `increment()` for counter metrics instead of raw SQL concatenation.
