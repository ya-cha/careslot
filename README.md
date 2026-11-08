# careslot · Emergency daycare allocation

Laravel 13, PHP 8.5, Livewire 4, Blade, and Tailwind CSS 4. A German-language administration interface with personal parent links, two-phase allocation, and audited confirmation. No public registration, parent accounts, or SPA.

Interface labels below remain in German to match the application.

## Installation

Requirements: PHP 8.5 with PDO-PgSQL or PDO-MySQL, Composer, a Node.js version compatible with the installed Vite version, and PostgreSQL or MySQL. The existing SQLite configuration is supported for local development and isolated tests; concurrent confirmations in production require PostgreSQL/MySQL with row-level locking.

```sh
composer install
cp .env.example .env
php artisan key:generate --no-interaction
npm ci
npm run build
```

Configure database credentials, `APP_URL`, `APP_LOCALE=de`, SMTP, and the sender in `.env`. PostgreSQL is the default in `.env.example`. For MySQL, set `DB_CONNECTION=mysql` and the appropriate port. Create the database first. The application timezone is `Europe/Berlin`; calendar weeks follow ISO 8601.

```sh
php artisan migrate --no-interaction
php artisan careslot:admin beirat@example.org --name="Elternbeirat"
```

The administrator password is requested through a hidden prompt and must contain at least twelve characters. There are no built-in default credentials. `db:seed` does not create an account.

Locally, `composer run dev` starts the web server, Vite, queue worker, and scheduler together. When running separate processes, start both the queue worker and `php artisan schedule:work` in addition to `php artisan serve` and `npm run dev`. Sign in at `/login`. The web server must use `public/` as its document root.

## Setup and workflow

1. Create a year under **Kita-Jahre** (Daycare years).
2. Create groups and assign administrator accounts under **Gruppen & Admins** (Groups & admins). Under **Kinder & Familien** (Children & families), assign each child to a group and add contacts. Explicitly configure entitlement patterns and validity periods for Monday through Friday. For siblings or another daycare year, select an existing family in the same group. Multiple contacts are supported; each receives a separate link to the child's response form.
3. Only after adding the initial children, create the **alphabetical initial list for each group**. Children are sorted by last name, then first name. New active children are appended afterward. Children who have left remain in the historical records and are skipped during allocation. Displayed positions refer to the full list, so gaps may appear when children who have left are hidden.
4. Create an emergency daycare day with a capacity, optional care hours, and an optional response deadline. The allocation round stores a snapshot of the entitlement rules valid at that time for each active child.
5. Maintain exactly one valid entitlement rule for each weekday. Individual care hours are not recorded; the entitlement applies to the care day.
6. Select **Anfragen versenden** (Send requests). Parents indicate whether they need their regular place or a waiting-list place and can change their response until the deadline. Multiple contacts for the same child edit the same response; the last saved response applies. Authorized group administrators and system administrators can select **Rückmeldung erfassen** (Record response) or **Rückmeldung ändern** (Edit response) for a child in the allocation view and save on the parents' behalf. No personal parent link is required. The same business rules apply as in the parent form. Administrators can make corrections even after the deadline or after the response phase has closed, provided the allocation has not yet been confirmed as binding. The history records the administrator account and the previous and new values. Parents can still edit the response themselves while the response phase is open. This input remains blocked for children who have left or moved to another group, and for allocations that have been confirmed as binding. An administrator's correction does not reopen the response phase for parents.
7. While the response phase is open, the deadline can be adjusted under **Rückmeldefrist ändern** (Change response deadline) in the allocation view, or removed by clearing the field. New deadlines must be in the future. Existing responses are retained, and changes are logged. Pending request and reminder emails receive the new deadline; messages already sent are not automatically resent. Closed allocation rounds are not reopened by this change. Review the responses and close the phase manually, including early closure when all responses are in. The scheduler automatically closes the phase when the deadline expires. Further edits by parents are blocked as soon as the deadline is reached.
8. If an administrator changes a response or records a first response after calculation, the current proposal is preserved in the audit log and discarded. The proposal must then be recalculated and reviewed again. Saving an unchanged response does not discard a proposal. Previously opened confirmation pages cannot confirm a proposal that has since changed. Select **Vorschlag berechnen** (Calculate proposal) and review both phases and the list movements. Without a response, the regular entitlement remains in place. Demand for a waiting-list place must be explicitly reported.
9. Confirm the complete proposal as binding using the confirmation checkbox (**verbindlich bestätigen**). Only this step saves the results, waiting-list movements, and email delivery requests. Repeated confirmations have no additional effect.
10. If staffing changes, open a **new allocation round** from the completed allocation, providing a reason and a new capacity. It references the latest completed round for the same care day. Previous results and list movements are retained.

