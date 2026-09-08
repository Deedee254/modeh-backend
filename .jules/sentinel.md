# Sentinel Security Journal

## 2026-03-20 - Unrestricted File Uploads via Generic Endpoint
**Vulnerability:** The generic `/api/uploads` endpoint had no MIME or extension validation on default file upload types, allowing potential remote code execution (RCE) or stored XSS via executable script uploads (`.php`, `.phtml`, `.html`, etc.).
**Learning:** Generic upload handlers must always validate allowed MIME types and file extensions even when custom upload types or fallback defaults are used.
**Prevention:** Always enforce strict whitelist validation (`mimes:...`) on file input fields regardless of frontend-declared file types.
