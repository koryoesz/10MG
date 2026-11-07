"""Builds the Architecture & Database Design document as print-ready HTML.

The data dictionary is generated from the live MySQL schema exports (columns.tsv,
indexes.tsv, fks.tsv) so it always matches the migrated database exactly.

Usage: python3 generate.py OUT.html SCHEMA_DIR   (scripts/design-pdf/build.sh does all of it)
"""
import csv
import html
import sys
from collections import defaultdict
from pathlib import Path

OUT = Path(sys.argv[1])
SCHEMA = Path(sys.argv[2]) if len(sys.argv) > 2 else Path(__file__).parent

# 10MG brand: the logo is a single-colour mark; on the brand-blue cover it is shown reversed (white).
BRAND_BLUE = "#0E78D9"
LOGO_WHITE = (Path(__file__).parent / "10mg-logo.svg").read_text().replace(f'fill="{BRAND_BLUE}"', 'fill="#FFFFFF"')

# ---------------------------------------------------------------- schema data
columns = defaultdict(list)
for t, c, typ, nullable, key, default in csv.reader(open(SCHEMA / "columns.tsv"), delimiter="\t"):
    columns[t].append((c, typ, nullable == "YES", key, default))

indexes = defaultdict(list)
for t, name, cols, non_unique in csv.reader(open(SCHEMA / "indexes.tsv"), delimiter="\t"):
    indexes[t].append((name, cols.split(","), non_unique == "0"))

fks = defaultdict(dict)
for t, c, ref, rule in csv.reader(open(SCHEMA / "fks.tsv"), delimiter="\t"):
    fks[t][c] = (ref, rule)

TABLES = [
    ("institutions", "Tenants: the lenders (banks, MFBs, credit unions) running on the platform. Not tenant-scoped itself."),
    ("institution_brandings", "One-to-one white-label profile per institution, served to the public storefront."),
    ("institution_kyc_settings", "One-to-one KYC policy per lender: whether verification is required and which checks (BVN, NIN, phone match) it runs."),
    ("users", "Every login: platform operators (institution_id NULL), lender staff and borrower owners. Email is unique per institution."),
    ("customers", "Borrowing healthcare businesses. The linked user is the owner or primary contact."),
    ("customer_directors", "The people behind a borrowing business. Holds the owner's BVN and NIN (encrypted) and the NIBSS / NIMC verification outcome."),
    ("loan_products", "Each lender's configurable products: limits, pricing, repayment and overdue rules, eligibility."),
    ("loan_product_fees", "Fees attached to a product, either deducted upfront or capitalised into the repayable amount."),
    ("loans", "Applications and loans, with a snapshot of the product terms and the running balances."),
    ("credit_assessments", "The scorer's explainable decision for a loan application (one per loan)."),
    ("loan_installments", "The repayment schedule generated at disbursement, with per-component due/paid tracking."),
    ("loan_transactions", "Append-only money ledger: disbursements, fees, repayments and penalties."),
    ("audit_logs", "Append-only audit trail of every privileged action, with actor, request id and value diffs."),
    ("platform_fees", "Append-only 10MG revenue ledger: the platform fee on each disbursed loan, owed by the lender to 10MG."),
    ("idempotency_keys", "Stored responses for retried mutating requests, keyed per user. Pruned after 24 hours."),
    ("personal_access_tokens", "Sanctum bearer tokens (hashed), polymorphic to users, with expiry."),
]

DESC = {
    "*": {
        "id": "Primary key (ULID unless noted).",
        "institution_id": "Owning tenant. Every tenant query is filtered on this column.",
        "created_at": "Creation time.",
        "updated_at": "Last update time.",
        "deleted_at": "Soft-delete marker.",
    },
    "platform_fees": {
        "loan_id": "The disbursed loan (unique: a loan is charged once).",
        "bearer": "lender (commission) or borrower (upfront fee on the loan).",
        "rate_bps": "Rate locked in when the borrower applied (basis points of the principal).",
        "base_amount": "Principal the fee was calculated on (minor units).",
        "amount": "Fee in minor units.",
        "currency": "ISO 4217, copied from the loan.",
        "accrued_at": "Disbursement time; filters revenue reports.",
    },
    "institutions": {
        "platform_fee_bps": "10MG fee for this lender in basis points; NULL = platform default (PLATFORM_FEE_BPS).",
        "platform_fee_bearer": "lender or borrower; NULL = platform default (PLATFORM_FEE_BEARER).",
        "slug": "Tenant identifier used in the X-Tenant header.",
        "status": "pending → active ↔ suspended.",
        "currency": "ISO 4217 currency; copied onto every product.",
        "country_code": "ISO 3166-1 alpha-2.",
        "approved_at": "When a platform operator activated it.",
        "suspended_at": "When it was suspended (tokens are revoked).",
    },
    "institution_brandings": {
        "institution_id": "Owning tenant (unique: one branding per institution).",
        "primary_color": "Hex colour, e.g. #0B5FFF.",
        "secondary_color": "Hex colour.",
        "custom_domain": "White-label domain; globally unique.",
        "logo_url": "HTTPS logo URL.",
    },
    "institution_kyc_settings": {
        "institution_id": "Owning lender (unique: one policy per institution).",
        "kyc_required": "Borrowers must pass every enabled check before applying.",
        "bvn_check_enabled": "Match the owner's BVN with NIBSS.",
        "nin_check_enabled": "Match the owner's NIN with NIMC.",
        "phone_match_required": "A phone mismatch fails the check instead of only being recorded.",
    },
    "users": {
        "institution_id": "Tenant; NULL only for platform operators.",
        "role": "platform_admin, institution_admin, loan_officer, finance_officer, customer.",
        "is_active": "Deactivation also revokes all tokens.",
        "password": "Bcrypt hash.",
        "last_login_at": "Last successful login.",
    },
    "customers": {
        "user_id": "Owner / primary contact login (unique).",
        "business_type": "pharmacy, patent_medicine_store, clinic, hospital, laboratory, diagnostic_centre.",
        "registration_number": "CAC number (RC/BN/IT…), unique per institution.",
        "licence_number": "Operating licence from the regulator (PCN, MDCN, MLSCN, State MoH).",
        "licence_expires_at": "An expired licence forces a decline recommendation.",
        "year_established": "Drives years-in-operation scoring.",
        "monthly_revenue": "Minor units (kobo).",
        "monthly_expenses": "Minor units; revenue − expenses = net cash flow.",
        "phone": "Unique per institution.",
        "kyc_status": "Outcome of the latest attempt: pending, verified, failed.",
        "kyc_checks": "Checks passed so far, e.g. [\"bvn\",\"nin\"]; compared with the lender's current settings.",
        "kyc_verified_at": "When both BVN and NIN passed.",
    },
    "customer_directors": {
        "customer_id": "The business this person owns or directs.",
        "role": "owner or director; unique per business.",
        "first_name": "As submitted; matched against NIBSS and NIMC.",
        "middle_name": "Optional; matched when supplied.",
        "last_name": "Must match the BVN and NIN records.",
        "date_of_birth": "Must match the BVN and NIN records.",
        "phone": "Recorded; a mismatch with the BVN phone is tolerated.",
        "bvn": "Bank Verification Number, encrypted (AES-256, APP_KEY). Never serialised. NULL when the lender does not run the BVN check.",
        "bvn_hash": "HMAC-SHA256 fingerprint; unique per institution (one BVN, one business).",
        "bvn_last4": "Last four digits for masked display.",
        "nin": "National Identification Number, encrypted. Never serialised. NULL when the lender does not run the NIN check.",
        "nin_hash": "HMAC-SHA256 fingerprint; unique per institution.",
        "nin_last4": "Last four digits for masked display.",
        "bvn_reference": "NIBSS lookup reference.",
        "bvn_verified_at": "Set when NIBSS matched name and date of birth.",
        "nin_reference": "NIMC lookup reference.",
        "nin_verified_at": "Set when NIMC matched name and date of birth.",
        "verification_checks": "Per-field matches and failure reasons from the latest attempt.",
    },
    "loan_products": {
        "code": "Product code, unique per institution.",
        "min_amount": "Minor units.",
        "max_amount": "Minor units.",
        "min_tenor": "Number of installments.",
        "max_tenor": "Number of installments.",
        "interest_rate_bps": "Annual rate in basis points (2400 = 24% p.a.).",
        "interest_method": "flat or reducing_balance.",
        "repayment_frequency": "weekly, biweekly or monthly.",
        "grace_period_days": "Days after due date before an installment is overdue.",
        "late_fee_type": "fixed (minor units) or percentage (bps of installment outstanding).",
        "late_fee_value": "Late fee amount or rate.",
        "min_credit_score": "Product-specific decline threshold (default 560).",
        "eligible_business_types": "JSON list of allowed business types; NULL = all.",
        "is_active": "Inactive products cannot be applied for.",
    },
    "loan_product_fees": {
        "calculation": "fixed (minor units) or percentage (bps of principal).",
        "value": "Fee amount or rate.",
        "charge_type": "upfront (deducted at payout) or capitalized (spread over installments).",
    },
    "loans": {
        "platform_fee_bps": "10MG fee rate locked in at application.",
        "platform_fee_bearer": "Who pays 10MG's fee on this loan, locked in at application.",
        "platform_fee_amount": "10MG fee in minor units (recorded in platform_fees at disbursement).",
        "reference": "Human-readable reference, e.g. LN-20261105-7KQ2M9AB.",
        "status": "pending, approved, rejected, cancelled, disbursed, repaid.",
        "principal_amount": "Minor units.",
        "tenor": "Number of installments.",
        "interest_rate_bps": "Snapshot of product rate.",
        "interest_method": "Snapshot.",
        "repayment_frequency": "Snapshot.",
        "fees_snapshot": "JSON copy of the product's fees at application time.",
        "grace_period_days": "Snapshot of overdue rules.",
        "late_fee_type": "Snapshot.",
        "late_fee_value": "Snapshot.",
        "total_interest": "Minor units.",
        "upfront_fees": "Deducted from the payout.",
        "capitalized_fees": "Added to the repayable amount.",
        "penalty_fees": "Late fees charged so far.",
        "net_disbursement_amount": "Principal − upfront fees (cash paid out).",
        "total_repayable": "Principal + interest + capitalised fees + penalties.",
        "amount_paid": "Running total, changed only under row lock.",
        "outstanding_balance": "total_repayable − amount_paid.",
        "purpose": "inventory_restock, medical_equipment, working_capital, emergency_financing, facility_expansion, other.",
        "reviewed_by": "Approving/rejecting user.",
        "disbursed_by": "Releasing user (must differ from reviewed_by).",
        "disbursement_reference": "Payment-rail reference.",
        "decision_reason": "Approval note, override reason or rejection reason.",
    },
    "credit_assessments": {
        "loan_id": "Assessed loan (unique).",
        "provider": "Scoring provider id (mock-healthcare-scorer).",
        "score": "300–850.",
        "risk_grade": "A–E.",
        "recommendation": "approve, review or decline.",
        "debt_service_coverage_bps": "Net cash flow ÷ monthly repayment (15000 = 1.50x).",
        "net_monthly_cash_flow": "Revenue − expenses at assessment time.",
        "max_recommended_amount": "Largest principal keeping coverage at 1.50x.",
        "factors": "JSON list of {factor, impact, detail}: the explanation.",
    },
    "loan_installments": {
        "sequence": "1-based installment number, unique per loan.",
        "due_date": "Derived from disbursement date and frequency.",
        "total_due": "principal + interest + fee + penalty due.",
        "status": "pending, partially_paid, paid, overdue.",
    },
    "loan_transactions": {
        "id": "Primary key (ULID).",
        "type": "disbursement, fee, repayment, penalty.",
        "amount": "Minor units.",
        "reference": "Internal unique reference (TXN-…).",
        "external_reference": "Provider reference; unique per institution, so it can't be applied twice.",
        "balance_before": "Outstanding balance before the entry.",
        "balance_after": "Outstanding balance after the entry.",
        "allocation": "JSON waterfall split per installment (penalty, fee, interest, principal).",
        "performed_by": "Acting user (NULL for system jobs).",
    },
    "audit_logs": {
        "id": "Primary key (auto-increment).",
        "institution_id": "Tenant (NULL for platform-level events).",
        "action": "Dotted event name, e.g. loan.approved.",
        "auditable_type": "Morph alias of the subject (loan, customer, …).",
        "old_values": "JSON of changed attributes before.",
        "new_values": "JSON of changed attributes after.",
        "request_id": "X-Request-Id of the originating request.",
    },
    "idempotency_keys": {
        "id": "Primary key (auto-increment).",
        "idempotency_key": "Client-supplied key; unique per user.",
        "request_hash": "SHA-256 of method + path + canonical body.",
        "status": "processing or completed.",
        "response_body": "Stored response replayed on retry.",
        "expires_at": "24 h after creation; pruned daily.",
    },
    "personal_access_tokens": {
        "id": "Primary key (auto-increment).",
        "token": "SHA-256 hash of the bearer token.",
        "tokenable_id": "Owning user (ULID morph).",
        "expires_at": "Tokens expire after 30 days.",
    },
}