## Business rules and technical decisions

- A child receives at most one place per allocation round. Entitlement depends on the weekday, ISO calendar week, and validity period. Optional care hours apply to the entire emergency daycare day rather than to individual children.
- If required regular entitlements exceed capacity, calculation is blocked with a message. The application does not invent a reduction or priority rule for this case, for which no business rule has been defined. The situation must be resolved before allocation.
- Phase 2 follows the existing waiting-list order. Only children actually awarded a waiting-list place they have requested are moved to the end. Releasing a regular place or merely requesting a place does not change the order. Any places remaining after regular entitlements and waiting-list allocation stay unfilled.
- Each allocation round has its own responses and entitlement snapshots. Changes to master data apply to newly opened rounds. Proposals record the revision of the group's waiting list for the daycare year. Changes to children or the waiting list after calculation require the proposal to be discarded and recalculated.
- All concurrent changes to children, responses, and waiting lists, as well as confirmations, lock the daycare year first and the allocation round second. Positions and results have additional unique constraints. Confirmation is a database transaction; PostgreSQL/MySQL provide the row-level locks.
- Final results are stored in `allocation_results`. The non-binding proposal and its inputs are stored in `emergency_allocations.proposal`. Calculation saves only proposal and audit data; it changes neither final results nor waiting-list positions.
- `waiting_list_movements` records all absolute positions changed by the allocation, including those of other children moving up. Only children awarded places in phase 2 are actually moved to the end.

## Email, queue, and scheduler

Configure SMTP using `MAIL_MAILER=smtp`, `MAIL_SCHEME`, `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_FROM_ADDRESS`, and `MAIL_FROM_NAME`. `MAIL_MAILER=log` writes messages locally to `storage/logs/laravel.log` (with the default configuration of `LOG_CHANNEL=stack` and `LOG_STACK=single`) and does not send real emails. The scheduler and queue must run even with the log mailer: the scheduler picks up delivery requests from the outbox, and the queue then processes them. After processing, a request is marked as sent; with the log mailer, this only means "logged." SMTP has not been tested against an external service because credentials were unavailable.

```sh
php artisan queue:work --tries=4 --timeout=45
php artisan schedule:work
```

In production, use a continuously supervised queue worker and invoke the scheduler every minute with `php artisan schedule:run`. Run `php artisan queue:restart` during deployments. The queue reservation duration must exceed the worker timeout (default reservation duration: 90 seconds).

Delivery requests are stored in `email_deliveries` as a transactional outbox. The scheduler queues pending requests every minute. A missing worker or an SMTP error does not undo a confirmed allocation. Laravel Notifications are sent from within the queue job. Delivery status, attempt counts, and sanitized errors are visible in the allocation view. Unique jobs, cache locks, unique result delivery requests, and `sent_at` prevent duplicate delivery during normal operation. If a process terminates immediately after SMTP acceptance but before `sent_at` is saved, a duplicate email is technically possible; SMTP does not provide a transactional exactly-once guarantee.

```sh
# Queue pending requests immediately
php artisan careslot:send-pending
# After fixing errors, also requeue requests with four failed attempts
php artisan careslot:send-pending --retry
php artisan queue:failed
php artisan queue:retry all
```

Individual messages can be explicitly queued again in the delivery overview. Revoked or expired links also block pending delivery requests. Changes to a contact's email address or family assignment revoke the affected existing links. Sending requests again creates new personal links; old links remain valid until they expire or are explicitly revoked. Reminders are queued only for missing responses. Delivery requests for binding results are created exactly once per child/contact/allocation round.

