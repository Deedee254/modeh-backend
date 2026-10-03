## 2026-03-30 - Public Endpoint Exposing Private Wallet Balances
**Vulnerability:** The public quiz master profile endpoint (`GET /api/quiz-masters/{id}`) returned private wallet balance fields (`available` and `pending`) to unauthenticated visitors and third parties.
**Learning:** Public endpoints aggregating user profiles often include related models (e.g., Wallet) for owner dashboards, accidentally leaking sensitive financial data to public consumers when authorization conditions are omitted.
**Prevention:** Always filter sensitive fields on public profile payloads using `$currentUser->id === $user->id` or explicit API resources/transformers.
