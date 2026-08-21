# Sentinel's Journal - Critical Security Learnings

## 2025-02-23 - Chat Attachment File Validation Gap
**Vulnerability:** Unvalidated chat message file uploads allowed arbitrary files (e.g., executable PHP scripts or HTML/JS payloads) of unrestricted sizes to be stored on public storage via `/api/chat/send`.
**Learning:** File upload parameters passed as arrays (`attachments.*`) in nested request structures need explicit Laravel array validation rules (`attachments` as array and `attachments.*` as file with mime and max size restrictions).
**Prevention:** Always validate array inputs and file attachment constraints explicitly in API endpoints accepting file uploads.