## Parent links and data protection

Tokens consist of 32 cryptographically random bytes, are sent as 64 hexadecimal characters, and are stored in `personal_access_tokens` only as SHA-256 hashes. Each token is bound to a response and a parent contact and can be revoked individually. Tokens expire 30 days after the care day. A parent form shows only that child's data, without other families or waiting-list positions. Closed allocation rounds allow read-only access.

The outbox must be able to reconstruct the link for later delivery, so the recipient and token are stored together in a delivery payload encrypted with `APP_KEY`. Hashes and encrypted payloads are hidden during model serialization. Back up `APP_KEY` and keep it confidential. The token itself is not written to audit entries. Parent pages send `Cache-Control: no-store`, `Referrer-Policy: no-referrer`, and `X-Robots-Tag: noindex, nofollow`.

In production, configure HTTPS, `APP_DEBUG=false`, secure session cookies (`SESSION_SECURE_COOKIE=true`), and encrypted sessions (`SESSION_ENCRYPT=true`). Web server and proxy access logs must not record personal `/r/…` URLs without protection. The database, backups, and mail/queue logs contain personal data and require restricted access. Do not copy real parent data into demo or test databases. Agree on retention and deletion policies with the daycare operator; historical allocations are not deleted automatically.

## Verification

```sh
php artisan test --compact tests/Feature/EmergencyAllocationTest.php
php artisan test --compact tests/Feature/CareAccessTest.php
php artisan test --compact
vendor/bin/pint --format agent
vendor/bin/phpstan analyse --memory-limit=1G
npm run build
```

Business rule tests cover all five weekdays, ISO week parity including year boundaries, day-based entitlements, missing responses, all allocation phases, waiting-list stability, snapshots, newly added children, children who have left, new allocation rounds, and idempotent confirmation. HTTP/Livewire tests cover sign-in, roles, attempts to access unauthorized child IDs, token expiry and revocation, deadlines, forms, delivery, and failed attempts. Tests use an isolated SQLite in-memory database. Actual concurrent confirmations and locking behavior must also be tested on the target PostgreSQL/MySQL system.

## Optional demo data

For local use only, with fictional contacts: `php artisan db:seed --class=DemoSeeder --no-interaction`. This creates a demo year, six children with five weekday rules each, a waiting list, an open allocation round with sample responses, and a non-binding allocation proposal for another day. The rules include even/odd weeks and day-based entitlements without individual care hours. The proposal demonstrates both allocation phases. No emails are sent and no administrator accounts are created. Running the seeder again does not duplicate the demo year; it refuses to run in production mode.

## Groups and access permissions

No group is created automatically. Before adding children, explicitly create a group under **Gruppen & Admins** (Groups & admins). Existing administrator accounts without a group assignment retain system-wide access and can manage groups and daycare years. Under **Gruppen & Admins**, these accounts can create and rename groups, create group administrator accounts, and assign existing accounts as group administrators. The last account with system-wide access is preserved.

Create new group administrators with `php artisan careslot:admin gruppe@example.org --group=GRUPPEN-ID`, replacing the group ID placeholder with the actual ID. Each group administrator account manages exactly one group. Omitting `--group` creates an account with system-wide access. Group administrators can manage their group's children, contacts, waiting lists, allocations, delivery requests, and history. Records outside their group remain inaccessible, including through direct requests or manipulated form/Livewire data. Shared daycare years can only be created or archived by system administrators.

Each group has its own alphabetical initial list and rotation for each daycare year. Only system administrators can move a child to another group. If the destination waiting list has been initialized, the child is appended to it; old parent links are revoked, and previous results are retained. Families and contacts are managed per group.

The migration that removes individual child care hours preserves confirmed results and their historical snapshots. Pending proposals are preserved in the audit log and discarded; recalculation is then required. The new group and care-hours migrations do not support an automatic rollback without data loss. In production, use forward migrations or restore from a verified database backup.
