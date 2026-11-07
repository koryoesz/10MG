# 10MG Health: Multi-Tenant Lending Core

A white-label lending backend for healthcare credit. One deployment serves many lenders (banks, microfinance banks, credit unions). Each lender gets its own branding, loan products, pricing, staff and borrowers, fully isolated from the others.

The borrowers are **healthcare businesses**: pharmacies, patent medicine stores, clinics, hospitals, laboratories and diagnostic centres. They apply for inventory, equipment or emergency financing, get scored instantly on their business profile, and go through approval, disbursement and repayment with an audited ledger.

![PHP](https://img.shields.io/badge/PHP-8.3-777BB4?logo=php&logoColor=white)
![Laravel](https://img.shields.io/badge/Laravel-13-FF2D20?logo=laravel&logoColor=white)
![MySQL](https://img.shields.io/badge/MySQL-8.x-4479A1?logo=mysql&logoColor=white)
![Tests](https://img.shields.io/badge/tests-260%20passing-2ea44f)
![Probe](https://img.shields.io/badge/adversarial%20probe-151%2F151-2ea44f)

**Contents:** [Requirements](#requirements) · [Run with Docker](#option-a-docker) · [Run locally with MySQL](#option-b-local-php--mysql) · [Run with SQLite](#option-c-zero-setup-sqlite) · [First API calls](#first-api-calls) · [Demo accounts](#demo-accounts) · [Tests](#running-the-tests) · [Configuration](#configuration) · [Troubleshooting](#troubleshooting) · [API overview](#api-overview) · [Documentation](#documentation)

---

## Requirements

| Option | You need |
|---|---|
| **A. Docker** | Docker Engine 24+ with Compose v2, and a MySQL 8 server on the host |
| **B. Local PHP** | PHP **8.3+** with `pdo_mysql`, `bcmath`, `intl`, `mbstring`, `zip`; Composer 2; MySQL 8 (or PostgreSQL) |
| **C. Zero-setup** | PHP 8.3+ with `pdo_sqlite` and `bcmath`; Composer 2 |

The [demo script](#scripted-demo) also needs `curl` and `jq`.

```bash
git clone <repository-url> 10mg
cd 10mg
```

---

## Option A: Docker

This runs the API (PHP 8.3 + Apache) and a scheduler container against a **MySQL server on your machine**.

**1. Create the database** (skip if it exists):

```bash
mysql -uroot -p -e "CREATE DATABASE \`10mg\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
```

**2. Configure the containers:**

```bash
cp .env.docker.example .env.docker
```

Edit `.env.docker` and set at least:

```dotenv
APP_KEY=base64:...          # generate one: php artisan key:generate --show
DB_HOST=127.0.0.1
DB_DATABASE=10mg
DB_USERNAME=root
DB_PASSWORD="your-mysql-password"
```

**3. Build and start:**

```bash
docker compose up -d --build                         # migrations run automatically on start-up
docker compose exec app php artisan db:seed --force  # load the demo data (first time only)
```

**4. Check it's up:**

```bash
curl http://127.0.0.1:8080/up              # → 200
open http://127.0.0.1:8080/docs/api        # interactive API docs
```

| | |
|---|---|
| API | `http://127.0.0.1:8080/api/v1` |
| Logs | `docker compose logs -f app` |
| Stop | `docker compose down` |
| Rebuild after code changes | `docker compose up -d --build` |

<details>
<summary>How the Docker setup works</summary>

- **Image** (`tenmg/lending-api`): `php:8.3-apache` with `pdo_mysql`, `bcmath`, `intl`, `zip`, `redis` and OPcache. Apache serves `public/` on `$PORT` (default `8080`) and has a health check on `/up`.
- **Start-up** ([`docker/entrypoint.sh`](docker/entrypoint.sh)): waits for MySQL, caches config and routes, runs migrations (`RUN_MIGRATIONS=true`), optionally seeds (`RUN_SEEDERS=true`), then starts Apache.
- **Scheduler service:** runs `php artisan schedule:work` from the same image (daily overdue processing).
- **Host networking:** the containers share the host's network, so `127.0.0.1:3306` is your local MySQL. This lets accounts restricted to `localhost` (like a default `root`) connect without changing MySQL grants. Host networking is Linux-only. On macOS or Windows, set `DB_HOST=host.docker.internal`, replace `network_mode: host` with `ports: ["8080:8080"]` in `docker-compose.yml`, and use a MySQL user that's allowed to connect from Docker's network.
- **Secrets:** `.env.docker` is read at runtime only. It's git-ignored and excluded from the build context, so credentials never end up in the image.
- **Lean production image:** `docker build --build-arg INSTALL_DEV_DEPENDENCIES=false -t tenmg/lending-api .`. Demo seeding needs the dev dependencies.

</details>

---

## Option B: Local PHP + MySQL

```bash
composer install
cp .env.example .env
php artisan key:generate
```

Point `.env` at your database:

```dotenv
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=10mg
DB_USERNAME=root
DB_PASSWORD="your-mysql-password"
```

Create the schema, load demo data and start the server:

```bash
php artisan migrate --seed
php artisan serve                        # http://127.0.0.1:8000
```

In a second terminal, run the scheduler. It handles the daily overdue and late-fee job and pruning of expired idempotency keys:

```bash
php artisan schedule:work
# or once, for any date:  php artisan loans:mark-overdue --date=2027-01-20
```

To reset everything back to the demo data: `php artisan migrate:fresh --seed`.

---

## Option C: Zero-setup (SQLite)

Good for a quick look. There's no database server to install.

```bash
composer install
cp .env.example .env              # DB_CONNECTION=sqlite is the default
php artisan key:generate
touch database/database.sqlite
php artisan migrate --seed
php artisan serve
```

---

## First API calls

All examples assume `BASE=http://127.0.0.1:8000/api/v1`. For Docker, use port `8080`.

```bash
BASE=http://127.0.0.1:8000/api/v1

# 1. Public storefront of a lender (no auth, tenant chosen by the X-Tenant header)
curl -s $BASE/tenant/loan-products -H 'X-Tenant: lagos-mfb' | jq '.data[].name'

# 2. Quote ₦500,000 over 6 months (amounts are in kobo)
curl -s -X POST $BASE/tenant/loan-products/<product-id>/quote \
  -H 'X-Tenant: lagos-mfb' -H 'Content-Type: application/json' \
  -d '{"amount": 50000000, "tenor": 6}' | jq '.data | {total_interest, total_repayable, installment_amount}'

# 3. Log in as the seeded pharmacy owner
TOKEN=$(curl -s -X POST $BASE/auth/login -H 'X-Tenant: lagos-mfb' -H 'Content-Type: application/json' \
  -d '{"email": "ada@okaforpharmacy.test", "password": "Password123"}' | jq -r .data.token)

# 4. Verify the owner's BVN (NIBSS) and NIN (NIMC). Seeded borrowers are already verified;
#    a newly registered one gets 422 KYC_REQUIRED from step 5 until this succeeds.
curl -s -X POST $BASE/me/kyc -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' \
  -H "Idempotency-Key: $(uuidgen)" \
  -d '{"first_name": "Ada", "last_name": "Okafor", "date_of_birth": "1984-06-21", "phone": "+2348031234567", "bvn": "22212345678", "nin": "70012345678"}' \
  | jq '.data | {status, owner}'

# 5. Apply for a loan (money-moving calls need an Idempotency-Key)
curl -s -X POST $BASE/loans -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' \
  -H "Idempotency-Key: $(uuidgen)" \
  -d '{"loan_product_id": "<product-id>", "amount": 20000000, "tenor": 4, "purpose": "inventory_restock"}' \
  | jq '.data | {reference, status, credit_assessment}'
```

Next, log in as `officer@lagos-mfb.test` to **approve**, then as `finance@lagos-mfb.test` to **disburse**. The same user can't do both. Then repay as the borrower.

### Scripted demo

[`scripts/demo.sh`](scripts/demo.sh) runs the whole story end to end in about 15 steps: lender sign-up and approval, branding, products and fees, staff, borrower onboarding, BVN + NIN verification, quote, application with credit scoring, idempotent retry, tenant isolation, role checks, approval, disbursement, repayment, ledger and audit trail.

```bash
./scripts/demo.sh                                       # against http://127.0.0.1:8000
BASE_URL=http://127.0.0.1:8080/api/v1 ./scripts/demo.sh # against Docker
```

### Adversarial probe

[`scripts/probe.sh`](scripts/probe.sh) runs **151 checks** of the kind a senior reviewer would try. It covers cross-tenant loan, borrower and product IDs; suspended lenders and disabled staff; segregation of duties; idempotent retries and reused keys; duplicate payment references; overpayment; repaying a completed or cancelled loan; negative, zero and malformed amounts and tenors; a product deactivated after a quote; the open-loan limit; BVN/NIN KYC (blocked before verification, masked numbers, one BVN per business, 8 simultaneous submissions); and lender-controlled KYC settings (BVN-only, re-enabling a check, KYC off, per-lender isolation). It also fires **real parallel requests**: 12 simultaneous repayments, 10 retries sharing one key, concurrent approve and disburse by two officers, 8 simultaneous applications against the open-loan limit, and 8 identical sign-ups, registrations, product codes and staff emails racing past validation. These checks verify that balances, the ledger and the schedule stay exact, and that the losers get `422`, never `500`. How each race is prevented is documented in [ARCHITECTURE.md](docs/ARCHITECTURE.md#race-conditions).

The server must run with several workers, otherwise requests are handled one at a time and the races are never exercised:

```bash
php artisan migrate:fresh --seed
PHP_CLI_SERVER_WORKERS=12 php artisan serve --no-reload
./scripts/probe.sh                     # exits non-zero if any check fails
```

### Postman

Import [`docs/postman_collection.json`](docs/postman_collection.json). It has **85 requests in eleven folders** and covers every endpoint. It carries **179 request-specific assertions** (status, key fields, maths such as *net payout = principal − upfront fees*, and what each role may see), plus four collection-level checks on every response: no `5xx`, JSON body, an `X-Request-Id` header and the `{message, error.code}` error envelope. **Run collection** in the Postman Runner therefore doubles as an end-to-end test:

| Folder | What it does |
|---|---|
| 1. Platform onboarding | Register a lender, approve it as the platform operator, read the platform audit log |
| 2. Lender configuration | Log in as the lender's admin; **KYC settings**; branding; create, read and reprice a product and add a fee; create the loan officer and finance officer |
| 3. Storefront & borrower onboarding | Public branding (including which KYC checks apply), catalogue and quote (`X-Tenant`); register a pharmacy |
| 4. Borrower | Profile, **verify the owner's BVN & NIN**, update trading figures, apply for a loan |
| 5. Back office decisions | Officer views the borrower's KYC status and approves; finance disburses, reads the schedule, records a repayment, views the ledger |
| 6. Alternative endings | The borrower cancels one application; the officer rejects another |
| 7. Guard rails | Expected refusals: apply before KYC, malformed BVN/NIN, missing idempotency key, re-verifying KYC, borrower approving, cross-tenant header, overpayment, borrower reporting cash, approving a rejected loan |
| 8. KYC policy: the lender's choice | The admin switches to BVN only, the borrower verifies without a NIN, NIN is switched back on (now outstanding), KYC is turned off (borrower can apply), an invalid combination is refused, defaults restored |
| 9. 10MG platform fee | The operator switches the lender to a borrower-paid 1% fee, the quote now lists it, the lender and the operator each view the fee ledger, and the original terms are restored |
| 10. Housekeeping (optional) | Deactivate/reactivate staff, remove a fee, archive a product, log out, suspend/reactivate the lender |
| 11. Act as a seeded borrower (optional) | Log in as `borrower_email`; run on its own with `tenant` set to that borrower's lender |

| Variable | Default | Change it to |
|---|---|---|
| `base_url` | `http://127.0.0.1:8080/api/v1` | `http://127.0.0.1:8000/api/v1` when running `php artisan serve` |
| `tenant` | `lagos-mfb` | the lender to work as, e.g. `ikeja-mfb` |
| `admin_email` | `admin@lagos-mfb.test` | that lender's admin email |
| `other_tenant` | `abuja-credit` | any lender other than `tenant` (used by the cross-tenant check) |
| `borrower_email` | `ada@okaforpharmacy.test` | a seeded borrower of `tenant` (folder 11 only) |

**Tokens** are kept in three variables so logging in as one actor never breaks another's requests: `operator_token` (platform), `token` (lender staff) and `borrower_token` (the pharmacy). IDs, the first installment amount and tokens are captured automatically. Every money-moving request gets a fresh `Idempotency-Key`, and product codes and borrower details are timestamped, so the walkthrough can be re-run. For a lender that already exists, *Register lender* (422) and *Activate lender* (409) accept the "already done" answer.

To work as a different lender, change `tenant` and `admin_email`. After login the token alone scopes every request to that lender; `X-Tenant` is only sent on login and public storefront calls.

---

## Demo accounts

Created by `php artisan db:seed`. **Password for all: `Password123`**

| Role | Email | Send header |
|---|---|---|
| Platform operator (approves lenders) | `operator@10mg.test` | none |
| Lagos MFB: admin / loan officer / finance officer | `admin@lagos-mfb.test` · `officer@lagos-mfb.test` · `finance@lagos-mfb.test` | `X-Tenant: lagos-mfb` |
| Abuja Credit Union: admin / loan officer / finance officer | `admin@abuja-credit.test` · `officer@abuja-credit.test` · `finance@abuja-credit.test` | `X-Tenant: abuja-credit` |
| Borrower: Okafor Family Pharmacy (has a part-repaid loan; owner KYC-verified) | `ada@okaforpharmacy.test` | `X-Tenant: lagos-mfb` |
| Borrower: Bello Specialist Clinic (owner KYC-verified) | `musa@belloclinic.test` | `X-Tenant: abuja-credit` |

`X-Tenant` is only needed to **log in** and for public storefront calls. After login, the token carries the institution.

**Seeded products:**
- Lagos MFB: *Pharmacy Inventory Finance* (pharmacies and patent medicine stores only) and *Medical Equipment Loan* (clinics, hospitals and labs; minimum score 620).
- Abuja Credit Union: *Emergency Care Bridge* (weekly repayments, open to all).
- **10MG platform fee:** Lagos uses the platform default (0.5%, paid by the lender); Abuja passes a 1% fee to its borrowers.
- **Pending lender:** `kano-capital` is waiting for approval, so you can try the onboarding flow.

---

## Running the tests

```bash
php artisan test                    # 260 tests; in-memory SQLite, ~5 s
```

To run the same suite against MySQL, use a **separate** database, because the tests reset the schema:

```bash
mysql -uroot -p -e "CREATE DATABASE \`10mg_testing\`;"
DB_CONNECTION=mysql DB_DATABASE=10mg_testing DB_USERNAME=root DB_PASSWORD="your-mysql-password" php artisan test
```

Code style: `vendor/bin/pint`

The suite covers the money maths, credit scoring, tenant isolation, role checks, the full loan lifecycle, idempotency, the audit trail and overdue processing. It also includes **architecture tests** that fail the build if a controller contains business logic or a service queries the database directly.

---

## Configuration

Main settings (in `.env`, or `.env.docker` for Docker):

| Variable | Default | Purpose |
|---|---|---|
| `APP_KEY` | none | Required. Generate with `php artisan key:generate` |
| `DB_CONNECTION` / `DB_HOST` / `DB_PORT` / `DB_DATABASE` / `DB_USERNAME` / `DB_PASSWORD` | `sqlite` | Database connection |
| `CACHE_STORE` | `database` | Cache and idempotency locks. Use `redis` (with `REDIS_HOST`) in production |
| `LENDING_MAX_OPEN_LOANS` | `3` | Open loans allowed per borrower |
| `LENDING_ENFORCE_FOUR_EYES` | `true` | The approver of a loan may not disburse it |
| `LENDING_REQUIRE_KYC` / `LENDING_KYC_BVN_CHECK` / `LENDING_KYC_NIN_CHECK` / `LENDING_KYC_PHONE_MATCH` | `true` / `true` / `true` / `false` | **Defaults only.** Each lender sets its own KYC policy with `PATCH /kyc-settings`; these apply until it does |
| `PLATFORM_FEE_BPS` / `PLATFORM_FEE_BEARER` | `50` / `lender` | **Default** 10MG fee for lenders a platform operator hasn't set individually (`PATCH /platform/institutions/{id}/platform-fee`). Basis points of the principal; `lender` or `borrower` pays |
| `NIBSS_MODE` / `NIMC_MODE` | `sandbox` | `sandbox` builds the real request but answers locally with a full match; `live` calls the provider |
| `NIBSS_BASE_URL` / `NIBSS_CLIENT_ID` / `NIBSS_CLIENT_SECRET` (and `NIMC_*`) | none | Provider endpoint and OAuth2 client credentials for `live` mode |
| `IDEMPOTENCY_TTL_HOURS` | `24` | How long a stored response can be replayed |
| `PORT` *(Docker)* | `8080` | Port Apache listens on |
| `RUN_MIGRATIONS` / `RUN_SEEDERS` *(Docker)* | `true` / `false` | Run on container start-up |

---

## Troubleshooting

| Symptom | Fix |
|---|---|
| `permission denied … /var/run/docker.sock` | Add yourself to the docker group: `sudo usermod -aG docker $USER && newgrp docker`, or prefix commands with `sudo` |
| Container logs `MySQL is unreachable: Access denied for user 'root'@'172.…'` | You're not on host networking, or your MySQL user only allows `localhost`. Keep `network_mode: host` (Linux), or create a MySQL user allowed from Docker's network |
| `Address already in use` on 8080 / 8000 | Change `PORT` in `.env.docker`, or use `php artisan serve --port=8001` |
| `400 TENANT_REQUIRED` | Public and login endpoints need `X-Tenant: <lender-slug>` |
| `400 IDEMPOTENCY_KEY_REQUIRED` | Add an `Idempotency-Key: <uuid>` header to KYC, apply, approve, reject, cancel, disburse and repay requests |
| `422 KYC_REQUIRED` when applying | The borrower's owner hasn't been verified. Call `POST /me/kyc` (Postman: *4. Borrower → Verify owner BVN & NIN*). Borrowers created before the KYC migration start as `pending` |
| `422 KYC_VERIFICATION_FAILED` | Name or date of birth didn't match the BVN / NIN record; `error.details` lists the failed fields. Correct them and resubmit |
| `409 KYC_ALREADY_VERIFIED` | The owner has passed every check the lender runs; nothing to do |
| `409 KYC_NOT_ENABLED` | The lender has switched off both identity checks, so there is nothing to verify |
| `422 KYC_REQUIRED` with `outstanding_checks: ["nin"]` | The lender switched on a check after the borrower verified; submit `POST /me/kyc` again |
| `503 IDENTITY_PROVIDER_UNAVAILABLE` | Only in `live` mode: NIBSS or NIMC is unreachable. Nothing was stored; retry, or set `NIBSS_MODE` / `NIMC_MODE` to `sandbox` |
| `403 TENANT_INACTIVE` on login | The lender is still `pending`. Log in as `operator@10mg.test` and call `POST /platform/institutions/{id}/activate` |
| `403 SEGREGATION_OF_DUTIES` | The user who approved the loan can't disburse it. Use a different finance officer or admin |
| `/docs/api` returns 403 | API docs are served when `APP_ENV=local` |

---

## API overview

**Conventions:**
- **Base path:** `/api/v1`.
- **Auth:** `Authorization: Bearer <token>` (tokens last 30 days).
- **Money:** integers in **kobo**, so `20000000` = ₦200,000.
- **Rates:** **basis points**, so `2400` = 24% p.a.
- **Errors:** `{"message": "...", "error": {"code": "MACHINE_CODE", "details": {}}}`.
- **Tracing:** every response carries `X-Request-Id`.

| Area | Endpoints | Who |
|---|---|---|
| Onboarding | `POST /institutions` · `POST /tenant/customers` | public |
| Auth | `POST /auth/login` · `GET /auth/me` · `POST /auth/logout` | |
| Platform | `GET /platform/institutions` · `GET /platform/institutions/{id}` (incl. KYC settings and platform fee) · `POST /platform/institutions/{id}/activate` · `…/suspend` · `PATCH /platform/institutions/{id}/platform-fee` · `GET /platform/platform-fees` · `GET /platform/audit-logs` | platform operator |
| Storefront | `GET /tenant` · `GET /tenant/loan-products` · `POST /tenant/loan-products/{id}/quote` | public + `X-Tenant` |
| White-label config | `GET`/`PATCH /branding` · `GET`/`PATCH /kyc-settings` · `/loan-products` (CRUD) · `/loan-products/{id}/fees` | institution admin |
| Staff | `GET`/`POST /staff` · `PATCH /staff/{id}` | institution admin |
| Borrowers | `GET /customers` · `GET /customers/{id}` · `GET /customers/{id}/loans` | staff |
| Self-service | `GET`/`PATCH /me/business` · `GET`/`POST /me/kyc` (BVN + NIN) | borrower |
| Loans | `GET /loans` · `GET /loans/{id}` · `…/schedule` · `…/transactions` | staff (all) / borrower (own) |
| | `POST /loans` (apply) | borrower |
| | `POST /loans/{id}/approve` · `…/reject` | admin, loan officer |
| | `POST /loans/{id}/disburse` | admin, finance officer |
| | `POST /loans/{id}/repayments` | finance staff, borrower (own loan) |
| | `POST /loans/{id}/cancel` | borrower (own loan), reviewers |
| Audit | `GET /audit-logs` | institution admin |
| Platform fees owed to 10MG | `GET /platform-fees` | institution admin, finance officer |

The full request and response schemas are at **`/docs/api`** (interactive) and in [`docs/openapi.json`](docs/openapi.json).

---

## Documentation

| Document | What's in it |
|---|---|
| [Architecture & Database Design (PDF)](docs/10MG-Architecture-and-Database-Design.pdf) | 29 pages: architecture and deployment diagrams, tenancy, access control, loan state machine, credit scorecard, BVN/NIN KYC and per-lender KYC settings, 10MG platform fee, financial integrity and race conditions, ERD and the full data dictionary. Rebuild with `./scripts/design-pdf/build.sh` (reads the schema from MySQL) |
| [`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md) | The same design in Markdown |
| [`docs/openapi.json`](docs/openapi.json) | OpenAPI 3.1 spec |
| [`docs/postman_collection.json`](docs/postman_collection.json) | Postman collection |

### Highlights

- **Tenant isolation:** every tenant table carries `institution_id`, and a global scope filters every query. If no tenant is known, the scope returns nothing. Cross-tenant IDs return `404`.
- **Access control:** five roles map to twelve permissions. Approval and disbursement are separate, and the approver can't disburse the same loan.
- **Healthcare credit scoring:** an explainable scorecard covering operating licence, debt-service coverage, years trading, provider type, revenue and existing loans. It sits behind an interface, ready for the real AI model.
- **Financial integrity:**
  - Integer kobo and bcmath; schedules sum exactly.
  - Database transactions with row locks; every race condition is reproduced under real concurrency and guarded (see [race conditions](docs/ARCHITECTURE.md#race-conditions)).
  - `Idempotency-Key` replay, and payment references can't be applied twice.
  - Append-only ledger and audit trail.
- **10MG platform fee:** a platform operator sets 10MG's fee per lender (basis points of the principal) and who pays it: the **lender** (a commission; borrower cost unchanged) or the **borrower** (an upfront fee shown in the quote and deducted from the payout). Terms are locked onto each loan at application; at disbursement the fee goes into an append-only `platform_fees` ledger that the operator and each lender can report on.
- **KYC (BVN + NIN):** the owner's BVN is matched with NIBSS and NIN with NIMC (`NibssService`/`NimcService` → `BvnService`/`NinService` → `KycService`) before any loan application. **Each lender chooses its own KYC policy** (`/kyc-settings`): require KYC or not, run the BVN check, the NIN check, or both, and optionally require the phone to match; switching a check on later makes it outstanding for borrowers who have not passed it. Numbers are encrypted at rest, fingerprinted with an HMAC for uniqueness, masked in every response and never written to the audit log.
- **Code structure:** Controller → Service → Repository, enforced by architecture tests.

### Project layout

```
app/
├── Http/            Controllers (thin), Middleware (tenancy, idempotency), Requests (validation), Resources
├── Services/        business rules: auth, institutions, branding, staff, customers, KYC, products, loans/*
│   └── Identity/    NIBSS (BVN) and NIMC (NIN) clients, BvnService, NinService
├── Repositories/    data access: Contracts/ (interfaces) + Eloquent/ (implementations)
├── Models/ Enums/ Policies/ Exceptions/ Console/ Support/
database/            migrations, factories, demo seeder
docker/              Apache config, PHP config, container entrypoint
docs/                design docs, OpenAPI spec, Postman collection
scripts/demo.sh      end-to-end walkthrough
scripts/probe.sh     adversarial checks, incl. real concurrency
scripts/design-pdf/  generator for the Architecture & Database Design PDF
tests/               Unit + Feature suites (PHPUnit)
```
