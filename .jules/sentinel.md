## 2025-05-18 - Strict MIME Type Restriction on Generic File Uploads
**Vulnerability:** Unrestricted generic upload endpoint (`/api/uploads`) accepted any file type without extension or MIME constraints when no type or an unrecognized type was specified, opening up arbitrary file upload risks (e.g. PHP scripts or HTML/SVG XSS vectors).
**Learning:** Generic upload fallback cases in controllers must strictly whitelist acceptable file MIME types and extensions rather than relying solely on file presence/size checks.
**Prevention:** Always enforce explicit `mimes:...` or `mimetypes:...` validation rules for user file upload endpoints.
