## 2025-05-18 - Unrestricted File Upload in Fallback Validation Branch
**Vulnerability:** The generic `/uploads` endpoint in `UploadController` fell back to `'required|file|max:10240'` when `type` parameter was omitted or unrecognized, permitting arbitrary file type uploads (e.g., PHP scripts or web executables) to public storage.
**Learning:** Default/fallback validation branches can inadvertently bypass MIME/extension checks if rules are defined inside type-specific switch statements without a restrictive default rule.
**Prevention:** Always enforce explicit allowed MIME type and extension whitelists (`mimes:...`) across all file upload validation branches, including fallback or default handling.
