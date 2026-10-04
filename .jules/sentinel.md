# Sentinel Security Journal

## 2026-03-29 - Unrestricted File Upload in Generic Upload Endpoint
**Vulnerability:** The generic file upload endpoint `/api/uploads` did not enforce extension/MIME restrictions when `type` was omitted or defaulted to fallback cases.
**Learning:** Default fallback cases in switch statements for file upload validation must always enforce a strict whitelist of safe file types; otherwise, users can upload arbitrary executable scripts (`.php`, `.sh`, `.exe`) leading to Remote Code Execution (RCE) or `.html` files leading to Stored XSS.
**Prevention:** Always validate uploaded files against explicit `mimes` or `mimetypes` whitelists, even in fallback or catch-all upload handlers.
