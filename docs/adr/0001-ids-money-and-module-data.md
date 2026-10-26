# ADR 0001: IDs, money and cross-module data

Status: accepted (change before Task 3 if you disagree)

- **IDs:** every table uses UUIDv7 primary keys (time-ordered, so index-friendly; not guessable, which helps
  against IDOR). Exceptions: per-conversation `messages.seq` and stock counters are bigint.
- **Money:** integer minor units plus an ISO 4217 currency code. No floats or decimals in application code.
- **Cross-module data:** modules store each other's ids as plain indexed uuid columns without foreign keys.
  Foreign keys exist only inside one module. Consistency across modules is checked by
  `orders:verify-invariants` and by tests, not by the database.
- **Users:** the Identity module owns `users` and its model. The default `app/Models/User.php` and root
  users migration move into Identity in Task 3.
