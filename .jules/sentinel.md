## 2026-05-18 - Generic Upload Controller MIME Restriction Gap
**Vulnerability:** Unrestricted file upload fallback in `UploadController.php` where missing `type` parameter defaulted to validating only file presence without extension/MIME restrictions, allowing potentially dangerous files (.php, .html) to be saved in public storage.
**Learning:** Generic upload handlers with switch/case branches based on user-provided type parameters must enforce a strict whitelist of safe file extensions/MIMEs in the default case to prevent arbitrary file upload vulnerabilities.
**Prevention:** Always validate file uploads against an explicit whitelist of safe MIME types or extensions (`mimes:...`), even in generic fallback routes.
