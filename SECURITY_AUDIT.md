# BookNest — Security Audit & Test Report

**Course:** SPG 0553 Advanced Web Programming — Mini Project
**System:** BookNest Library Management System
**Stack:** Laravel 13 · PHP 8.5 · Livewire 4 · Flux UI 2 (free) · Alpine.js · Laravel Fortify · SQLite · Pest 5
**Audit date:** 22 November 2026

---

## 1. Summary

| Item | Result |
|---|---|
| Automated tests | **240 passed, 0 failed, 0 skipped — 931 assertions** (`php artisan test`) |
| Static analysis | PHPStan / Larastan: **0 errors** |
| Code style | Laravel Pint: **pass** |
| PHP syntax (`php -l`, all app/config/database/routes/tests files) | **0 errors** |
| Blade templates (`php artisan view:cache`) | **compile without errors** |
| Browser console (manual use of dashboard, borrowings, login) | **no JavaScript errors** recorded |
| Defects found in this audit | **1 security defect — fixed and regression-tested** (see §2) |

---

## 2. Findings from this audit

### F-1 (fixed) — Livewire actions did not re-check email verification

**Problem.** Every protected page uses the `auth` and `verified` middleware. A page load checks both, but each later Livewire request (a button click or form submit) only re-runs Livewire's *persistent* middleware. That list includes `auth` but not `verified`. A user who became unverified while a page was open could therefore keep performing actions on it. This happens, for example, after changing their email address in Profile settings, which resets verification.

**Fix.** `app/Providers/AppServiceProvider.php` registers `EnsureEmailIsVerified` as persistent Livewire middleware.

**Evidence.** `SecurityAuditTest › Livewire re-checks email verification on every component request` sends a real HTTP request to the Livewire update endpoint:

- With the fix: **403**.
- With the fix temporarily disabled: **200** (the test fails), which confirms the test detects the defect.

### Checked and confirmed safe (no change needed)

| Concern | Finding |
|---|---|
| Swapping the book being edited from the browser (`form.book`) | Livewire ignores client changes to model properties. The save still updates only the book that was authorised (tested). |
| Calling `#[Computed]` methods from the browser (e.g. the student list) | Livewire refuses (`CannotCallComputedDirectlyException`), tested. |
| Changing record ids held in component state | Ids are `#[Locked]`; changes are refused, tested. |
| Unescaped output | The only `{!! !!}` is the starter kit's server-generated 2FA QR-code SVG. All user data is printed with `{{ }}`. Flux renders alert headings with `{{ }}` (checked in vendor source). |

---

## 3. Implemented security controls

### 3.1 Authentication (Laravel Fortify)
- Registration, login, password reset, **email verification**, optional **two-factor authentication** and password confirmation for security settings.
- **Login throttling:** 5 attempts per minute per email + IP (`FortifyServiceProvider`). The 6th attempt returns **429**, even with the correct password.
- Every protected page (`/dashboard`, `/manage/books`, `/borrowings`, `/reservations`) uses `auth` + `verified`. Livewire re-applies both on every component request (F-1).
- Book-changing routes from Assignment 1 (`create`, `store`, `edit`, `update`, `destroy`) require `auth` + `verified` through controller middleware. `index` and `show` stay public, as in Assignment 1.

### 3.2 Authorization (Policies, enforced server-side)
- **Policies:** `BookPolicy`, `AuthorPolicy`, `PublisherPolicy`, `BorrowingPolicy`, `ReservationPolicy` (auto-discovered).
- **Checked twice:** every mutation is authorized in the Livewire component (`Gate::authorize` / `Gate::inspect`) **and again inside the Action class** (`Gate::forUser($user)->authorize(...)`). An Action called from anywhere — controller, component, test or console — cannot skip the policy.
- **Buttons are not security:** hiding buttons with `@can` is only for usability. Tests call the server methods directly as a student and confirm they are refused.
- **Roles:**
  - `role` is an enum (`admin`, `librarian`, `student`).
  - It is **not mass assignable**, and the database default is `student`.
  - Registration always creates a student, even when `role=admin` is posted (tested end-to-end).

### 3.3 Privacy of student records
- Students' borrowing, reservation and dashboard lists are filtered by `user_id` **in the database query**. Other students' rows are never loaded, so they cannot leak through the page, search, filters, statistics or pagination.
- Search by student name or email is only available to staff.
- Policies refuse a student viewing, cancelling, reviewing or returning another student's record. The request is refused, and the record is not changed.

### 3.4 CSRF
- All Assignment 1 forms include `@csrf`. Livewire sends the CSRF token with every request.
- Laravel 13's `PreventRequestForgery` middleware accepts a request only with a valid token or a verified same-origin request.
- Laravel turns CSRF checks off during tests, so `SecurityAuditTest` installs the real middleware with that shortcut disabled. It then proves:
  - Forged POST, PUT and DELETE requests to `/books` return **419** and change nothing.
  - A valid token is accepted.
  - A Livewire request with a forged token returns **419**.

