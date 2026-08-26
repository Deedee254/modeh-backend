## 2026-03-31 - Laravel DB::raw Parameter Bindings vs Explicit Casting
**Vulnerability:** String concatenation inside `DB::raw()` expressions can expose the application to SQL injection risks if inputs are not strictly typed.
**Learning:** `DB::raw()` accepts only a string argument and does not accept a second parameter binding array (passing one is silently ignored by PHP, which results in unbound `?` placeholders causing SQL errors).
**Prevention:** Always strictly typecast numeric inputs (e.g. `(int) $count`) before concatenating into `DB::raw()` expressions, or use builder methods like `DB::raw('COALESCE(value,0) + ?')` with query builder methods that support parameter bindings.