def esc(s):
    return html.escape(str(s))


def describe(table, col):
    if col in DESC.get(table, {}):
        return DESC[table][col]
    if col in fks[table]:
        return f"References {fks[table][col][0]}."
    return DESC["*"].get(col, "")


def data_dictionary():
    out = []
    for table, purpose in TABLES:
        rows = []
        for c, typ, nullable, key, default in columns[table]:
            flags = []
            if key == "PRI":
                flags.append('<span class="tag pk">PK</span>')
            if c in fks[table]:
                ref, rule = fks[table][c]
                flags.append(f'<span class="tag fk" title="ON DELETE {esc(rule)}">FK</span>')
            if any(unique and idx_cols == [c] and name != "PRIMARY" for name, idx_cols, unique in indexes[table]):
                flags.append('<span class="tag uk">UQ</span>')
            rows.append(
                f"<tr><td class='mono'>{esc(c)}</td><td class='mono type'>{esc(typ)}</td>"
                f"<td class='c'>{'yes' if nullable else ''}</td><td>{''.join(flags)}</td>"
                f"<td>{esc(describe(table, c))}</td></tr>"
            )
        idx = []
        for name, cols, unique in sorted(indexes[table], key=lambda x: (x[0] != "PRIMARY", x[0])):
            if name == "PRIMARY":
                continue
            kind = "UNIQUE" if unique else "INDEX"
            idx.append(f"<li><b>{kind}</b> <span class='mono'>({esc(', '.join(cols))})</span></li>")
        fk_list = [
            f"<li><span class='mono'>{esc(c)}</span> → <span class='mono'>{esc(ref)}</span> <span class='muted'>on delete {esc(rule.lower())}</span></li>"
            for c, (ref, rule) in fks[table].items()
        ]
        out.append(f"""
<section class="dd" id="t-{table}">
  <h3><span class="mono">{table}</span></h3>
  <p class="purpose">{esc(purpose)}</p>
  <table class="cols">
    <thead><tr><th style="width:22%">Column</th><th style="width:19%">Type</th><th style="width:6%">Null</th><th style="width:10%">Key</th><th>Notes</th></tr></thead>
    <tbody>{''.join(rows)}</tbody>
  </table>
  <div class="meta">
    {'<div><h4>Indexes</h4><ul>' + ''.join(idx) + '</ul></div>' if idx else ''}
    {'<div><h4>Foreign keys</h4><ul>' + ''.join(fk_list) + '</ul></div>' if fk_list else ''}
  </div>
</section>""")
    return "\n".join(out)


# ---------------------------------------------------------------- diagrams
ACCENT = "#0F766E"
INK = "#0F172A"
MUTED = "#64748B"
LINE = "#94A3B8"
SOFT = "#F1F5F9"
TINT = "#ECFDF5"


def box(x, y, w, h, title, sub=None, fill="#fff", stroke=LINE, title_color=INK, rx=8, bold=True):
    s = f'<rect x="{x}" y="{y}" width="{w}" height="{h}" rx="{rx}" fill="{fill}" stroke="{stroke}" stroke-width="1.2"/>'
    ty = y + (h / 2 if not sub else h / 2 - 6)
    s += f'<text x="{x + w / 2}" y="{ty}" text-anchor="middle" dominant-baseline="middle" font-size="13" font-weight="{"600" if bold else "400"}" fill="{title_color}">{esc(title)}</text>'
    if sub:
        s += f'<text x="{x + w / 2}" y="{ty + 16}" text-anchor="middle" dominant-baseline="middle" font-size="10.5" fill="{MUTED}">{esc(sub)}</text>'
    return s


def arrow_defs():
    return f"""<defs>
<marker id="arr" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="7" markerHeight="7" orient="auto-start-reverse"><path d="M0,0 L10,5 L0,10 z" fill="{MUTED}"/></marker>
<marker id="arrA" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="7" markerHeight="7" orient="auto-start-reverse"><path d="M0,0 L10,5 L0,10 z" fill="{ACCENT}"/></marker>
</defs>"""


def architecture_svg():
    s = [f'<svg viewBox="0 0 1000 690" xmlns="http://www.w3.org/2000/svg" font-family="Noto Sans, DejaVu Sans, sans-serif">', arrow_defs()]
    # clients
    s.append(f'<text x="20" y="22" font-size="11" font-weight="700" fill="{MUTED}" letter-spacing="1">CLIENTS</text>')
    for i, (t, sub) in enumerate([("Lender storefronts", "white-labelled web / mobile"), ("Back-office portal", "admins, officers, finance"), ("Platform console", "10MG operators")]):
        s.append(box(20 + i * 330, 34, 300, 54, t, sub))
    s.append(f'<line x1="500" y1="92" x2="500" y2="126" stroke="{MUTED}" stroke-width="1.4" marker-end="url(#arr)"/>')
    s.append(f'<text x="512" y="113" font-size="10.5" fill="{MUTED}">HTTPS · Bearer token · X-Tenant · Idempotency-Key</text>')
    # edge
    s.append(box(20, 130, 960, 40, "Edge: CloudFront + WAF → Application Load Balancer (per-tenant custom domains via ACM)", fill=SOFT, bold=False))
    s.append(f'<line x1="500" y1="170" x2="500" y2="196" stroke="{MUTED}" stroke-width="1.4" marker-end="url(#arr)"/>')
    # API container
    s.append(f'<rect x="20" y="200" width="960" height="300" rx="12" fill="{TINT}" stroke="{ACCENT}" stroke-width="1.4"/>')
    s.append(f'<text x="38" y="224" font-size="12" font-weight="700" fill="{ACCENT}">Laravel 13 API: stateless, horizontally scalable (ECS Fargate)</text>')
    layers = [
        ("Middleware pipeline", "request id · auth · tenant · bindings · RBAC · idempotency"),
        ("Controllers + FormRequests", "validate → call one service method → return a Resource"),
        ("Services (business rules)", "LoanApplication · LoanDecision · Disbursement · Repayment · … · one DB tx each"),
        ("Repositories (data access)", "12 interfaces + Eloquent implementations · queries & row locks"),
    ]
    for i, (t, sub) in enumerate(layers):
        y = 236 + i * 64
        s.append(box(40, y, 600, 52, t, sub))
        if i < 3:
            s.append(f'<line x1="340" y1="{y + 52}" x2="340" y2="{y + 64}" stroke="{MUTED}" stroke-width="1.2" marker-end="url(#arr)"/>')
    s.append(box(670, 236, 290, 116, "Integration contracts", "CreditBureau · DisbursementGateway", fill="#fff"))
    s.append(f'<text x="815" y="320" text-anchor="middle" dominant-baseline="middle" font-size="10.5" fill="{MUTED}">BvnRegistry (NIBSS) · NinRegistry (NIMC)</text>')
    s.append(f'<text x="815" y="338" text-anchor="middle" dominant-baseline="middle" font-size="10" fill="{MUTED}">bound in AppServiceProvider · swap by binding / env</text>')
    s.append(box(670, 364, 290, 116, "Scheduler (separate process)", "loans:mark-overdue · daily 00:30", fill="#fff"))
    s.append(f'<text x="815" y="448" text-anchor="middle" font-size="10.5" fill="{MUTED}">model:prune idempotency_keys · daily</text>')
    s.append(f'<line x1="640" y1="294" x2="668" y2="294" stroke="{MUTED}" stroke-width="1.2" marker-end="url(#arr)"/>')
    # data + external
    s.append(f'<text x="20" y="530" font-size="11" font-weight="700" fill="{MUTED}" letter-spacing="1">DATA &amp; EXTERNAL SYSTEMS</text>')
    s.append(box(20, 542, 300, 66, "MySQL 8 / Aurora (RDS)", "shared schema · row-level tenancy · FKs", fill="#fff", stroke=ACCENT))
    s.append(box(350, 542, 300, 66, "Redis (ElastiCache)", "idempotency locks · rate limits · cache · queues", fill="#fff", stroke=ACCENT))
    s.append(box(680, 542, 300, 66, "Credit · Payment rail · NIBSS / NIMC", "scorer & rail mocked · identity: sandbox or live HTTP", fill="#fff", stroke="#CBD5E1"))
    for x in (170, 500):
        s.append(f'<line x1="{x}" y1="500" x2="{x}" y2="538" stroke="{ACCENT}" stroke-width="1.4" marker-end="url(#arrA)"/>')
    s.append(f'<line x1="830" y1="352" x2="830" y2="360" stroke="{MUTED}" stroke-width="0"/>')
    s.append(f'<path d="M 960 300 C 990 300 990 520 830 538" fill="none" stroke="{MUTED}" stroke-width="1.2" stroke-dasharray="4 3" marker-end="url(#arr)"/>')
    s.append(f'<text x="20" y="640" font-size="10.5" fill="{MUTED}">Secrets: AWS Secrets Manager · Logs: CloudWatch → S3 (Object Lock) for an immutable audit archive · All services encrypted at rest.</text>')
    s.append(f'<text x="20" y="660" font-size="10.5" fill="{MUTED}">The AWS layout is the documented deployment target; the submission runs locally or via docker compose (MySQL + Redis + scheduler).</text>')
    s.append("</svg>")
    return "".join(s)