### 3.5 XSS
- Blade `{{ }}` escaping is used for all user data (titles, authors, names, remarks, flash messages).
- A `<script>` payload stored as a book title, author, user name and reservation remark is shown escaped on the Dashboard, Book Management, Borrowings, Reservations and the public `/books` page (tested).

### 3.6 Input validation (Form Objects)
| Form Object | Rules |
|---|---|
| `BookForm` | Title and author required (≤255); category must exist; linked author and publisher optional but must exist; year 1900 to next year; price numeric 0–999,999.99 |
| `BorrowingForm` | Borrower must be an existing **student**; book must exist; due date `Y-m-d`, after today, at most 60 days away |
| `ReservationForm` | Book required and must exist; review decision must be `approve` or `reject`; remarks ≤500 characters and **required when rejecting** |
| `BookController` (Assignment 1) | Its original validation rules are kept |

Actions re-check the business rules even after validation passes: borrower role, availability, due date in the future, and reservation state.

### 3.7 Mass-assignment protection
- Every model declares `#[Fillable]`. `User::role` and `email_verified_at` are not fillable.
- Reservation review fields (`status`, `reviewed_by`, `reviewed_at`, `expires_at`, `remarks`) are not fillable; only the Actions set them (tested).
- `BookController` saves only `$request->validate(...)` output, never raw request data.

### 3.8 Secrets
- `.env` is gitignored, and `.env.example` ships with an empty `APP_KEY` (tested).
- Passwords are hashed (`hashed` cast).
- `password`, `remember_token` and the 2FA secret and recovery codes are `#[Hidden]` and never serialised (tested).
- No secrets appear in code, views, tests or this report.

### 3.9 Data integrity, transactions and history
- **Transactions:** issue, return, create, approve, reject and cancel all run inside `DB::transaction` with row locks (`lockForUpdate`). Tests force a failure **after** the database write and confirm a full rollback for issuing, returning, creating a reservation, approving, and issuing a held book.
- **Database-level rules** (SQLite and PostgreSQL partial unique indexes):
  - one open loan per book;
  - one active reservation per student per book;
  - one approved hold per book.
- **Safe against duplicate submissions:** reservation status changes are conditional updates (`WHERE status = 'pending'`), so a double-click or two librarians acting at once cannot change a reservation twice.
- **History is preserved:**
  - Returns stamp `returned_at`; loans are never deleted.
  - A deleted user account sets `user_id` to NULL on its loans and reservations instead of deleting them.
  - A book with any borrowing or reservation history cannot be deleted (policy, plus a database `RESTRICT` on borrowings).

---

## 4. Role permission matrix

Enforced by Policies and verified as a whole by `SecurityAuditTest › the role permission matrix is enforced by the policies`.

| Capability | Admin | Librarian | Student | Guest |
|---|:---:|:---:|:---:|:---:|
| Browse public catalogue (`/books`, `/books/{id}`) | ✅ | ✅ | ✅ | ✅ |
| Open Book Management (`/manage/books`) | ✅ | ✅ | 👁 read-only | ❌ login |
| Create / edit books | ✅ | ✅ | ❌ | ❌ |
| Delete books (only with no loan or reservation history) | ✅ | ❌ | ❌ | ❌ |
| Manage authors / publishers (policy) | ✅ | ✅ | ❌ | ❌ |
| View all borrowings | ✅ | ✅ | ❌ (own only) | ❌ |
| Issue a book / record a return | ✅ | ✅ | ❌ | ❌ |
| Request a reservation | ❌ | ❌ | ✅ | ❌ |
| View all reservations | ✅ | ✅ | ❌ (own only) | ❌ |
| Approve / reject pending reservations | ✅ | ✅ | ❌ | ❌ |
| Cancel own pending request or hold | — | — | ✅ | ❌ |
| Release another student's approved hold (no-show) | ✅ | ✅ | ❌ | ❌ |
| Dashboard | library-wide + account totals | library-wide | own data + public catalogue figures | ❌ login |

---

## 5. Business rules verified

| Rule | Where tested |
|---|---|
| Only staff create or edit books; only admins delete; never a book with history | `BookManagerTest`, `PolicyTest`, `BookControllerTest` |
| The seven Assignment 1 routes still map to `BookController` and the public pages work | `BookManagerTest`, `LibraryWorkflowTest` |
| A borrowed book cannot be issued again (Action **and** database index) | `BorrowingManagerTest` |
| A loan cannot be returned twice, including from two tabs | `BorrowingManagerTest` |
| An approved reservation holds the book for **its student** until the end of the 3rd day | `LibraryWorkflowTest`, `ReservationManagerTest` |
| Other students cannot borrow a held book; the holder can, and the reservation becomes **Fulfilled** | `LibraryWorkflowTest`, `ReservationManagerTest` |
| An expired hold releases the book, even before `reservations:expire` runs | `LibraryWorkflowTest`, `ReservationManagerTest` |
| Approval rechecks availability (refused if the book is on loan or on hold for someone else) | `ReservationManagerTest` |
| A closed reservation (fulfilled, rejected, cancelled, expired or already approved) can never be approved | `SecurityAuditTest`, `ReservationManagerTest` |
| Duplicate active reservations are refused (Action **and** database index) | `ReservationManagerTest` |
| Loan status and `returned_at` stay consistent across the lifecycle | `SecurityAuditTest` |
| Transactions roll back on failure | `BorrowingManagerTest`, `ReservationManagerTest` |
| Dashboard figures match the module rules; students see only their own data | `DashboardTest`, `LibraryWorkflowTest` |

