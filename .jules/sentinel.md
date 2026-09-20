## 2026-04-12 - Unrestricted File Upload Vulnerability in Generic Upload Controller
**Vulnerability:** The default fallback rule in `UploadController::store()` allowed any file type (`required|file|max:102400`) without MIME extension restrictions when `type` was unspecified or fallback was triggered.
**Learning:** Generic file upload endpoints that rely on dynamic `type` parameters can expose server-side execution risks if fallback rules do not enforce explicit file extension and MIME whitelisting.
**Prevention:** Always enforce strict `mimes` validation rules on all file upload endpoints, including default/fallback cases, to reject executable scripts (`.php`, `.html`, `.sh`, `.exe`).