def pipeline_svg():
    steps = [
        ("AssignRequestId", "trace id"),
        ("auth:sanctum", "401 if no token"),
        ("ResolveTenant", "403/404/400"),
        ("SubstituteBindings", "scoped → 404"),
        ("Authorize (can)", "403"),
        ("EnforceIdempotency", "replay / 409 / 422"),
        ("Controller → Service", "→ Repository"),
    ]
    w, gap = 128, 10
    s = [f'<svg viewBox="0 0 {len(steps) * (w + gap)} 92" xmlns="http://www.w3.org/2000/svg" font-family="Noto Sans, DejaVu Sans, sans-serif">', arrow_defs()]
    for i, (t, sub) in enumerate(steps):
        x = i * (w + gap)
        fill = TINT if i in (2, 5) else "#fff"
        stroke = ACCENT if i in (2, 5) else LINE
        s.append(f'<rect x="{x + 1}" y="14" width="{w}" height="62" rx="8" fill="{fill}" stroke="{stroke}" stroke-width="1.2"/>')
        s.append(f'<text x="{x + 1 + w / 2}" y="40" text-anchor="middle" font-size="11.5" font-weight="600" fill="{INK}">{esc(t)}</text>')
        s.append(f'<text x="{x + 1 + w / 2}" y="58" text-anchor="middle" font-size="10" fill="{MUTED}">{esc(sub)}</text>')
        if i < len(steps) - 1:
            s.append(f'<line x1="{x + w + 1}" y1="45" x2="{x + w + gap}" y2="45" stroke="{MUTED}" stroke-width="1.2" marker-end="url(#arr)"/>')
    s.append("</svg>")
    return "".join(s)


def state_svg():
    s = [f'<svg viewBox="0 0 1000 300" xmlns="http://www.w3.org/2000/svg" font-family="Noto Sans, DejaVu Sans, sans-serif">', arrow_defs()]
    nodes = {
        "pending": (60, 60), "approved": (330, 60), "disbursed": (600, 60), "repaid": (850, 60),
        "rejected": (60, 210), "cancelled": (330, 210),
    }
    W, H = 130, 50
    for n, (x, y) in nodes.items():
        terminal = n in ("rejected", "cancelled", "repaid")
        s.append(f'<rect x="{x}" y="{y}" width="{W}" height="{H}" rx="25" fill="{TINT if n == "disbursed" else "#fff"}" stroke="{ACCENT if not terminal else LINE}" stroke-width="{1.6 if not terminal else 1.2}"/>')
        s.append(f'<text x="{x + W / 2}" y="{y + H / 2 + 1}" text-anchor="middle" dominant-baseline="middle" font-size="13" font-weight="600" fill="{INK}">{n}</text>')

    def edge(x1, y1, x2, y2, label, lx, ly, anchor="middle"):
        s.append(f'<line x1="{x1}" y1="{y1}" x2="{x2}" y2="{y2}" stroke="{MUTED}" stroke-width="1.3" marker-end="url(#arr)"/>')
        s.append(f'<text x="{lx}" y="{ly}" text-anchor="{anchor}" font-size="10.5" fill="{MUTED}">{label}</text>')

    s.append(f'<line x1="10" y1="85" x2="56" y2="85" stroke="{MUTED}" stroke-width="1.3" marker-end="url(#arr)"/>')
    s.append(f'<text x="10" y="44" font-size="10.5" fill="{MUTED}">apply +</text><text x="10" y="58" font-size="10.5" fill="{MUTED}">credit score</text>')
    edge(190, 85, 326, 85, "officer approves", 258, 76)
    edge(460, 85, 596, 85, "finance disburses", 528, 76)
    edge(730, 85, 846, 85, "balance = 0", 788, 76)
    edge(125, 110, 125, 206, "officer rejects", 135, 162, "start")
    edge(170, 110, 340, 206, "borrower cancels", 268, 150, "start")
    edge(395, 110, 395, 206, "cancel before payout", 405, 162, "start")
    s.append(f'<text x="530" y="140" font-size="10.5" fill="{MUTED}">On disburse: schedule generated, ledger posted,</text>')
    s.append(f'<text x="530" y="155" font-size="10.5" fill="{MUTED}">approver ≠ disburser enforced.</text>')
    s.append(f'<text x="530" y="185" font-size="10.5" fill="{MUTED}">Daily job: installments past due + grace → overdue,</text>')
    s.append(f'<text x="530" y="200" font-size="10.5" fill="{MUTED}">late fee charged once (status stays disbursed).</text>')
    s.append(f'<text x="530" y="235" font-size="10.5" fill="{INK}">Any other transition → 409 INVALID_LOAN_STATE</text>')
    s.append(f'<text x="530" y="250" font-size="10.5" fill="{MUTED}">(single source of truth: LoanStatus::canTransitionTo)</text>')
    s.append("</svg>")
    return "".join(s)


ERD_BOXES = {
    # name: (x, y, [(col, marker)])
    "institutions": (385, 10, [("id", "PK"), ("slug", "UQ"), ("status", ""), ("currency", ""), ("platform_fee_bps / bearer", "")]),
    "institution_brandings": (750, 10, [("id", "PK"), ("institution_id", "FK·UQ"), ("display_name", ""), ("primary_color", ""), ("custom_domain", "UQ")]),
    "institution_kyc_settings": (750, 140, [("id", "PK"), ("institution_id", "FK·UQ"), ("kyc_required · check flags", "")]),
    "users": (20, 230, [("id", "PK"), ("institution_id", "FK"), ("email", ""), ("role", ""), ("is_active", "")]),
    "loan_products": (750, 230, [("id", "PK"), ("institution_id", "FK"), ("code", ""), ("min/max_amount", ""), ("interest_rate_bps", ""), ("interest_method", ""), ("repayment_frequency", ""), ("eligible_business_types", "")]),
    "customers": (20, 470, [("id", "PK"), ("institution_id", "FK"), ("user_id", "FK·UQ"), ("business_name", ""), ("business_type", ""), ("registration_number", ""), ("licence_expires_at", ""), ("monthly_revenue/expenses", ""), ("kyc_status", "")]),
    "customer_directors": (20, 700, [("id", "PK"), ("customer_id", "FK"), ("role", ""), ("name, date_of_birth", ""), ("bvn / nin (encrypted)", ""), ("bvn_hash / nin_hash", "UQ"), ("…_reference, …_verified_at", "")]),
    "loans": (385, 300, [("id", "PK"), ("institution_id", "FK"), ("customer_id", "FK"), ("loan_product_id", "FK"), ("reference", "UQ"), ("status", ""), ("purpose", ""), ("principal_amount", ""), ("terms snapshot (rate, method…)", ""), ("fees_snapshot", ""), ("platform_fee_bps/bearer/amount", ""), ("total_repayable", ""), ("amount_paid", ""), ("outstanding_balance", ""), ("reviewed_by", "FK"), ("disbursed_by", "FK")]),
    "loan_product_fees": (750, 500, [("id", "PK"), ("loan_product_id", "FK"), ("calculation", ""), ("value", ""), ("charge_type", "")]),
    "credit_assessments": (20, 900, [("id", "PK"), ("loan_id", "FK·UQ"), ("score", ""), ("risk_grade", ""), ("recommendation", ""), ("debt_service_coverage_bps", ""), ("factors", "")]),
    "loan_installments": (385, 900, [("id", "PK"), ("loan_id", "FK"), ("sequence", ""), ("due_date", ""), ("…_due / …_paid", ""), ("status", "")]),
    "loan_transactions": (750, 900, [("id", "PK"), ("loan_id", "FK"), ("type", ""), ("amount", ""), ("reference", "UQ"), ("external_reference", ""), ("balance_before/after", ""), ("allocation", "")]),
    "audit_logs": (20, 1170, [("id", "PK"), ("institution_id", "FK"), ("user_id", "FK"), ("action", ""), ("auditable_type/id", ""), ("old/new_values", ""), ("request_id", "")]),
    "platform_fees": (385, 1170, [("id", "PK"), ("institution_id", "FK"), ("loan_id", "FK·UQ"), ("bearer", ""), ("rate_bps", ""), ("amount", "")]),
    "idempotency_keys": (750, 1170, [("id", "PK"), ("user_id", "FK"), ("idempotency_key", ""), ("request_hash", ""), ("response_body", ""), ("expires_at", "")]),
}
EW, EHDR, EROW = 230, 26, 17


def ebox(name):
    x, y, cols = ERD_BOXES[name]
    return x, y, EW, EHDR + EROW * len(cols) + 6