---

## 6. Test coverage (240 tests)

| Test file | Tests | Covers |
|---|---:|---|
| `Auth/*` (Fortify starter tests) | 19 | Login, logout, email verification, password reset and confirmation, 2FA challenge, registration |
| `Settings/*` | 15 | Profile update, account deletion, security settings |
| `BookControllerTest` | 15 | Assignment 1 CRUD, validation, seeders, category↔book relationship |
| `BookManagerTest` | 35 | Livewire Book CRUD, roles, validation, search, category filter, pagination, relationships, protected delete, Assignment 1 routes |
| `BorrowingManagerTest` | 30 | Issue and return, unavailability, duplicate returns, validation, student restrictions, filters, pagination, rollback |
| `ReservationManagerTest` | 37 | Create, approve, reject, cancel, duplicates, invalid book, availability recheck, ownership, expiry, fulfilment, rollback, history |
| `LibraryWorkflowTest` | 11 | End-to-end hold workflow through the real pages, hold window and expiry command, page protection, XSS, role escalation |
| `DashboardTest` | 13 | Access per role, branding, exact statistics, student privacy, admin account totals |
| `SecurityAuditTest` | 23 | Verification and auth on Livewire requests, login throttling, **CSRF (real middleware)**, state tampering, mass assignment, secrets, full role matrix, closed-reservation rules, history consistency |
| `PolicyTest` | 20 | HTTP authorization for book routes and policy rules |
| `DomainSchemaTest` | 15 | Migrations, relationships, delete behaviour, seeding |
| `UserRoleTest` | 5 | Registration as student, role not mass assignable |
| `ExampleTest` (Feature + Unit) | 2 | Starter smoke tests |

Run them with:

```bash
php artisan test --compact          # full suite
vendor/bin/phpstan analyse          # static analysis
vendor/bin/pint --test              # code style
php artisan route:list --path=books # Assignment 1 routes
```

---

## 7. Known limitations

| # | Limitation | Impact / recommendation |
|---|---|---|
| L-1 | The database rules use **partial indexes**, which SQLite and PostgreSQL support but **MySQL/MariaDB do not**. | On MySQL, only the Actions' row locks prevent duplicate loans and holds. Add a generated-column unique index if you move to MySQL. |
| L-2 | Lapsed holds are marked `expired` by `php artisan reservations:expire`, scheduled hourly. It only runs automatically when the scheduler runs (`php artisan schedule:work` or cron). | Availability and the page display are always correct because they check the date. Only the stored status lags. |
| L-3 | The audit trail records the student, dates, reviewer and remarks, but **not** which staff member issued or returned a loan, or who cancelled a reservation. | Add `issued_by`, `returned_by` and `cancelled_by` columns if full accountability is required. |
| L-4 | The Assignment 1 `BookController` is kept as originally written. It uses the policies but not the Book Action classes. Its year range (1900–2100) and missing price maximum differ from `BookForm`. | Kept on purpose to preserve Assignment 1. SQLite does not enforce `decimal(8,2)`. |
| L-5 | Deleting a **category** cascades to its books (Assignment 1 design), and with them their reservations. Books with loans block it (`RESTRICT`). | There is no category-deletion screen. Avoid deleting categories directly in the database. |
| L-6 | There is no screen for changing roles; roles are assigned by seeder or tinker. The last admin can delete their own account. | Keep at least two admin accounts in production. |
| L-7 | A student can delete their account while a book is on loan. The loan is kept (borrower shown as "Deleted account") and can still be returned. | Optionally block account deletion while loans are open. |
| L-8 | Demo accounts (`admin@`, `librarian@`, `test@example.com`) use the password `password`. | **Local demonstration only.** Remove them or change their passwords before any deployment. |
| L-9 | The local `.env` uses `APP_ENV=local`, `APP_DEBUG=true` and `APP_NAME=Laravel`, so emails say "Laravel". | For production: `APP_ENV=production`, `APP_DEBUG=false`, HTTPS, `SESSION_SECURE_COOKIE=true`, `APP_NAME=BookNest`. In production, `Password::defaults()` also enforces strong passwords. |
| L-10 | There are no automated real-browser tests (the Pest browser plugin is not installed). Flux and Alpine behaviour was checked through rendered output and manual use. | Optional: add `pestphp/pest-plugin-browser`. |
| L-11 | The project is not a git repository. | Run `git init` and commit before submission so there is a restore point. |
