#!/usr/bin/env bash
#
# End-to-end walkthrough of the lending API against a running server.
#
#   php artisan migrate:fresh --seed && php artisan serve
#   ./scripts/demo.sh                  # BASE_URL defaults to http://127.0.0.1:8000/api/v1
#
# Requires curl and jq.
set -euo pipefail

BASE="${BASE_URL:-http://127.0.0.1:8000/api/v1}"
SLUG="ikeja-mfb-$RANDOM"
PASSWORD="Password123"

bold() { printf '\n\033[1;36m▶ %s\033[0m\n' "$*"; }
note() { printf '  \033[2m%s\033[0m\n' "$*"; }

# api METHOD PATH [JSON] [extra curl args...]  → prints "STATUS<TAB>BODY"
api() {
  local method=$1 path=$2 body=${3:-} ; shift 3 || shift $#
  local args=(-s -o /tmp/demo_body.$$ -w '%{http_code}' -X "$method" "$BASE$path" -H 'Accept: application/json' -H 'Content-Type: application/json')
  [[ -n "${TOKEN:-}" ]] && args+=(-H "Authorization: Bearer $TOKEN")
  [[ -n "$body" ]] && args+=(-d "$body")
  local status; status=$(curl "${args[@]}" "$@")
  printf '%s\t%s' "$status" "$(cat /tmp/demo_body.$$)"
}
status() { cut -f1 <<<"$1"; }
body()   { cut -f2- <<<"$1"; }
show()   { printf '  HTTP %s  ' "$(status "$1")"; body "$1" | jq -c "${2:-.}"; }
key()    { cat /proc/sys/kernel/random/uuid; }

bold "1. A new institution registers itself (status: pending)"
R=$(api POST /institutions "{\"institution\":{\"name\":\"Ikeja Microfinance Bank\",\"slug\":\"$SLUG\",\"email\":\"hello@$SLUG.test\"},\"admin\":{\"name\":\"Chief Executive\",\"email\":\"ceo@$SLUG.test\",\"password\":\"$PASSWORD\"}}")
show "$R" '{slug: .data.institution.slug, status: .data.institution.status, admin: .data.admin.email}'
INSTITUTION_ID=$(body "$R" | jq -r .data.institution.id)

bold "2. Its admin cannot log in until the platform approves it"
show "$(api POST /auth/login "{\"email\":\"ceo@$SLUG.test\",\"password\":\"$PASSWORD\"}" -H "X-Tenant: $SLUG")" '.error'