def erd_svg():
    s = [f'<svg viewBox="0 0 1000 1410" xmlns="http://www.w3.org/2000/svg" font-family="Noto Sans, DejaVu Sans, sans-serif">', arrow_defs()]
    # relationships first (under boxes)
    def side(name, which, offset=0.5):
        x, y, w, h = ebox(name)
        return {
            "top": (x + w * offset, y), "bottom": (x + w * offset, y + h),
            "left": (x, y + h * offset), "right": (x + w, y + h * offset),
        }[which]

    def rel(a, aside, b, bside, label_a="1", label_b="N", dashed=False, ao=0.5, bo=0.5, via=None, mid=None, points=None):
        (x1, y1), (x2, y2) = side(a, aside, ao), side(b, bside, bo)
        if points:
            d = f"M {x1} {y1} " + " ".join(f"L {px} {py}" for px, py in points) + f" L {x2} {y2}"
        elif via == "hv":
            d = f"M {x1} {y1} L {x2} {y1} L {x2} {y2}"
        elif via == "vh":
            d = f"M {x1} {y1} L {x1} {y2} L {x2} {y2}"
        elif via == "hvh":
            mx = (x1 + x2) / 2
            d = f"M {x1} {y1} L {mx} {y1} L {mx} {y2} L {x2} {y2}"
        elif via == "margin":
            d = f"M {x1} {y1} L 8 {y1} L 8 {y2} L {x2} {y2}"
        elif via == "vhv":
            my = mid if mid is not None else (y1 + y2) / 2
            d = f"M {x1} {y1} L {x1} {my} L {x2} {my} L {x2} {y2}"
        else:
            d = f"M {x1} {y1} L {x2} {y2}"
        s.append(f'<path d="{d}" fill="none" stroke="{ACCENT}" stroke-width="1.3" {"stroke-dasharray=\'5 4\'" if dashed else ""}/>')
        s.append(f'<circle cx="{x2}" cy="{y2}" r="3.2" fill="{ACCENT}"/>')
        def lab(x, y, t, dx, dy):
            s.append(f'<text x="{x + dx}" y="{y + dy}" font-size="11" font-weight="700" fill="{ACCENT}">{t}</text>')
        lab(x1, y1, label_a, 5 if aside in ("top", "bottom") else (6 if aside == "right" else -14), -5 if aside == "top" else (14 if aside == "bottom" else -5))
        lab(x2, y2, label_b, 5 if bside in ("top", "bottom") else (6 if bside == "right" else -14), -6 if bside == "top" else (16 if bside == "bottom" else -5))

    rel("institutions", "right", "institution_brandings", "left", "1", "1")
    rel("institutions", "bottom", "institution_kyc_settings", "left", "1", "1", ao=0.9, via="vh")
    rel("institutions", "left", "users", "top", "1", "N", via="hv", ao=0.35)
    rel("institutions", "right", "loan_products", "left", "1", "N", via="hvh", ao=0.8, bo=0.15)
    rel("institutions", "bottom", "loans", "top", "1", "N")
    rel("users", "bottom", "customers", "top", "1", "1")
    rel("customers", "bottom", "customer_directors", "top", "1", "N")
    rel("customers", "right", "loans", "left", "1", "N", ao=0.3, bo=0.6, via="hvh")
    rel("loan_products", "left", "loans", "right", "1", "N", ao=0.5, bo=0.35, via="hvh")
    rel("loan_products", "bottom", "loan_product_fees", "top", "1", "N")
    rel("users", "right", "loans", "left", "1", "N", dashed=True, ao=0.6, bo=0.85, via="hvh")
    rel("loans", "bottom", "loan_installments", "top", "1", "N")
    rel("loans", "bottom", "credit_assessments", "top", "1", "1", ao=0.2, via="vhv", mid=875)
    rel("loans", "bottom", "loan_transactions", "top", "1", "N", ao=0.8, via="vhv")
    lx, ly = side("loans", "right", 0.97)
    rel("loans", "right", "platform_fees", "top", "1", "1", ao=0.97, points=[(705, ly), (705, 1135), (500, 1135)])
    rel("users", "left", "audit_logs", "left", "1", "N", ao=0.8, bo=0.3, dashed=True, via="margin")

    for name, (x, y, cols) in ERD_BOXES.items():
        _, _, w, h = ebox(name)
        s.append(f'<rect x="{x}" y="{y}" width="{w}" height="{h}" rx="7" fill="#fff" stroke="{LINE}" stroke-width="1.2"/>')
        s.append(f'<path d="M {x} {y + 7} a 7 7 0 0 1 7 -7 h {w - 14} a 7 7 0 0 1 7 7 v {EHDR - 7} h {-w} z" fill="{ACCENT}"/>')
        s.append(f'<text x="{x + 10}" y="{y + 17.5}" font-size="12.5" font-weight="700" fill="#fff" font-family="DejaVu Sans Mono, monospace">{name}</text>')
        for i, (c, mark) in enumerate(cols):
            cy = y + EHDR + 13 + i * EROW
            s.append(f'<text x="{x + 10}" y="{cy}" font-size="11" fill="{INK}" font-family="DejaVu Sans Mono, monospace">{esc(c)}</text>')
            if mark:
                s.append(f'<text x="{x + w - 8}" y="{cy}" text-anchor="end" font-size="9.5" font-weight="700" fill="{ACCENT if "PK" in mark else MUTED}">{mark}</text>')
    s.append(f'<text x="20" y="1400" font-size="11" fill="{MUTED}">Solid: owning relationship · Dashed: actor references (reviewed_by / disbursed_by / user_id on audit). Every tenant table also carries institution_id → institutions (only key links drawn).</text>')
    s.append("</svg>")
    return "".join(s)


# ---------------------------------------------------------------- page
CSS = """
@page { size: A4; margin: 18mm 16mm 18mm 16mm;
  @bottom-left { content: "10MG Health · Lending Core: Architecture & Database Design"; font: 8.5pt 'Noto Sans', sans-serif; color: #94A3B8; }
  @bottom-right { content: counter(page) " / " counter(pages); font: 8.5pt 'Noto Sans', sans-serif; color: #94A3B8; } }
@page cover { margin: 0; @bottom-left { content: none } @bottom-right { content: none } }
:root { --accent: #0F766E; --ink: #0F172A; --muted: #64748B; --line: #E2E8F0; --soft: #F8FAFC; --tint: #ECFDF5; }
* { box-sizing: border-box; }
html { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
body { font-family: 'Noto Sans', 'DejaVu Sans', sans-serif; color: var(--ink); font-size: 9.6pt; line-height: 1.5; margin: 0; background: #fff; }
.mono, code { font-family: 'DejaVu Sans Mono', monospace; font-size: 0.86em; }
code { background: var(--soft); border: 1px solid var(--line); border-radius: 3px; padding: 0 3px; }
h1 { font-size: 19pt; margin: 0 0 4mm; letter-spacing: -0.01em; }
h2 { font-size: 14.5pt; margin: 0 0 3mm; padding-bottom: 2mm; border-bottom: 2px solid var(--accent); break-after: avoid; }
h2 .num { color: var(--accent); margin-right: 6px; }
h3 { font-size: 11.2pt; margin: 6mm 0 2mm; break-after: avoid; }
h4 { font-size: 9pt; margin: 2mm 0 1mm; color: var(--muted); text-transform: uppercase; letter-spacing: 0.06em; }
p { margin: 0 0 2.6mm; }
.chapter { break-before: page; }
.lead { font-size: 10.6pt; color: #334155; }
.muted { color: var(--muted); }
table { width: 100%; border-collapse: collapse; margin: 2mm 0 4mm; font-size: 8.8pt; break-inside: auto; }
th { text-align: left; background: var(--soft); color: #334155; font-weight: 600; border-bottom: 1.5px solid #CBD5E1; padding: 4px 6px; }
td { border-bottom: 1px solid var(--line); padding: 4px 6px; vertical-align: top; }
tr { break-inside: avoid; }
td.c, th.c { text-align: center; }
.figure { border: 1px solid var(--line); border-radius: 8px; padding: 4mm; margin: 3mm 0 2mm; break-inside: avoid; }
.figure svg { width: 100%; height: auto; display: block; }
.caption { font-size: 8.5pt; color: var(--muted); margin: 1.5mm 0 4mm; }
.cards { display: grid; grid-template-columns: repeat(3, 1fr); gap: 3mm; margin: 3mm 0 4mm; }
.card { border: 1px solid var(--line); border-radius: 8px; padding: 3mm; break-inside: avoid; }
.card b { display: block; color: var(--accent); margin-bottom: 1mm; }
.note { background: var(--tint); border-left: 3px solid var(--accent); padding: 2.5mm 3.5mm; border-radius: 0 6px 6px 0; margin: 3mm 0; break-inside: avoid; }
ul { margin: 0 0 3mm; padding-left: 5mm; } li { margin: 0 0 1mm; }
.yes { color: var(--accent); font-weight: 700; }
.tag { display: inline-block; font-size: 7.2pt; font-weight: 700; border-radius: 3px; padding: 0 4px; margin-right: 2px; line-height: 1.5; }
.tag.pk { background: var(--accent); color: #fff; } .tag.fk { background: #E0F2FE; color: #075985; } .tag.uk { background: #FEF3C7; color: #92400E; }
.dd { break-inside: auto; margin-bottom: 5mm; }
.dd h3 { margin-top: 3mm; color: var(--accent); }
.dd .purpose { color: #334155; margin-bottom: 1.5mm; }
.dd table.cols td { font-size: 8.2pt; padding: 2.5px 5px; } .dd table.cols th { font-size: 8.2pt; padding: 3px 5px; }
.dd .type { color: #475569; }
.dd .meta { display: grid; grid-template-columns: 1fr 1fr; gap: 4mm; font-size: 8.4pt; break-inside: avoid; }
.dd .meta ul { padding-left: 4mm; }
.toc { columns: 2; column-gap: 10mm; font-size: 10pt; } .toc div { break-inside: avoid; margin-bottom: 1.6mm; } .toc .n { color: var(--accent); font-weight: 700; display: inline-block; width: 7mm; }
.toc .sub { font-size: 8.8pt; color: var(--muted); padding-left: 7mm; }
/* cover */
.cover { page: cover; height: 297mm; padding: 26mm 20mm; display: flex; flex-direction: column; background: #0E78D9; color: #fff; }
.cover .logo svg { height: 13mm; width: auto; display: block; margin-bottom: 14mm; }
.cover .kicker { letter-spacing: .18em; text-transform: uppercase; font-size: 9pt; opacity: .85; }
.cover h1 { font-size: 34pt; line-height: 1.12; margin: 8mm 0 6mm; }
.cover .subtitle { font-size: 13pt; opacity: .92; max-width: 150mm; }
.cover .facts { margin-top: auto; display: grid; grid-template-columns: repeat(4, 1fr); gap: 4mm; }
.cover .fact { border-top: 1px solid rgba(255,255,255,.35); padding-top: 3mm; }
.cover .fact b { display: block; font-size: 17pt; }
.cover .fact span { font-size: 8.6pt; opacity: .8; }
.cover .foot { margin-top: 10mm; font-size: 9pt; opacity: .8; display: flex; justify-content: space-between; }
"""


def h2(num, title, anchor):
    return f'<h2 id="{anchor}"><span class="num">{num}</span>{title}</h2>'


