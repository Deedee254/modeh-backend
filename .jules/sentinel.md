## 2025-05-18 - Generic Upload Endpoint MIME Type Whitelisting
**Vulnerability:** In unrestricted generic file upload endpoints, lack of default file extension/MIME type validation permits arbitrary file uploads (e.g., PHP scripts or executables).
**Learning:** Default validation branches in file upload handlers must explicitly enforce strict MIME type whitelists rather than accepting any file.
**Prevention:** Always restrict accepted MIME types/extensions on file upload endpoints (`mimes:jpeg,png,pdf,...`) and avoid generic `required|file` validation rules without MIME bounds.
