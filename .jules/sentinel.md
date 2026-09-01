# Sentinel Journal

## 2026-05-18 - Missing Group Membership Authorization Checks in Chat API
**Vulnerability:** Group chat endpoints (`/api/chat/messages?group_id=...`, `/api/chat/send`, `/api/chat/groups/mark-read`) allowed any authenticated user to view, send, or mark messages as read for any group without verifying group membership.
**Learning:** Endpoints taking optional group parameters (`group_id`) in general controller actions can easily bypass membership verification if authorization checks are only performed on group-level listing endpoints instead of on every action operating on group resources.
**Prevention:** Always perform explicit `whereHas('members', ...)` or policy authorization checks on resource actions that receive a `group_id` parameter.