def build():
    n_domain = len(TABLES)
    body = f"""
<div class="cover">
  <div class="logo">{LOGO_WHITE}</div>
  <div class="kicker">10MG Health · Technical Assessment</div>
  <h1>Multi-Tenant Lending Core<br/>Architecture &amp; Database Design</h1>
  <div class="subtitle">How one shared, white-label backend lets many lenders offer fast, data-driven credit to Africa's pharmacies, clinics and hospitals, with strict tenant isolation and auditable money movement.</div>
  <div class="facts">
    <div class="fact"><b>{n_domain}</b><span>domain tables (MySQL 8.4)</span></div>
    <div class="fact"><b>48</b><span>documented API endpoints (39 URLs)</span></div>
    <div class="fact"><b>260</b><span>automated tests · 1,151 assertions</span></div>
    <div class="fact"><b>151/151</b><span>adversarial probe checks, incl. real concurrency</span></div>
  </div>
  <div class="foot"><span>Laravel 13 · PHP 8.3 · Sanctum · MySQL · Redis</span><span>Version 1.3 · November 2026</span></div>
</div>

<section>
  <h1>Contents</h1>
  <div class="toc">
    <div><span class="n">1</span>Context &amp; goals</div>
    <div><span class="n">2</span>System architecture<div class="sub">Components · request pipeline · deployment</div></div>
    <div><span class="n">3</span>Multi-tenancy design</div>
    <div><span class="n">4</span>Authentication &amp; access control</div>
    <div><span class="n">5</span>Lending workflow<div class="sub">State machine · credit scoring · pricing maths · BVN / NIN KYC</div></div>
    <div><span class="n">6</span>Financial integrity<div class="sub">10MG platform fee · race conditions</div></div>
    <div><span class="n">7</span>API conventions &amp; error model</div>
    <div><span class="n">8</span>Database design<div class="sub">Principles · ERD · lifecycle of a loan in data</div></div>
    <div><span class="n">9</span>Data dictionary<div class="sub">Every table, column, index and foreign key</div></div>
    <div><span class="n">10</span>Verification</div>
    <div><span class="n">11</span>Trade-offs &amp; roadmap</div>
  </div>

  <div style="height:8mm"></div>
  {h2(1, "Context &amp; goals", "s1")}
  <p class="lead">10MG Health provides credit to healthcare providers whose cash needs move faster than traditional bank lending: a pharmacy restocking antimalarials before rainy season, a clinic replacing a broken ultrasound scanner, or a hospital bridging payroll so emergency care keeps running.</p>
  <p>This backend is a simplified <b>10MG V2 lending core</b>. It's a single deployment that many financial institutions (banks, microfinance banks, credit unions) use as <b>tenants</b>. Each tenant white-labels the platform under its own brand, sets its own loan products and pricing, runs its own staff, and lends to its own borrowers. No tenant can see another's data.</p>
  <table>
    <thead><tr><th style="width:28%">Requirement</th><th>How the design meets it</th></tr></thead>
    <tbody>
      <tr><td><b>Multi-tenant architecture</b></td><td>Shared schema with row-level isolation that fails closed. Institution onboarding and approval. Sanctum token auth. Permission-based RBAC with enforced maker/checker.</td></tr>
      <tr><td><b>White-label configuration</b></td><td>Per-tenant branding and custom domain. Products with limits, interest method and rate, frequency, grace and late fees, minimum score and eligible provider types. Upfront or capitalised fees.</td></tr>
      <tr><td><b>Lending workflow</b></td><td>Owner identity check (BVN via NIBSS, NIN via NIMC) → apply → instant business-profile scoring (mock) → approve/reject → disburse (mock rail) → waterfall repayments → automatic closure. Daily overdue and penalty job.</td></tr>
      <tr><td><b>Financial integrity</b></td><td>10MG platform fee per lender (lender- or borrower-borne), snapshotted per loan and recorded in an append-only revenue ledger. Integer minor units and bcmath. Transactions and row locks. HTTP and domain idempotency. Append-only ledger and audit trail. Encrypted, masked BVN/NIN. Layered validation and a stable error envelope.</td></tr>
      <tr><td><b>Code quality</b></td><td>Controller → Service → Repository layering, enforced by architecture tests. Contracts at integration boundaries. 260 automated tests. OpenAPI 3.1 docs. This document.</td></tr>
    </tbody>
  </table>
  <div class="cards">
    <div class="card"><b>Borrowers are businesses</b>Pharmacies, patent medicine stores, clinics, hospitals, laboratories and diagnostic centres. Each is scored on its trading profile, not on personal salary.</div>
    <div class="card"><b>Lenders are tenants</b>Every row of tenant data belongs to exactly one institution. Isolation is enforced centrally, never left to individual queries.</div>
    <div class="card"><b>Money is never a float</b>Kobo integers, basis-point rates, exact rounding. Every naira can be traced through the ledger and audit trail.</div>
  </div>
</section>

<section class="chapter">
  {h2(2, "System architecture", "s2")}
  <p>The system is a <b>modular monolith</b> on Laravel 13 with a strict <b>Controller → Service → Repository</b> layering. HTTP concerns (authentication, tenancy, authorisation, idempotency) are handled once in the middleware pipeline. Controllers hold no logic. <b>Services</b> own every business rule and transaction, and reach the database only through <b>repository interfaces</b>, whose Eloquent implementations own all queries and row locks. External dependencies (credit scoring, the payment rail, and the NIBSS and NIMC identity registers) sit behind contracts. The scorer and rail are mocks; the identity clients make real HTTP calls in <code>live</code> mode and answer locally in <code>sandbox</code> mode. Any of them can be swapped for a production provider without touching business code. An architecture test fails the build if a controller or service breaks these rules.</p>
  <div class="figure">{architecture_svg()}</div>
  <div class="caption">Figure 1: Component view and target AWS deployment.</div>

  <h3>Request pipeline</h3>
  <p>Every API request passes through the same ordered pipeline. Ordering is enforced with Laravel's middleware priority list. The tenant is resolved <i>before</i> route-model binding, so a foreign record never resolves and the request returns 404. The idempotency key is claimed only <i>after</i> authorisation passes.</p>
  <div class="figure">{pipeline_svg()}</div>
  <div class="caption">Figure 2: Middleware order (highlighted stages are custom: tenancy and idempotency).</div>

  <h3>Code organisation</h3>
  <table>
    <thead><tr><th style="width:26%">Layer</th><th style="width:34%">Location</th><th>Responsibility</th></tr></thead>
    <tbody>
      <tr><td>HTTP edge</td><td class="mono">Http/Middleware, Http/Requests</td><td>Request id, tenant resolution, idempotency; input validation (FormRequests).</td></tr>
      <tr><td>Controllers</td><td class="mono">Http/Controllers/Api/V1/*</td><td>No logic: FormRequest in → one service call → API Resource out.</td></tr>
      <tr><td>Services</td><td class="mono">Services/*Service, Services/Loans/*</td><td>Every business rule, transaction, invariant and audit write. Data access only through repository interfaces.</td></tr>
      <tr><td>Repositories</td><td class="mono">Repositories/Contracts, Repositories/Eloquent</td><td>All queries, filters, pagination and row locks; bound in <code>RepositoryServiceProvider</code>, mockable in tests.</td></tr>
      <tr><td>Domain helpers</td><td class="mono">Services/Lending, Services/AuditLogger</td><td>Pure pricing engine; audit writer.</td></tr>
      <tr><td>Integration contracts</td><td class="mono">Contracts/, Services/Credit, Services/Disbursement, Services/Identity</td><td><code>CreditBureau</code>, <code>DisbursementGateway</code>, <code>BvnRegistry</code> (NIBSS), <code>NinRegistry</code> (NIMC).</td></tr>
      <tr><td>Tenancy</td><td class="mono">Support/Tenancy</td><td><code>TenantContext</code>, <code>InstitutionScope</code>, <code>BelongsToInstitution</code>.</td></tr>
      <tr><td>Policy &amp; RBAC</td><td class="mono">Enums/Role, Enums/Permission, Policies</td><td>Role → permission matrix registered as Gates; <code>LoanPolicy</code> for borrower self-service.</td></tr>
      <tr><td>Errors</td><td class="mono">Exceptions/Api/*, ApiErrorRenderer</td><td>One class per machine-readable error code; single JSON envelope.</td></tr>
    </tbody>
  </table>
</section>

<section class="chapter">
  {h2(3, "Multi-tenancy design", "s3")}
  <p><b>Strategy: shared database, shared schema, row-level isolation on <code>institution_id</code>.</b> This suits a white-label platform with many small and medium lenders. It needs a single migration path, pools connections well, and costs little per tenant to run.</p>
  <table>
    <thead><tr><th>Option</th><th>Isolation</th><th>Operational cost</th><th class="c">Chosen</th></tr></thead>
    <tbody>
      <tr><td>Database per tenant</td><td>Strongest</td><td>High: N migrations, N connection pools</td><td class="c"></td></tr>
      <tr><td>Schema per tenant</td><td>Strong</td><td>Medium; not portable to MySQL</td><td class="c"></td></tr>
      <tr><td><b>Shared schema + institution_id</b></td><td>Enforced centrally in the app, backed by FKs and composite unique keys</td><td>Low; scales to many tenants</td><td class="c yes">✓</td></tr>
    </tbody>
  </table>
  <h3>Defense in depth</h3>
  <ol>
    <li><b>Tenant resolution.</b> For an authenticated user, the tenant always comes from <code>users.institution_id</code> on the token, so it can't be spoofed. Anonymous storefront calls use the <code>X-Tenant: &lt;slug&gt;</code> header. A header that conflicts with the token returns <code>403 TENANT_MISMATCH</code>. Platform operators have no tenant and can't call tenant routes (<code>400 TENANT_REQUIRED</code>).</li>
    <li><b>Global scope that fails closed.</b> Every tenant-owned model uses <code>BelongsToInstitution</code>, which adds <code>WHERE institution_id = :current</code> to every query, including route-model binding. With no tenant in context the scope matches <i>nothing</i>. Cross-tenant system jobs (the overdue command) must opt in explicitly with <code>TenantContext::runAs()</code>.</li>
    <li><b>Safe writes.</b> New tenant records are stamped with the current tenant automatically. Creating tenant data with no tenant in context throws.</li>
    <li><b>Existence is never revealed.</b> A cross-tenant ID returns <code>404</code>, not <code>403</code>, so one lender can't even confirm another's records exist.</li>
    <li><b>Per-tenant uniqueness.</b> Business keys are unique <i>within</i> a tenant: user email, borrower CAC number and phone, product code. The same pharmacy can bank with two lenders.</li>
    <li><b>Tenant lifecycle.</b> Pending or suspended lenders are refused (<code>403 TENANT_INACTIVE</code>). Suspending a lender revokes every token its users hold.</li>
  </ol>
  <div class="note"><b>Why not rely on developers adding WHERE clauses?</b> One forgotten filter is a data breach. The scope is applied by the framework to every query, and its default when the tenant is unknown is deny. Tests check cross-tenant reads, writes, header spoofing and the fail-closed default.</div>

  {h2(4, "Authentication &amp; access control", "s4")}
  <p>Authentication uses <b>Laravel Sanctum</b> bearer tokens: hashed at rest, revocable, and expiring after 30 days. Roles map to fine-grained permissions in one enum. Each permission is registered as a Gate, and routes declare <code>->can('loans.review')</code>. Controllers never check roles directly.</p>
  <table>
    <thead><tr><th>Permission</th><th class="c">platform<br/>admin</th><th class="c">institution<br/>admin</th><th class="c">loan<br/>officer</th><th class="c">finance<br/>officer</th><th class="c">customer<br/>(borrower)</th></tr></thead>
    <tbody>
      <tr><td>institutions.manage (approve / suspend lenders)</td><td class="c yes">✓</td><td></td><td></td><td></td><td></td></tr>
      <tr><td>audit.view</td><td class="c yes">✓</td><td class="c yes">✓</td><td></td><td></td><td></td></tr>
      <tr><td>branding.manage · kyc-settings.manage · products.manage · staff.manage</td><td></td><td class="c yes">✓</td><td></td><td></td><td></td></tr>
      <tr><td>platform-fees.view (10MG fees owed by the lender)</td><td></td><td class="c yes">✓</td><td></td><td class="c yes">✓</td><td></td></tr>
      <tr><td>loans.view (all loans in tenant, borrower list)</td><td></td><td class="c yes">✓</td><td class="c yes">✓</td><td class="c yes">✓</td><td></td></tr>
      <tr><td>loans.review (approve / reject)</td><td></td><td class="c yes">✓</td><td class="c yes">✓</td><td></td><td></td></tr>
      <tr><td>loans.disburse</td><td></td><td class="c yes">✓</td><td></td><td class="c yes">✓</td><td></td></tr>
      <tr><td>repayments.record (any channel, incl. cash)</td><td></td><td class="c yes">✓</td><td></td><td class="c yes">✓</td><td></td></tr>
      <tr><td>loans.apply · loans.view-own · repayments.make-own</td><td></td><td></td><td></td><td></td><td class="c yes">✓</td></tr>
    </tbody>
  </table>
  <ul>
    <li><b>Maker/checker, enforced per loan.</b> Approval and disbursement are separate permissions. The user who approved a loan can't disburse it (<code>403 SEGREGATION_OF_DUTIES</code>), even an institution admin who holds both. It's configurable for single-operator lenders.</li>
    <li><b>Borrower self-service.</b> <code>LoanPolicy</code> limits borrowers to their own loans. Borrowers can't self-report <code>cash</code> repayments. Their legal identity (CAC number, business type) can't be changed through self-service. Borrowers verify their owner's BVN and NIN themselves (<code>/me/kyc</code>); staff see the KYC status but never the numbers.</li>
    <li><b>Account safety.</b> Deactivating a user removes their permissions and revokes their tokens. Admins can't deactivate themselves or change their own role. Login and onboarding are rate-limited.</li>
  </ul>
</section>

<section class="chapter">
  {h2(5, "Lending workflow", "s5")}
  <div class="figure">{state_svg()}</div>
  <div class="caption">Figure 3: Loan state machine.</div>
  <ol>
    <li><b>Apply.</b> The borrower picks a product, amount, tenor and purpose (inventory restock, medical equipment, working capital, emergency financing, facility expansion). The request is checked against the product's limits and its eligible provider types. The product's terms (rate, method, frequency, fees, overdue rules) are <b>snapshotted onto the loan</b>, so later repricing never changes an existing loan.</li>
    <li><b>Instant credit assessment.</b> The business profile is scored synchronously and the explainable result is stored (below).</li>
    <li><b>Decision.</b> An officer approves or rejects. Approving a <code>decline</code> recommendation requires a written <code>override_reason</code>, which is kept on the loan and in the audit trail.</li>
    <li><b>Disburse.</b> A different authorised user releases the funds. Inside one transaction, the system calls the payment rail with a deterministic idempotency reference, generates the schedule from the payout date, and posts disbursement and fee ledger entries. A rejected transfer returns <code>502</code> and rolls everything back.</li>
    <li><b>Repay.</b> Payments are allocated oldest installment first, and within an installment <b>penalty → fees → interest → principal</b>. Partial payments are supported and overpayment is refused. The loan closes automatically at zero.</li>
    <li><b>Overdue.</b> A daily job marks installments past <code>due date + grace</code> as overdue and charges the snapshotted late fee once per installment, with a ledger entry.</li>
  </ol>

  <h3>Credit assessment: healthcare business scorecard</h3>
  <p><code>MockHealthcareCreditScorer</code> stands in for 10MG's AI business-profile model behind the <code>CreditBureau</code> contract. It's deterministic and fully explainable: each factor's impact is stored, so a loan officer can see exactly why a recommendation was made.</p>
  <table>
    <thead><tr><th style="width:24%">Factor</th><th style="width:44%">Signal</th><th>Score impact</th></tr></thead>
    <tbody>
      <tr><td>Operating licence</td><td>Licence from PCN, MDCN, MLSCN or State MoH, valid today</td><td>+40 valid · −200 expired (<b>forces decline</b>)</td></tr>
      <tr><td>Debt-service coverage</td><td>Net monthly cash flow ÷ new monthly repayment (weekly normalised ×52/12)</td><td>≥2.0x +80 · ≥1.5x +30 · ≥1.2x −40 · &lt;1.2x −150 (<b>forces decline</b>)</td></tr>
      <tr><td>Years in operation</td><td>Current year − year established</td><td>&lt;1 −60 · 1–2 −10 · 3–5 +30 · &gt;5 +60</td></tr>
      <tr><td>Provider type</td><td>Business type</td><td>Hospital +30 · pharmacy/clinic +20 · lab/diagnostic +10 · PPMV 0</td></tr>
      <tr><td>Revenue scale</td><td>Monthly revenue</td><td>≥₦5m +30 · ≥₦1m +10 · otherwise −20</td></tr>
      <tr><td>Existing exposure</td><td>Open loans with this lender</td><td>−40 each (max −120)</td></tr>
    </tbody>
  </table>
  <p>Base score is 600, clamped to 300–850, graded A (≥750) to E. <b>Decline</b> if the licence is expired, coverage is below 1.20x, or the score is below the product minimum (default 560). <b>Approve</b> if the score is ≥680 and coverage is ≥1.50x. Otherwise <b>review</b>. The scorer also returns the largest principal that keeps coverage at 1.50x.</p>

  <h3>Pricing maths</h3>
  <ul>
    <li><b>Flat:</b> interest = P × annual rate × (n ÷ periods per year), spread evenly.</li>
    <li><b>Reducing balance:</b> amortised EMI = P·r ÷ (1 − (1+r)<sup>−n</sup>), with r = annual rate ÷ periods per year (52 / 26 / 12). Each period's interest is charged on the outstanding principal.</li>
    <li>All arithmetic uses <b>bcmath</b> at 20-digit precision and is rounded half-up to kobo only when stored. Rounding residue goes into the <b>last installment</b>, so the schedule always sums exactly to the quoted totals. The engine was cross-checked against an independent Decimal implementation to the kobo.</li>
    <li><b>Fees:</b> <i>upfront</i> fees are deducted from the payout (net disbursement = principal − upfront fees). <i>Capitalised</i> fees are added to the repayable amount and spread across installments.</li>
  </ul>

  <h3>Borrower KYC: BVN and NIN</h3>
  <p>The borrower is a business, but a BVN or NIN belongs to a <b>person</b>, so both are captured for the business's owner in <code>customer_directors</code>. Before the business can apply, the owner submits name, date of birth, phone, BVN and NIN to <code>POST /me/kyc</code> (with an <code>Idempotency-Key</code>, because each attempt is a billable lookup). Until it passes, <code>POST /loans</code> returns <code>422 KYC_REQUIRED</code>.</p>
  <table>
    <thead><tr><th style="width:20%">Layer</th><th style="width:30%">Class</th><th>Responsibility</th></tr></thead>
    <tbody>
      <tr><td>Transport</td><td class="mono">IdentityRegistryClient → NibssService, NimcService</td><td>OAuth2 client-credentials token (cached 50 min), JSON request with timeout and retry on connection errors, provider envelope <code>{{responseCode, reference, data.fieldMatches}}</code>. Unreachable provider → <code>503 IDENTITY_PROVIDER_UNAVAILABLE</code>.</td></tr>
      <tr><td>Matching rules</td><td class="mono">BvnService, NinService</td><td>Number not found → fail. First name, last name and date of birth must match. A phone mismatch is recorded but tolerated, because BVN phone numbers are often out of date.</td></tr>
      <tr><td>Orchestration</td><td class="mono">KycService</td><td>Rejects a BVN/NIN already linked to another business of the same lender, calls both registers <b>outside</b> the transaction (no lock held on the network), then locks the borrower, re-checks, saves the owner and status, and audits, all in one transaction.</td></tr>
    </tbody>
  </table>
  <div class="note"><b>Sandbox and live modes.</b> With <code>NIBSS_MODE</code> / <code>NIMC_MODE</code> set to <code>sandbox</code> (the default), each service builds exactly the request it would send and answers it locally with a full match, so the whole flow runs without credentials. <code>live</code> sends it; the tests drive live mode through <code>Http::fake()</code> to pin the endpoints, headers and payloads. <b>Protecting the numbers (NDPA 2023):</b> encrypted at rest, an HMAC fingerprint for uniqueness, last four digits for display, masked (<code>*******8901</code>) in every response and audit entry, never serialised.</div>
  <h4>KYC settings: each lender's choice</h4>
  <p>Each lender sets its own policy through <code>GET</code>/<code>PATCH /kyc-settings</code> (institution admin, permission <code>kyc-settings.manage</code>, audited). Until it does, the platform defaults apply.</p>
  <table>
    <thead><tr><th style="width:26%">Switch (default)</th><th>Effect</th></tr></thead>
    <tbody>
      <tr><td class="mono">kyc_required (on)</td><td>Applications are refused with <code>422 KYC_REQUIRED</code> and the <code>outstanding_checks</code> until every enabled check has passed. Off: verification is optional.</td></tr>
      <tr><td class="mono">bvn_check_enabled (on)</td><td>Match the BVN with NIBSS. Off: no BVN is asked for and NIBSS is never called.</td></tr>
      <tr><td class="mono">nin_check_enabled (on)</td><td>Match the NIN with NIMC. Off: likewise for the NIN and NIMC.</td></tr>
      <tr><td class="mono">phone_match_required (off)</td><td>A phone mismatch fails the check instead of only being recorded.</td></tr>
    </tbody>
  </table>
  <p>Requiring KYC with both checks off is refused (<code>422</code>). With both off, <code>POST /me/kyc</code> answers <code>409 KYC_NOT_ENABLED</code> and the status is <code>not_required</code>. <code>customers.kyc_checks</code> records the checks a business has passed, and status is judged against the lender's <b>current</b> settings. A borrower verified under a BVN-only policy has <code>nin</code> outstanding once the lender switches it on, and resubmits; a check switched off keeps its earlier result. The storefront (<code>GET /tenant</code>) publishes the active checks so the white-label app asks only for what the lender needs, and platform operators see each lender's policy read-only in <code>GET /platform/institutions/{{id}}</code> (<code>kyc_settings</code>, alongside its <code>platform_fee</code> terms).</p>
  <p class="muted">Next uses of the verified BVN: credit-bureau lookups (CRC, FirstCentral) in the scorer, NIP name enquiry on the payout account before disbursement, and Global Standing Instruction / direct-debit mandates for collections.</p>
</section>

<section class="chapter">
  {h2(6, "Financial integrity", "s6")}
  <table>
    <thead><tr><th style="width:24%">Concern</th><th>Mechanism</th></tr></thead>
    <tbody>
      <tr><td><b>Money representation</b></td><td>Unsigned <code>BIGINT</code> minor units (kobo). Rates and percentage fees in basis points. No floating point anywhere in the money path. Every API amount is capped at ₦10 trillion so 64-bit arithmetic can never overflow.</td></tr>
      <tr><td><b>Atomicity</b></td><td>Each money-moving service method runs in one <code>DB::transaction()</code>. A failure rolls back the loan, schedule, ledger and audit rows together.</td></tr>
      <tr><td><b>Concurrency</b></td><td><code>SELECT … FOR UPDATE</code> on the loan row before any state or balance change. This prevents double disbursement and races between concurrent repayments. Applications lock the borrower row, so the open-loan limit can't be raced.</td></tr>
      <tr><td><b>HTTP idempotency</b></td><td><code>Idempotency-Key</code> is required on apply, approve, reject, cancel, disburse and repay. The key is scoped per user and fingerprints method + path + canonicalised body (SHA-256). The first response is stored, and a retry replays it with <code>Idempotent-Replayed: true</code>. The same key with a different payload returns <code>422</code>. A concurrent duplicate returns <code>409</code> (atomic Redis/cache lock). <code>5xx</code> responses aren't stored, so the client can safely retry.</td></tr>
      <tr><td><b>Domain idempotency</b></td><td><code>loan_transactions.external_reference</code> is unique per institution. A payment provider's reference can only ever be applied once (<code>409 DUPLICATE_PAYMENT_REFERENCE</code>), even with a fresh idempotency key.</td></tr>
      <tr><td><b>Immutable ledger</b></td><td><code>loan_transactions</code> is append-only: no <code>updated_at</code>, and the model refuses updates and deletes. Each entry stores <code>balance_before</code> and <code>balance_after</code> plus its allocation, so the loan balance can be rebuilt from the ledger alone.</td></tr>
      <tr><td><b>Audit trail</b></td><td><code>audit_logs</code> is append-only. Every privileged action records the actor, tenant, IP, user agent, <code>X-Request-Id</code> and old/new values. It's written inside the same transaction as the change, so a failed action leaves no audit entry, and a failed disbursement is audited separately after the rollback.</td></tr>
      <tr><td><b>Invariants</b></td><td>Checked by tests and by direct SQL against MySQL: <code>outstanding_balance</code> = Σ installment outstanding = last ledger <code>balance_after</code> = <code>total_repayable − amount_paid</code>.</td></tr>
    </tbody>
  </table>

  <h3>10MG platform fee</h3>
  <p>10MG earns a fee on every disbursed loan, separate from the lender's own product fees. 10MG never funds lenders: they lend their own money, and the platform fee is 10MG's revenue.</p>
  <table>
    <thead><tr><th style="width:22%">Aspect</th><th>Design</th></tr></thead>
    <tbody>
      <tr><td><b>Who sets it</b></td><td>A platform operator, per lender: <code>PATCH /platform/institutions/{{id}}/platform-fee</code> with <code>rate_bps</code> (basis points of the principal, 0–1000; 0 waives it) and <code>bearer</code>. Lenders never set use the platform default (<code>PLATFORM_FEE_BPS=50</code>, <code>PLATFORM_FEE_BEARER=lender</code>). Audited as <code>institution.platform_fee_updated</code>.</td></tr>
      <tr><td><b>Lender pays</b></td><td>A commission the lender owes 10MG. The borrower's quote, payout and repayments are unchanged, and borrowers never see it (staff see it on each loan as <code>platform_fee</code>).</td></tr>
      <tr><td><b>Borrower pays</b></td><td>Priced as an upfront fee named <i>10MG platform fee</i>: listed in the quote, deducted from the payout (1% on ₦200,000 → ₦198,000 paid out) and posted to the loan ledger with the lender's other upfront fees.</td></tr>
      <tr><td><b>Snapshot</b></td><td><code>platform_fee_bps</code>, <code>platform_fee_bearer</code> and <code>platform_fee_amount</code> are copied onto the loan at application, so changing a lender's terms never reprices an existing loan.</td></tr>
      <tr><td><b>Revenue ledger</b></td><td>At disbursement, in the same transaction, one append-only <code>platform_fees</code> row is written (unique per loan, so a loan is never charged twice). Operators report across lenders with <code>GET /platform/platform-fees</code> (filters <code>institution_id</code>, <code>from</code>, <code>to</code>; totals per bearer). Each lender's admin and finance officers see its own terms and fees with <code>GET /platform-fees</code>.</td></tr>
    </tbody>
  </table>

  <h3>Race conditions</h3>
  <p>Each race was reproduced with real parallel requests against MySQL 8.4 and a 12-worker server, and each has a regression test.</p>
  <table>
    <thead><tr><th style="width:27%">Race</th><th style="width:38%">Guard</th><th>Observed under contention</th></tr></thead>
    <tbody>
      <tr><td>Concurrent repayments overdrawing a loan</td><td>Loan row lock, then the overpayment check</td><td>12 sent, only 4 fit: 4 accepted, 8 × 422; balances exact</td></tr>
      <tr><td>One payment retried in parallel</td><td>Atomic lock per (user, Idempotency-Key) + unique index</td><td>10 retries: one payment applied; the rest replayed or told to retry (409)</td></tr>
      <tr><td>Two officers approve / two finance officers disburse</td><td>Loan row lock + state machine</td><td>1 success, 9 × 409; paid out once, one schedule</td></tr>
      <tr><td>Parallel applications beyond the open-loan limit</td><td>Borrower row lock around count-then-insert</td><td>8 sent, limit 3: exactly 3 accepted</td></tr>
      <tr><td>Two overdue jobs plus repayments</td><td>Scheduler overlap guard, per-loan lock, overdue installments skipped</td><td>Each installment penalised once</td></tr>
      <tr><td>One borrower submitting KYC twice at once</td><td>Borrower row lock, then a re-check of <code>kyc_status</code>; unique (customer_id, role)</td><td>8 sent: 1 × 200, 7 × 409 KYC_ALREADY_VERIFIED</td></tr>
      <tr><td>Two businesses submitting the same BVN</td><td>Unique (institution_id, bvn_hash); the violation is answered as 422 on <code>bvn</code></td><td>Loser rolls back and stays pending</td></tr>
      <tr><td>Duplicate sign-ups, slugs, product codes, staff emails, domains</td><td>Unique indexes are authoritative; the losing insert is answered with the same 422 a sequential duplicate gets (no 500, no SQL leak)</td><td>8 sent: 1 × 201, 7 × 422, exactly one row</td></tr>
    </tbody>
  </table>
  <p class="muted">Lock order is the same everywhere (loan → installments; borrower → new loan), so these paths can't deadlock. Accepted windows: product edits are last-write-wins (audited, and loans are protected by snapshots); a request that crashes mid-flight leaves its key "processing" (409 until expiry, never a double execution).</p>

  {h2(7, "API conventions &amp; error model", "s7")}
  <table>
    <tbody>
      <tr><td style="width:24%"><b>Base path</b></td><td><code>/api/v1</code>. Versioned, with JSON resources wrapped in <code>data</code> and paginated lists.</td></tr>
      <tr><td><b>Tenant</b></td><td>From the token for tenant users. <code>X-Tenant: &lt;slug&gt;</code> for the public storefront, borrower sign-up and login.</td></tr>
      <tr><td><b>Money / rates</b></td><td>Integers in minor units; rates in basis points (<code>2400</code> = 24.00% p.a.).</td></tr>
      <tr><td><b>Tracing</b></td><td><code>X-Request-Id</code> is accepted or generated, echoed on every response, and stored on audit rows.</td></tr>
      <tr><td><b>Documentation</b></td><td>OpenAPI 3.1, generated from the code (Scramble): interactive at <code>/docs/api</code>, exported to <code>docs/openapi.json</code>. Also a Postman collection.</td></tr>
    </tbody>
  </table>
  <p>Every failure uses one envelope: <code>{{"message": "…", "error": {{"code": "MACHINE_CODE", "details": {{…}}}}}}</code>.</p>
  <table>
    <thead><tr><th style="width:12%">Status</th><th style="width:38%">Code</th><th>Meaning</th></tr></thead>
    <tbody>
      <tr><td>400</td><td class="mono">TENANT_REQUIRED · IDEMPOTENCY_KEY_REQUIRED</td><td>Missing tenant context / missing idempotency key</td></tr>
      <tr><td>401</td><td class="mono">UNAUTHENTICATED · INVALID_CREDENTIALS</td><td>No/invalid token; wrong login</td></tr>
      <tr><td>403</td><td class="mono">FORBIDDEN · TENANT_MISMATCH · TENANT_INACTIVE · SEGREGATION_OF_DUTIES · ACCOUNT_DISABLED</td><td>Not permitted</td></tr>
      <tr><td>404</td><td class="mono">NOT_FOUND · TENANT_NOT_FOUND</td><td>Missing or belongs to another tenant</td></tr>
      <tr><td>409</td><td class="mono">INVALID_LOAN_STATE · DUPLICATE_PAYMENT_REFERENCE · IDEMPOTENCY_REQUEST_IN_PROGRESS · INVALID_INSTITUTION_STATE · KYC_ALREADY_VERIFIED · KYC_NOT_ENABLED</td><td>Conflicts with current state</td></tr>
      <tr><td>422</td><td class="mono">VALIDATION_FAILED · LOAN_TERMS_VIOLATION · OVERPAYMENT · CREDIT_OVERRIDE_REQUIRED · OPEN_LOAN_LIMIT_REACHED · IDEMPOTENCY_KEY_REUSED · KYC_REQUIRED · KYC_VERIFICATION_FAILED</td><td>Request understood but not acceptable</td></tr>
      <tr><td>502</td><td class="mono">DISBURSEMENT_FAILED</td><td>Payment rail rejected the transfer (rolled back)</td></tr>
      <tr><td>503</td><td class="mono">IDENTITY_PROVIDER_UNAVAILABLE</td><td>NIBSS or NIMC unreachable; nothing stored, safe to retry</td></tr>
    </tbody>
  </table>
</section>

<section class="chapter">
  {h2(8, "Database design", "s8")}
  <h3>Design principles</h3>
  <div class="cards">
    <div class="card"><b>ULID primary keys</b>Sortable, generated without a round-trip, and impossible to enumerate. Raw IDs never reveal record counts or another lender's activity.</div>
    <div class="card"><b>Tenant key everywhere</b>Every tenant-owned table has an indexed, FK-constrained <code>institution_id</code>, and business keys are unique per tenant.</div>
    <div class="card"><b>Exact money</b>Unsigned BIGINT minor units; basis-point rates. Balances are maintained under row locks and can be rebuilt from the ledger.</div>
    <div class="card"><b>Snapshots over references</b>Loans copy the product terms and fees they were priced with. Products can be repriced or archived (soft delete) safely.</div>
    <div class="card"><b>Append-only history</b>Ledger and audit tables are never updated or deleted, and FKs from them use RESTRICT or SET NULL so history survives.</div>
    <div class="card"><b>Indexes follow queries</b>Composite indexes back the hot paths, e.g. <code>(institution_id, status, created_at)</code> for loan queues and <code>(status, due_date)</code> for the overdue job.</div>
  </div>
  <div class="figure" style="padding:3mm">{erd_svg()}</div>
  <div class="caption">Figure 4: Entity-relationship diagram (key columns shown; full definitions in §9).</div>

  <h3>A loan's life in the data</h3>
  <table>
    <thead><tr><th style="width:18%">Step</th><th>Rows written (one transaction each)</th></tr></thead>
    <tbody>
      <tr><td>KYC</td><td><code>customer_directors</code> (owner, encrypted BVN/NIN, references) + <code>customers</code> (kyc_status) + <code>audit_logs</code> (customer.kyc_verified / kyc_failed, masked numbers only)</td></tr>
      <tr><td>Apply</td><td><code>loans</code> (status pending, terms snapshot) + <code>credit_assessments</code> + <code>audit_logs</code> (loan.applied) + <code>idempotency_keys</code></td></tr>
      <tr><td>Approve / reject</td><td><code>loans</code> (status, reviewed_by, decision_reason) + <code>audit_logs</code></td></tr>
      <tr><td>Disburse</td><td><code>loans</code> (balances, dates, rail reference) + N × <code>loan_installments</code> + <code>platform_fees</code> (10MG fee) + <code>loan_transactions</code> (disbursement, upfront fee) + <code>audit_logs</code></td></tr>
      <tr><td>Repay</td><td><code>loan_installments</code> (paid components, status) + <code>loans</code> (amount_paid, outstanding, maybe repaid) + <code>loan_transactions</code> (repayment + allocation) + <code>audit_logs</code></td></tr>
      <tr><td>Overdue job</td><td><code>loan_installments</code> (overdue, penalty_due) + <code>loans</code> (penalty_fees, totals) + <code>loan_transactions</code> (penalty) + <code>audit_logs</code></td></tr>
    </tbody>
  </table>
  <p class="muted">Framework tables (not detailed): <code>migrations</code>, <code>cache</code>, <code>cache_locks</code> (idempotency locks when Redis is absent), <code>jobs</code>, <code>job_batches</code>, <code>failed_jobs</code>, <code>sessions</code>, <code>password_reset_tokens</code>.</p>
</section>

<section class="chapter">
  {h2(9, "Data dictionary", "s9")}
  <p class="muted">Generated from the migrated MySQL 8.4 database (<code>information_schema</code>), so types, nullability, indexes and foreign-key actions are exactly what's deployed. <span class="tag pk">PK</span> primary key · <span class="tag fk">FK</span> foreign key · <span class="tag uk">UQ</span> single-column unique.</p>
  {data_dictionary()}
</section>

<section class="chapter">
  {h2(10, "Verification", "s10")}
  <table>
    <thead><tr><th style="width:34%">Check</th><th>Result</th></tr></thead>
    <tbody>
      <tr><td>Migrations on MySQL 8.4 (<code>10mg</code>)</td><td>All 10 migrations ran; demo data seeded (3 lenders, staff, a pharmacy and a clinic, 3 healthcare products, one loan through the full lifecycle)</td></tr>
      <tr><td>Automated suite: SQLite in-memory</td><td><b>260 tests · 1,151 assertions · all passing</b> (~5 s)</td></tr>
      <tr><td>Automated suite: MySQL 8.4</td><td><b>260 / 260 passing</b> (separate <code>10mg_testing</code> database)</td></tr>
      <tr><td>Live HTTP flow checks</td><td><b>56 / 56 passing</b>: onboarding, auth, branding, products, staff, borrower onboarding, eligibility, decline override, reject/cancel, four-eyes, idempotent retry, overpayment, payoff, overdue penalties, isolation, suspension, audit</td></tr>
      <tr><td>Postman collection (Runner, 85 requests in 11 folders)</td><td><b>85 / 85 passing</b> for a seeded lender with a lender-borne fee (lagos-mfb) and one with a borrower-borne fee (abuja-credit); every request asserts its status and key fields</td></tr>
      <tr><td>Adversarial probe (<code>scripts/probe.sh</code>, 12-worker server)</td><td><b>151 / 151 passing</b>: cross-tenant IDs, suspended lenders, disabled staff, segregation of duties, idempotency, duplicate references, overpayment, closed/cancelled loans, malformed input, deactivated products, open-loan limit, BVN/NIN KYC, lender KYC settings, and real parallel races</td></tr>
      <tr><td>Data invariants (direct SQL)</td><td>For every disbursed/repaid loan: balance = schedule outstanding = last ledger balance = total repayable − paid</td></tr>
    </tbody>
  </table>
  <table>
    <thead><tr><th style="width:34%">Test suite</th><th>Focus</th></tr></thead>
    <tbody>
      <tr><td class="mono">Unit/…/RepaymentScheduleCalculatorTest</td><td>Flat vs reducing, EMI, exact rounding sums, fees, due dates</td></tr>
      <tr><td class="mono">Unit/…/MockHealthcareCreditScorerTest</td><td>Licence, coverage bands, trading history, weekly normalisation, affordability</td></tr>
      <tr><td class="mono">Unit/Enums/*</td><td>State machine; complete role → permission matrix</td></tr>
      <tr><td class="mono">Feature/TenantIsolationTest</td><td>Cross-tenant 404s, header spoofing, fail-closed scope, per-tenant uniqueness</td></tr>
      <tr><td class="mono">Feature/RoleBasedAccessTest</td><td>Maker/checker, staff vs borrower, self-demotion, deactivation</td></tr>
      <tr><td class="mono">Feature/Loan*Test</td><td>Lifecycle, eligibility, repayments, disbursement failure, four-eyes</td></tr>
      <tr><td class="mono">Feature/IdempotencyTest · AuditTrailTest · MarkOverdueInstallmentsTest</td><td>Replay, mismatch, concurrency, 5xx retry; audit immutability; penalties</td></tr>
      <tr><td class="mono">Unit/ArchitectureTest</td><td>Layering rules for every controller and service; each repository contract implemented</td></tr>
      <tr><td class="mono">Feature/Services/StaffServiceTest</td><td>Service rules tested against a mocked repository (no database)</td></tr>
      <tr><td class="mono">Feature/PlatformFeeTest</td><td>Lender- vs borrower-borne pricing, quote disclosure, terms locked at application, waived fee, operator-only and audited, revenue per lender and across lenders, borrowers never see the commission, append-only</td></tr>
      <tr><td class="mono">Feature/KycSettingsTest</td><td>Defaults, BVN-only never calls NIMC, re-enabling a check, KYC off, phone matching, invalid combination, per-lender isolation, admin-only, audit, storefront</td></tr>
      <tr><td class="mono">Feature/KycVerificationTest · IdentityRegistryClientTest</td><td>KYC gate, masking and encryption, NIBSS/NIMC mismatch and retry, provider outage, one BVN per business, exact live request shapes, token caching</td></tr>
      <tr><td class="mono">Feature/BusinessProfileTest · CustomerLoanHistoryTest</td><td>Borrower self-service, licence renewal, immutable identity, staff loan history</td></tr>
    </tbody>
  </table>

  {h2(11, "Trade-offs &amp; roadmap", "s11")}
  <table>
    <thead><tr><th style="width:30%">Decision</th><th>Rationale / next step</th></tr></thead>
    <tbody>
      <tr><td>Shared-schema tenancy</td><td>Cheapest to run for many tenants. All access goes through one tenancy layer, so a very large lender could later move to a dedicated database without business-code changes. Postgres row-level security could add a second, database-enforced layer.</td></tr>
      <tr><td>Synchronous disbursement in the transaction</td><td>Fine for a mock rail. Production should use a <i>disbursing</i> state, a transactional outbox and a provider webhook. The deterministic gateway reference already makes rail-side retries safe.</td></tr>
      <tr><td>Mock scorer, self-declared financials</td><td>The contract matches the real AI model. Next: ingest bank statements, POS settlements and wholesaler purchase history, verify licences against regulator registers, and re-score on each application.</td></tr>
      <tr><td>Scheduled interest (no daily accrual)</td><td>Simple and exact for installment products. Next: daily accrual, early-settlement rebates and restructuring as product settings.</td></tr>
      <tr><td>KYC for the owner only</td><td>Covers sole proprietors, most pharmacies. Each lender already chooses which checks run. Next: every director of a limited company, CAC director lookup, and re-verification when an owner changes.</td></tr>
      <tr><td>One owner login per business</td><td>Enough for the assessment. Next: multiple staff per borrowing business (e.g. a pharmacy's accountant) via a membership table.</td></tr>
      <tr><td>Platform fee without settlement</td><td>Fees accrue per loan and are reportable per lender. Next: monthly invoices to lenders and marking fees settled, with an optional platform-wide cap on lender product fees.</td></tr>
      <tr><td>Scorer ignores outside debt</td><td>Existing exposure counts only loans with this lender. Next: a credit-bureau lookup (CRC, FirstCentral) keyed on the verified BVN, feeding outside debts and recent defaults into the score.</td></tr>
      <tr><td>Platform roadmap</td><td>Event webhooks for lenders, per-tenant rate limits and notification templates, Terraform for the AWS layout in Figure 1.</td></tr>
    </tbody>
  </table>
</section>
"""
    OUT.write_text(f"<!doctype html><html lang='en'><head><meta charset='utf-8'><title>10MG Lending Core: Architecture &amp; Database Design</title><style>{CSS}</style></head><body>{body}</body></html>")


build()
print("wrote", OUT)