bold "3. Platform operator approves the institution"
TOKEN=$(body "$(api POST /auth/login "{\"email\":\"operator@10mg.test\",\"password\":\"$PASSWORD\"}")" | jq -r .data.token)
show "$(api POST "/platform/institutions/$INSTITUTION_ID/activate" '{"reason":"KYC documents verified"}')" '{status: .data.status, approved_at: .data.approved_at}'

bold "4. Institution admin logs in and white-labels the platform"
TOKEN=$(body "$(api POST /auth/login "{\"email\":\"ceo@$SLUG.test\",\"password\":\"$PASSWORD\"}" -H "X-Tenant: $SLUG")" | jq -r .data.token)
ADMIN_TOKEN=$TOKEN
show "$(api PATCH /branding '{"display_name":"Ikeja Cash","primary_color":"#E11D48","secondary_color":"#0EA5E9","logo_url":"https://cdn.example.test/ikeja.svg"}')" '.data | {display_name, primary_color}'

bold "5. …configures a loan product with interest, fees and repayment rules"
R=$(api POST /loan-products '{"name":"Quick Cash","code":"QUICK","min_amount":1000000,"max_amount":50000000,"min_tenor":1,"max_tenor":6,"interest_rate_bps":3000,"interest_method":"reducing_balance","repayment_frequency":"monthly","grace_period_days":3,"late_fee_type":"percentage","late_fee_value":500,"fees":[{"name":"Processing fee","calculation":"percentage","value":150,"charge_type":"upfront"},{"name":"Insurance","calculation":"fixed","value":100000,"charge_type":"capitalized"}]}')
show "$R" '.data | {id, code, interest_rate_bps, interest_method, fees: [.fees[].name]}'
PRODUCT_ID=$(body "$R" | jq -r .data.id)

bold "6. …and provisions staff with separated duties (maker / checker)"
show "$(api POST /staff "{\"name\":\"Tolu Officer\",\"email\":\"officer@$SLUG.test\",\"password\":\"$PASSWORD\",\"role\":\"loan_officer\"}")" '.data | {email, role, permissions}'
show "$(api POST /staff "{\"name\":\"Femi Finance\",\"email\":\"finance@$SLUG.test\",\"password\":\"$PASSWORD\",\"role\":\"finance_officer\"}")" '.data | {email, role, permissions}'

bold "7. Public storefront: branding + product catalogue + quote (X-Tenant header, no auth)"
TOKEN=""
show "$(api GET /tenant '' -H "X-Tenant: $SLUG")" '.data.branding | {display_name, primary_color}'
show "$(api POST "/tenant/loan-products/$PRODUCT_ID/quote" '{"amount":20000000,"tenor":4}' -H "X-Tenant: $SLUG")" '.data | {total_interest, upfront_fees, net_disbursement_amount, total_repayable, installment_amount}'

bold "8. A pharmacy onboards as a borrower of this lender"
R=$(api POST /tenant/customers "{\"contact_name\":\"Chioma Eze\",\"email\":\"chioma@ezepharmacy.test\",\"password\":\"$PASSWORD\",\"business_name\":\"Eze Community Pharmacy\",\"business_type\":\"pharmacy\",\"registration_number\":\"RC$RANDOM$RANDOM\",\"licence_number\":\"PCN/PR/77812\",\"licence_expires_at\":\"2027-12-31\",\"year_established\":2018,\"phone\":\"+234801$RANDOM$RANDOM\",\"city\":\"Ikeja\",\"state\":\"Lagos\",\"staff_count\":6,\"monthly_revenue\":250000000,\"monthly_expenses\":180000000}" -H "X-Tenant: $SLUG")
show "$R" '.data.customer | {business_name, business_type, licence, net_monthly_cash_flow}'
CUSTOMER_TOKEN=$(body "$R" | jq -r .data.token)

bold "8b. Owner's BVN (NIBSS) and NIN (NIMC) are verified before the pharmacy may borrow"
TOKEN=$CUSTOMER_TOKEN
show "$(api POST /me/kyc "{\"first_name\":\"Chioma\",\"last_name\":\"Eze\",\"date_of_birth\":\"1986-02-14\",\"phone\":\"+2348035550101\",\"bvn\":\"2$(printf '%05d%05d' $RANDOM $RANDOM)\",\"nin\":\"7$(printf '%05d%05d' $RANDOM $RANDOM)\"}" -H "Idempotency-Key: $(key)")" '.data | {status, owner: .owner | {name, bvn: .bvn.masked, nin: .nin.masked}}'

bold "9. Pharmacy applies for ₦200,000 of inventory finance over 4 months (business profile scored instantly)"
TOKEN=$CUSTOMER_TOKEN
APPLY_KEY=$(key)
R=$(api POST /loans "{\"loan_product_id\":\"$PRODUCT_ID\",\"amount\":20000000,\"tenor\":4,\"purpose\":\"inventory_restock\",\"purpose_note\":\"Antimalarials and ORS\"}" -H "Idempotency-Key: $APPLY_KEY")
show "$R" '.data | {reference, status, total_repayable, credit: .credit_assessment | {score, risk_grade, recommendation, debt_service_coverage_bps}}'
LOAN_ID=$(body "$R" | jq -r .data.id)

bold "10. Network retry with the same Idempotency-Key replays the response; no duplicate loan"
curl -s -D - -o /dev/null -X POST "$BASE/loans" -H 'Accept: application/json' -H 'Content-Type: application/json' \
  -H "Authorization: Bearer $TOKEN" -H "Idempotency-Key: $APPLY_KEY" \
  -d "{\"loan_product_id\":\"$PRODUCT_ID\",\"amount\":20000000,\"tenor\":4,\"purpose\":\"inventory_restock\",\"purpose_note\":\"Antimalarials and ORS\"}" | grep -i -E '^(HTTP|idempotent-replayed)' | sed 's/^/  /'
show "$(api POST /loans "{\"loan_product_id\":\"$PRODUCT_ID\",\"amount\":99000000,\"tenor\":4,\"purpose\":\"inventory_restock\"}" -H "Idempotency-Key: $APPLY_KEY")" '.error.code'
note "same key + different payload is rejected"

bold "11. Tenant isolation: another institution's admin cannot see this loan"
TOKEN=$(body "$(api POST /auth/login "{\"email\":\"admin@lagos-mfb.test\",\"password\":\"$PASSWORD\"}" -H 'X-Tenant: lagos-mfb')" | jq -r .data.token)
show "$(api GET "/loans/$LOAN_ID")" '.error'
show "$(api GET /loans '' -H "X-Tenant: $SLUG")" '.error'

bold "12. RBAC: finance officer cannot approve; loan officer can"
FINANCE_TOKEN=$(body "$(api POST /auth/login "{\"email\":\"finance@$SLUG.test\",\"password\":\"$PASSWORD\"}" -H "X-Tenant: $SLUG")" | jq -r .data.token)
OFFICER_TOKEN=$(body "$(api POST /auth/login "{\"email\":\"officer@$SLUG.test\",\"password\":\"$PASSWORD\"}" -H "X-Tenant: $SLUG")" | jq -r .data.token)
TOKEN=$FINANCE_TOKEN; show "$(api POST "/loans/$LOAN_ID/approve" '{}' -H "Idempotency-Key: $(key)")" '.error.code'
TOKEN=$OFFICER_TOKEN; show "$(api POST "/loans/$LOAN_ID/approve" '{"note":"Employment confirmed"}' -H "Idempotency-Key: $(key)")" '.data | {status, reviewed_at}'
note "approving twice is an illegal state transition:"
show "$(api POST "/loans/$LOAN_ID/approve" '{}' -H "Idempotency-Key: $(key)")" '.error'

bold "13. Finance disburses: mock payout, schedule generated, ledger posted"
TOKEN=$FINANCE_TOKEN
R=$(api POST "/loans/$LOAN_ID/disburse" '' -H "Idempotency-Key: $(key)")
show "$R" '.data | {status, net_disbursement_amount, outstanding_balance, disbursement_reference, maturity_date}'
show "$(api GET "/loans/$LOAN_ID/schedule")" '[.data[] | {sequence, due_date, principal_due, interest_due, fee_due, total_due}]'
FIRST_DUE=$(body "$(api GET "/loans/$LOAN_ID/schedule")" | jq -r '.data[0].total_due')

bold "14. Pharmacy repays the first installment"
TOKEN=$CUSTOMER_TOKEN
show "$(api POST "/loans/$LOAN_ID/repayments" "{\"amount\":$FIRST_DUE,\"channel\":\"card\",\"external_reference\":\"PSK-$SLUG-1\"}" -H "Idempotency-Key: $(key)")" '.data | {allocation: .transaction.allocation, outstanding: .loan.outstanding_balance}'
note "the same provider reference cannot be applied twice:"
show "$(api POST "/loans/$LOAN_ID/repayments" "{\"amount\":$FIRST_DUE,\"channel\":\"card\",\"external_reference\":\"PSK-$SLUG-1\"}" -H "Idempotency-Key: $(key)")" '.error.code'
note "overpayment is rejected:"
show "$(api POST "/loans/$LOAN_ID/repayments" '{"amount":999999999,"channel":"card"}' -H "Idempotency-Key: $(key)")" '.error'

bold "15. Immutable ledger and audit trail"
TOKEN=$ADMIN_TOKEN
show "$(api GET "/loans/$LOAN_ID/transactions")" '[.data[] | {type, amount, balance_before, balance_after}]'
show "$(api GET "/audit-logs?auditable_id=$LOAN_ID")" '[.data[] | {action, actor: .actor.role, request_id}]'

rm -f /tmp/demo_body.$$
printf '\n\033[1;32m✔ Demo complete.\033[0m API docs: %s\n' "${BASE%/api/v1}/docs/api"
