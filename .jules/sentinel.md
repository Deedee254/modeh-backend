## 2026-03-30 - Refactor raw SQL sorting in LeaderboardController
**Vulnerability:** Raw string interpolation in `orderByRaw("points {$sortDir}")` in `LeaderboardController.php` exposed potential SQL injection if input validation/sanitization failed or was bypassed.
**Learning:** `orderByRaw` in Eloquent executes raw SQL strings without parameter binding for directions, making unescaped direction input risky.
**Prevention:** Always use `orderBy($column, $direction)` instead of `orderByRaw` when sorting by dynamic directions.
