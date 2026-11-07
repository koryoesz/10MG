#!/usr/bin/env bash
#
# Adversarial probe of the lending API: tenant isolation, access control, idempotency,
# money-movement integrity and real concurrency (parallel requests).
#
# Run against a seeded database and a MULTI-WORKER server, otherwise requests are
# serialised and the race-condition checks prove nothing:
#
#   php artisan migrate:fresh --seed
#   PHP_CLI_SERVER_WORKERS=12 php artisan serve --no-reload --port=8000
#   ./scripts/probe.sh                       # BASE_URL=http://host:port/api/v1 to override
#
# Requires curl and jq. Exits non-zero if any probe fails.
set -uo pipefail

BASE="${BASE_URL:-http://127.0.0.1:8000/api/v1}"
PW=Password123
RUN=$RANDOM$RANDOM
TMP=$(mktemp -d)
PASS=0; FAIL=0; SECTION=0
trap 'rm -rf "$TMP"' EXIT

# ------------------------------------------------------------------ helpers
uuid() { cat /proc/sys/kernel/random/uuid 2>/dev/null || uuidgen; }

# req METHOD PATH TOKEN TENANT BODY [IDEMPOTENCY_KEY|auto|none]  → sets CODE, BODY
req() {
  local args=(-s -o "$TMP/body" -w '%{http_code}' -X "$1" "$BASE$2" -H 'Accept: application/json' -H 'Content-Type: application/json')
  [[ -n "$3" ]] && args+=(-H "Authorization: Bearer $3")
  [[ -n "$4" ]] && args+=(-H "X-Tenant: $4")
  [[ -n "${5:-}" ]] && args+=(-d "$5")
  case "${6:-none}" in none) ;; auto) args+=(-H "Idempotency-Key: $(uuid)") ;; *) args+=(-H "Idempotency-Key: $6") ;; esac
  CODE=$(curl "${args[@]}"); BODY=$(cat "$TMP/body")
}

check() { # check "label" expected_code [jq expr] [expected value]
  local ok=1 got=""
  [[ "$CODE" == "$2" ]] || ok=0
  if [[ $ok == 1 && $# -ge 4 ]]; then got=$(jq -r "$3" <<<"$BODY" 2>/dev/null); [[ "$got" == "$4" ]] || ok=0; fi
  if [[ $ok == 1 ]]; then PASS=$((PASS+1)); printf '  \033[32m✔\033[0m %s\n' "$1"
  else FAIL=$((FAIL+1)); printf '  \033[31m✘ %s\033[0m  (HTTP %s %s)\n' "$1" "$CODE" "$(head -c 240 <<<"$BODY")"; fi
}
assert() { # assert "label" actual expected
  if [[ "$2" == "$3" ]]; then PASS=$((PASS+1)); printf '  \033[32m✔\033[0m %s\n' "$1"
  else FAIL=$((FAIL+1)); printf '  \033[31m✘ %s\033[0m  (got %s, expected %s)\n' "$1" "$2" "$3"; fi
}
section() { SECTION=$((SECTION+1)); printf '\n\033[1;36m%2d. %s\033[0m\n' "$SECTION" "$1"; }

# parallel N METHOD PATH TOKEN BODY KEY_MODE [TENANT] → fires N simultaneous requests; prints status counts
parallel() {
  local n=$1 i; rm -f "$TMP"/p.*
  for i in $(seq 1 "$n"); do
    local key; case "$6" in auto) key=$(uuid) ;; none) key="" ;; *) key="$6" ;; esac
    ( args=(-s -o "$TMP/p.body.$i" -w '%{http_code}' -X "$2" "$BASE$3" -H 'Accept: application/json' -H 'Content-Type: application/json' -H "Authorization: Bearer $4")
      [[ -n "$4" ]] || args=("${args[@]:0:${#args[@]}-2}")
      [[ -n "${7:-}" ]] && args+=(-H "X-Tenant: $7")
      [[ -n "$5" ]] && args+=(-d "$5"); [[ -n "$key" ]] && args+=(-H "Idempotency-Key: $key")
      curl "${args[@]}" > "$TMP/p.code.$i" ) &
  done
  wait
  summarise
}
summarise() { for f in "$TMP"/p.code.*; do cat "$f"; echo; done | sort | uniq -c | awk '{printf "%s×%s ", $1, $2}'; }
count_code() { grep -lx "$1" "$TMP"/p.code.* 2>/dev/null | wc -l | tr -d ' '; }

login() { req POST /auth/login "" "${2:-}" "{\"email\":\"$1\",\"password\":\"$PW\"}"; jq -r '.data.token // empty' <<<"$BODY"; }

digits11() { printf '%s%05d%05d' "$((RANDOM % 9 + 1))" "$RANDOM" "$RANDOM"; }
kyc_body() { echo "{\"first_name\":\"Ada\",\"last_name\":\"Probe\",\"date_of_birth\":\"1985-04-12\",\"phone\":\"+2348030000000\",\"bvn\":\"${1:-$(digits11)}\",\"nin\":\"${2:-$(digits11)}\"}"; }

# register_pharmacy TENANT NAME → prints token of a borrower whose owner passed BVN/NIN checks
register_pharmacy() {
  local token; token=$(register_unverified "$@")
  req POST /me/kyc "$token" "" "$(kyc_body)" auto
  echo "$token"
}

# register_unverified TENANT NAME → prints token of a borrower that has not done KYC
register_unverified() {
  req POST /tenant/customers "" "$1" "{\"contact_name\":\"$2\",\"email\":\"$2.$RUN@probe.test\",\"password\":\"$PW\",\"business_name\":\"$2 $RUN Pharmacy\",\"business_type\":\"pharmacy\",\"registration_number\":\"RC$((RANDOM % 9000 + 1000))$RANDOM\",\"licence_number\":\"PCN/PR/$RANDOM\",\"licence_expires_at\":\"2030-01-01\",\"year_established\":2016,\"phone\":\"+234$RANDOM$RANDOM$RANDOM\",\"city\":\"Ikeja\",\"state\":\"Lagos\",\"monthly_revenue\":900000000,\"monthly_expenses\":300000000}"
  jq -r '.data.token // empty' <<<"$BODY"
}
apply() { # apply TOKEN PRODUCT AMOUNT TENOR [KEY] → sets CODE/BODY
  req POST /loans "$1" "" "{\"loan_product_id\":\"$2\",\"amount\":$3,\"tenor\":$4,\"purpose\":\"inventory_restock\"}" "${5:-auto}"
}
approve() { req POST "/loans/$2/approve" "$1" "" '{"note":"probe"}' auto; }
disburse() { req POST "/loans/$2/disburse" "$1" "" '' auto; }
loan_field() { req GET "/loans/$2" "$1" ""; jq -r "$3" <<<"$BODY"; }

# ------------------------------------------------------------------ actors
printf '\033[1mProbing %s (run %s)\033[0m\n' "$BASE" "$RUN"
OPERATOR=$(login operator@10mg.test)
L_ADMIN=$(login admin@lagos-mfb.test lagos-mfb)
L_OFFICER=$(login officer@lagos-mfb.test lagos-mfb)
L_FINANCE=$(login finance@lagos-mfb.test lagos-mfb)
A_ADMIN=$(login admin@abuja-credit.test abuja-credit)
for t in OPERATOR L_ADMIN L_OFFICER L_FINANCE A_ADMIN; do [[ -n "${!t}" ]] || { echo "Login failed for $t: is the database seeded?" >&2; exit 2; }; done

req GET /tenant/loan-products "" lagos-mfb
RX=$(jq -r '.data[]|select(.code=="RX-STOCK").id' <<<"$BODY")
req POST /staff "$L_ADMIN" "" "{\"name\":\"Second Finance\",\"email\":\"finance2.$RUN@lagos-mfb.test\",\"password\":\"$PW\",\"role\":\"finance_officer\"}"
L_FINANCE2=$(login "finance2.$RUN@lagos-mfb.test" lagos-mfb)
req POST /staff "$L_ADMIN" "" "{\"name\":\"Second Officer\",\"email\":\"officer2.$RUN@lagos-mfb.test\",\"password\":\"$PW\",\"role\":\"loan_officer\"}"
L_OFFICER2=$(login "officer2.$RUN@lagos-mfb.test" lagos-mfb)

# A Lagos loan + borrower used as cross-tenant targets.
BORROWER_A=$(register_pharmacy lagos-mfb "victim")
apply "$BORROWER_A" "$RX" 20000000 4; LAGOS_LOAN=$(jq -r .data.id <<<"$BODY")
req GET /customers "$L_ADMIN" ""; LAGOS_CUSTOMER=$(jq -r ".data[]|select(.business_name==\"victim $RUN Pharmacy\").id" <<<"$BODY")
req GET "/loan-products/$RX" "$L_ADMIN" ""; LAGOS_FEE=$(jq -r '.data.fees[0].id' <<<"$BODY")
req GET /staff "$L_ADMIN" ""; LAGOS_STAFF=$(jq -r '.data[0].id' <<<"$BODY")

# ------------------------------------------------------------------ 1-3 cross-tenant
section "Cross-tenant object access (Abuja admin → Lagos objects)"
req GET "/loan-products/$RX" "$A_ADMIN" "";                      check "read Lagos product → 404" 404 .error.code NOT_FOUND
req PATCH "/loan-products/$RX" "$A_ADMIN" "" '{"interest_rate_bps":1}'; check "edit Lagos product → 404" 404
req DELETE "/loan-products/$RX/fees/$LAGOS_FEE" "$A_ADMIN" "";  check "delete Lagos product fee → 404" 404
req PATCH "/staff/$LAGOS_STAFF" "$A_ADMIN" "" '{"is_active":false}'; check "deactivate Lagos staff → 404" 404
req GET "/audit-logs?auditable_id=$LAGOS_LOAN" "$A_ADMIN" "";   check "Lagos audit entries invisible" 200 '.data|length' 0
req GET /loans "$A_ADMIN" lagos-mfb;                             check "X-Tenant spoofing → 403" 403 .error.code TENANT_MISMATCH
req GET "/loan-products/$RX" "$L_ADMIN" "";                      check "Lagos product unchanged (rate 2400)" 200 .data.interest_rate_bps 2400

section "Cross-tenant loan IDs"
req GET "/loans/$LAGOS_LOAN" "$A_ADMIN" "";                      check "GET loan → 404" 404
req GET "/loans/$LAGOS_LOAN/schedule" "$A_ADMIN" "";             check "GET schedule → 404" 404
req GET "/loans/$LAGOS_LOAN/transactions" "$A_ADMIN" "";         check "GET ledger → 404" 404
req POST "/loans/$LAGOS_LOAN/approve" "$A_ADMIN" "" '{}' auto;   check "approve → 404" 404
req POST "/loans/$LAGOS_LOAN/reject" "$A_ADMIN" "" '{"reason":"hostile"}' auto; check "reject → 404" 404
req POST "/loans/$LAGOS_LOAN/disburse" "$A_ADMIN" "" '' auto;    check "disburse → 404" 404
req POST "/loans/$LAGOS_LOAN/repayments" "$A_ADMIN" "" '{"amount":100,"channel":"cash"}' auto; check "repay → 404" 404
req GET /loans "$A_ADMIN" "";                                    assert "Lagos loan absent from Abuja list" "$(jq -r "[.data[].id]|index(\"$LAGOS_LOAN\")" <<<"$BODY")" null
assert "loan still pending after attacks" "$(loan_field "$L_ADMIN" "$LAGOS_LOAN" .data.status)" pending
OTHER_BORROWER=$(register_pharmacy lagos-mfb "nosy")
req GET "/loans/$LAGOS_LOAN" "$OTHER_BORROWER" "";               check "same-tenant borrower reads another's loan → 403" 403
req POST "/loans/$LAGOS_LOAN/cancel" "$OTHER_BORROWER" "" '{}' auto; check "same-tenant borrower cancels another's loan → 403" 403

section "Cross-tenant customer IDs"
req GET "/customers/$LAGOS_CUSTOMER" "$A_ADMIN" "";              check "GET borrower → 404" 404
req GET "/customers/$LAGOS_CUSTOMER/loans" "$A_ADMIN" "";        check "GET borrower loans → 404" 404
req GET "/customers?search=victim%20$RUN" "$A_ADMIN" "";                check "search cannot find Lagos borrower" 200 '.data|length' 0
req GET "/loans?customer_id=$LAGOS_CUSTOMER" "$A_ADMIN" "";      check "filter by foreign customer_id → empty" 200 '.data|length' 0
req GET "/loans?customer_id=$LAGOS_CUSTOMER" "$OTHER_BORROWER" ""; check "borrower cannot widen list via customer_id" 200 '.data|length' 0
A_BORROWER=$(register_pharmacy abuja-credit "abujan")
apply "$A_BORROWER" "$RX" 20000000 4;                            check "Abuja borrower applies with Lagos product → 422" 422 .error.code VALIDATION_FAILED

# ------------------------------------------------------------------ 4-5 disabled actors
section "Disabled / suspended lender login"
req GET /platform/institutions "$OPERATOR" ""; ABUJA_ID=$(jq -r '.data[]|select(.slug=="abuja-credit").id' <<<"$BODY")
req POST "/platform/institutions/$ABUJA_ID/suspend" "$OPERATOR" "" '{"reason":"probe"}'; check "operator suspends abuja-credit" 200 .data.status suspended
req POST /auth/login "" abuja-credit "{\"email\":\"admin@abuja-credit.test\",\"password\":\"$PW\"}"; check "suspended lender staff login → 403" 403 .error.code TENANT_INACTIVE
req GET /loans "$A_ADMIN" "";                                    check "suspended lender's existing token → 401" 401
req GET /tenant "" abuja-credit;                                 check "suspended lender storefront → 403" 403 .error.code TENANT_INACTIVE
req POST /tenant/customers "" abuja-credit '{}';                 check "suspended lender sign-up → 403" 403
req POST "/platform/institutions/$ABUJA_ID/activate" "$OPERATOR" "" '{}'; check "reactivate abuja-credit" 200 .data.status active
req POST /auth/login "" kano-capital '{"email":"x@kano.test","password":"x"}'; check "pending lender login → 403" 403 .error.code TENANT_INACTIVE

section "Disabled staff login"
req POST /staff "$L_ADMIN" "" "{\"name\":\"Leaver\",\"email\":\"leaver.$RUN@lagos-mfb.test\",\"password\":\"$PW\",\"role\":\"loan_officer\"}"; LEAVER_ID=$(jq -r .data.id <<<"$BODY")
LEAVER=$(login "leaver.$RUN@lagos-mfb.test" lagos-mfb)
req PATCH "/staff/$LEAVER_ID" "$L_ADMIN" "" '{"is_active":false}'; check "admin deactivates staff" 200 .data.is_active false
req GET /loans "$LEAVER" "";                                     check "deactivated staff token → 401" 401
req POST /auth/login "" lagos-mfb "{\"email\":\"leaver.$RUN@lagos-mfb.test\",\"password\":\"$PW\"}"; check "deactivated staff login → 403" 403 .error.code ACCOUNT_DISABLED
req POST /auth/login "" lagos-mfb "{\"email\":\"leaver.$RUN@lagos-mfb.test\",\"password\":\"wrong\"}"; check "wrong password does not reveal disabled state → 401" 401 .error.code INVALID_CREDENTIALS

# ------------------------------------------------------------------ 6-8 segregation of duties
section "Borrower attempting to approve a loan"
req POST "/loans/$LAGOS_LOAN/approve" "$BORROWER_A" "" '{}' auto;  check "borrower approves own loan → 403" 403 .error.code FORBIDDEN
req POST "/loans/$LAGOS_LOAN/disburse" "$BORROWER_A" "" '' auto;   check "borrower disburses own loan → 403" 403
req PATCH "/loan-products/$RX" "$BORROWER_A" "" '{"interest_rate_bps":0}'; check "borrower edits pricing → 403" 403

section "Approver attempting to disburse their own approval"
B=$(register_pharmacy lagos-mfb "fourEyes"); apply "$B" "$RX" 20000000 4; L=$(jq -r .data.id <<<"$BODY")
approve "$L_OFFICER" "$L";                                       check "loan officer approves" 200 .data.status approved
disburse "$L_OFFICER" "$L";                                      check "loan officer disburses (no permission) → 403" 403 .error.code FORBIDDEN
B=$(register_pharmacy lagos-mfb "adminSelf"); apply "$B" "$RX" 20000000 4; L=$(jq -r .data.id <<<"$BODY")
approve "$L_ADMIN" "$L";                                         check "institution admin approves" 200
disburse "$L_ADMIN" "$L";                                        check "same admin disburses own approval → 403" 403 .error.code SEGREGATION_OF_DUTIES
assert "loan still approved, no money moved" "$(loan_field "$L_ADMIN" "$L" .data.status)" approved
disburse "$L_FINANCE" "$L";                                      check "different finance officer disburses" 200 .data.status disbursed

section "Finance officer attempting to approve"
B=$(register_pharmacy lagos-mfb "finApprove"); apply "$B" "$RX" 20000000 4; L=$(jq -r .data.id <<<"$BODY")
approve "$L_FINANCE" "$L";                                       check "finance officer approves → 403" 403 .error.code FORBIDDEN
req POST "/loans/$L/reject" "$L_FINANCE" "" '{"reason":"not my job"}' auto; check "finance officer rejects → 403" 403
assert "loan still pending" "$(loan_field "$L_ADMIN" "$L" .data.status)" pending

# ------------------------------------------------------------------ 9-11 idempotency & references
section "Duplicate Idempotency-Key (sequential retry)"
B=$(register_pharmacy lagos-mfb "retry"); KEY=$(uuid)
apply "$B" "$RX" 20000000 4 "$KEY"; FIRST=$(jq -r .data.id <<<"$BODY"); check "first request creates loan" 201
apply "$B" "$RX" 20000000 4 "$KEY";                              check "retry replays the same loan" 201 .data.id "$FIRST"
req GET /loans "$B" "";                                          check "borrower has exactly one loan" 200 '.data|length' 1
req POST /loans "$B" "" "{\"loan_product_id\":\"$RX\",\"amount\":20000000,\"tenor\":4,\"purpose\":\"inventory_restock\"}" none; check "missing Idempotency-Key → 400" 400 .error.code IDEMPOTENCY_KEY_REQUIRED

section "Same Idempotency-Key with a different request body"
apply "$B" "$RX" 30000000 4 "$KEY";                              check "different amount, same key → 422" 422 .error.code IDEMPOTENCY_KEY_REUSED
req GET /loans "$B" "";                                          check "still exactly one loan" 200 '.data|length' 1
B2=$(register_pharmacy lagos-mfb "otherUser")
apply "$B2" "$RX" 20000000 4 "$KEY";                             check "same key from another user is independent → 201" 201
assert "…and creates a different loan" "$([[ $(jq -r .data.id <<<"$BODY") != "$FIRST" ]] && echo yes)" yes

section "Duplicate repayment reference"
B=$(register_pharmacy lagos-mfb "dupRef"); apply "$B" "$RX" 20000000 4; L=$(jq -r .data.id <<<"$BODY")
approve "$L_OFFICER" "$L"; disburse "$L_FINANCE" "$L"
REF="NIP-$RUN-001"
req POST "/loans/$L/repayments" "$L_FINANCE" "" "{\"amount\":100000,\"channel\":\"bank_transfer\",\"external_reference\":\"$REF\"}" auto; check "first payment with reference" 201
req POST "/loans/$L/repayments" "$L_FINANCE" "" "{\"amount\":100000,\"channel\":\"bank_transfer\",\"external_reference\":\"$REF\"}" auto; check "same reference, new idempotency key → 409" 409 .error.code DUPLICATE_PAYMENT_REFERENCE
req POST "/loans/$L/repayments" "$B" "" "{\"amount\":100000,\"channel\":\"card\",\"external_reference\":\"$REF\"}" auto; check "same reference from the borrower → 409" 409
assert "only one payment applied" "$(loan_field "$L_FINANCE" "$L" .data.amount_paid)" 100000

# ------------------------------------------------------------------ 12-13 concurrency
section "Concurrent repayment requests"
B=$(register_pharmacy lagos-mfb "race"); apply "$B" "$RX" 20000000 4; L=$(jq -r .data.id <<<"$BODY")
approve "$L_OFFICER" "$L"; disburse "$L_FINANCE" "$L"; OUT=$(jq -r .data.outstanding_balance <<<"$BODY")
SLICE=$((OUT / 4)); REM=$((OUT - SLICE * 4))
printf '  outstanding %s → 12 parallel payments of %s (only 4 fit)\n  responses: %s\n' "$OUT" "$SLICE" "$(parallel 12 POST "/loans/$L/repayments" "$L_FINANCE" "{\"amount\":$SLICE,\"channel\":\"bank_transfer\"}" auto)"
assert "exactly 4 payments accepted" "$(count_code 201)" 4
assert "the other 8 refused as overpayment" "$(count_code 422)" 8
assert "no server errors under contention" "$(count_code 500)" 0
req GET "/loans/$L" "$L_FINANCE" ""
assert "amount_paid = 4 slices exactly" "$(jq -r .data.amount_paid <<<"$BODY")" "$((SLICE * 4))"
assert "outstanding = remainder exactly" "$(jq -r .data.outstanding_balance <<<"$BODY")" "$REM"
req GET "/loans/$L/transactions" "$L_FINANCE" ""
assert "ledger holds exactly 4 repayments" "$(jq '[.data[]|select(.type=="repayment")]|length' <<<"$BODY")" 4
assert "ledger balance chain is unbroken" "$(jq '[.data[]|select(.type=="repayment")]|sort_by(.balance_before)|reverse|. as $t|[range(1;length)|select($t[.].balance_before != $t[.-1].balance_after)]|length' <<<"$BODY")" 0
req GET "/loans/$L/schedule" "$L_FINANCE" ""
assert "schedule outstanding matches loan" "$(jq '[.data[].outstanding]|add' <<<"$BODY")" "$REM"
KEY=$(uuid)
printf '  10 parallel retries of ONE payment (same Idempotency-Key)\n  responses: %s\n' "$(parallel 10 POST "/loans/$L/repayments" "$L_FINANCE" "{\"amount\":$REM,\"channel\":\"bank_transfer\"}" "$KEY")"
assert "exactly one payment applied for the shared key" "$(loan_field "$L_FINANCE" "$L" .data.outstanding_balance)" 0
assert "duplicates were replayed or told to retry (no 5xx)" "$(count_code 500)" 0

section "Concurrent loan approval / disbursement"
B=$(register_pharmacy lagos-mfb "raceApprove"); apply "$B" "$RX" 20000000 4; L=$(jq -r .data.id <<<"$BODY")
printf '  2 officers approve simultaneously ×5 each: %s\n' "$(rm -f "$TMP"/p.*; for i in 1 2 3 4 5; do for T in "$L_OFFICER" "$L_OFFICER2"; do ( curl -s -o /dev/null -w '%{http_code}' -X POST "$BASE/loans/$L/approve" -H 'Accept: application/json' -H 'Content-Type: application/json' -H "Authorization: Bearer $T" -H "Idempotency-Key: $(uuid)" -d '{"note":"race"}' > "$TMP/p.code.$i$RANDOM" ) & done; done; wait; summarise)"
assert "exactly one approval succeeded" "$(count_code 200)" 1
assert "the rest saw an invalid state" "$(count_code 409)" 9
req GET "/audit-logs?auditable_id=$L&action=loan.approved" "$L_ADMIN" ""
assert "one approval in the audit trail" "$(jq '.data|length' <<<"$BODY")" 1
printf '  2 finance officers disburse simultaneously ×5 each: %s\n' "$(rm -f "$TMP"/p.*; for i in 1 2 3 4 5; do for T in "$L_FINANCE" "$L_FINANCE2"; do ( curl -s -o /dev/null -w '%{http_code}' -X POST "$BASE/loans/$L/disburse" -H 'Accept: application/json' -H "Authorization: Bearer $T" -H "Idempotency-Key: $(uuid)" > "$TMP/p.code.$i$RANDOM" ) & done; done; wait; summarise)"
assert "exactly one disbursement succeeded" "$(count_code 200)" 1
req GET "/loans/$L/transactions" "$L_FINANCE" ""
assert "money paid out exactly once" "$(jq '[.data[]|select(.type=="disbursement")]|length' <<<"$BODY")" 1
req GET "/loans/$L/schedule" "$L_FINANCE" ""
assert "one schedule (4 installments, not 8+)" "$(jq '.data|length' <<<"$BODY")" 4

# ------------------------------------------------------------------ 14-16 repayment edge cases
section "Overpayment"
B=$(register_pharmacy lagos-mfb "overpay"); apply "$B" "$RX" 20000000 4; L=$(jq -r .data.id <<<"$BODY")
approve "$L_OFFICER" "$L"; disburse "$L_FINANCE" "$L"; OUT=$(jq -r .data.outstanding_balance <<<"$BODY")
req POST "/loans/$L/repayments" "$L_FINANCE" "" "{\"amount\":$((OUT + 1)),\"channel\":\"cash\"}" auto; check "outstanding + 1 kobo → 422" 422 .error.code OVERPAYMENT
assert "nothing applied" "$(loan_field "$L_FINANCE" "$L" .data.amount_paid)" 0

section "Repayment after loan completion"
req POST "/loans/$L/repayments" "$L_FINANCE" "" "{\"amount\":$OUT,\"channel\":\"bank_transfer\"}" auto; check "exact payoff closes loan" 201 .data.loan.status repaid
req POST "/loans/$L/repayments" "$L_FINANCE" "" '{"amount":1,"channel":"cash"}' auto; check "1 kobo on a repaid loan → 409" 409 .error.code INVALID_LOAN_STATE
req POST "/loans/$L/repayments" "$B" "" '{"amount":1,"channel":"card"}' auto; check "borrower pays a repaid loan → 409" 409

section "Repayment after cancellation"
B=$(register_pharmacy lagos-mfb "cancelled"); apply "$B" "$RX" 20000000 4; L=$(jq -r .data.id <<<"$BODY")
approve "$L_OFFICER" "$L"
req POST "/loans/$L/cancel" "$B" "" '{"reason":"changed mind"}' auto; check "borrower cancels approved loan" 200 .data.status cancelled
req POST "/loans/$L/repayments" "$L_FINANCE" "" '{"amount":1000,"channel":"cash"}' auto; check "repay cancelled loan → 409" 409 .error.code INVALID_LOAN_STATE
disburse "$L_FINANCE" "$L";                                      check "disburse cancelled loan → 409" 409 .error.code INVALID_LOAN_STATE
approve "$L_OFFICER" "$L";                                       check "re-approve cancelled loan → 409" 409

# ------------------------------------------------------------------ 17-18 input validation
section "Negative / zero / malformed amounts"
B=$(register_pharmacy lagos-mfb "badInput")
for amt in 0 -1 -20000000 '"abc"' 1.5 99999999999999999999 null; do
  apply "$B" "$RX" "$amt" 4;                                     check "apply amount=$amt → 422" 422 .error.code VALIDATION_FAILED
done
req POST "/tenant/loan-products/$RX/quote" "" lagos-mfb '{"amount":-5,"tenor":3}'; check "quote amount=-5 → 422" 422
apply "$B" "$RX" 500 4;                                          check "below product minimum → 422" 422 .error.code LOAN_TERMS_VIOLATION
apply "$B" "$RX" 500000001 4;                                    check "above product maximum → 422" 422 .error.code LOAN_TERMS_VIOLATION
for amt in 0 -100; do
  req POST "/loans/$LAGOS_LOAN/repayments" "$L_FINANCE" "" "{\"amount\":$amt,\"channel\":\"cash\"}" auto; check "repay amount=$amt → 422" 422 .error.code VALIDATION_FAILED
done
req POST /loan-products "$L_ADMIN" "" '{"name":"Neg","code":"NEG","min_amount":-1,"max_amount":-5,"min_tenor":1,"max_tenor":2,"interest_rate_bps":-100,"interest_method":"flat","repayment_frequency":"monthly"}'
check "product with negative limits/rate → 422" 422 .error.code VALIDATION_FAILED
req GET /loans "$B" "";                                          check "no loan was created by bad input" 200 '.data|length' 0

section "Invalid tenor"
for t in 0 -3 7 521 '"six"' 2.5 null; do
  apply "$B" "$RX" 20000000 "$t"
  if [[ "$t" == 7 ]]; then check "tenor=$t (above product max 6) → 422" 422 .error.code LOAN_TERMS_VIOLATION
  else check "tenor=$t → 422" 422 .error.code VALIDATION_FAILED; fi
done

# ------------------------------------------------------------------ 19-20 product & limits
section "Product becoming inactive after quote"
req POST /loan-products "$L_ADMIN" "" "{\"name\":\"Flash $RUN\",\"code\":\"FLASH-$RUN\",\"min_amount\":1000000,\"max_amount\":90000000,\"min_tenor\":1,\"max_tenor\":6,\"interest_rate_bps\":2400,\"interest_method\":\"flat\",\"repayment_frequency\":\"monthly\"}"
FLASH=$(jq -r .data.id <<<"$BODY")
req POST "/tenant/loan-products/$FLASH/quote" "" lagos-mfb '{"amount":5000000,"tenor":3}'; check "quote while active" 200
req PATCH "/loan-products/$FLASH" "$L_ADMIN" "" '{"is_active":false}'; check "admin deactivates product" 200 .data.is_active false
B=$(register_pharmacy lagos-mfb "lateApplicant")
apply "$B" "$FLASH" 5000000 3;                                   check "apply after deactivation → 422" 422 .error.code LOAN_TERMS_VIOLATION
req POST "/tenant/loan-products/$FLASH/quote" "" lagos-mfb '{"amount":5000000,"tenor":3}'; check "re-quote after deactivation → 422" 422
req GET /tenant/loan-products "" lagos-mfb;                     assert "hidden from storefront" "$(jq "[.data[].id]|index(\"$FLASH\")" <<<"$BODY")" null
req PATCH "/loan-products/$FLASH" "$L_ADMIN" "" '{"is_active":true}'
apply "$B" "$FLASH" 5000000 3; FL=$(jq -r .data.id <<<"$BODY")
req PATCH "/loan-products/$FLASH" "$L_ADMIN" "" '{"is_active":false,"interest_rate_bps":9900}'
approve "$L_OFFICER" "$FL";                                      check "loan applied before deactivation can still be approved" 200
disburse "$L_FINANCE" "$FL";                                     check "…and disbursed on its original terms" 200 .data.interest_rate_bps 2400
req DELETE "/loan-products/$FLASH" "$L_ADMIN" "";               check "archive product" 204
req GET "/loans/$FL" "$B" "";                                    check "existing loan still readable after archive" 200 .data.status disbursed

section "Borrower exceeding maximum open loans (limit 3)"
B=$(register_pharmacy lagos-mfb "greedy")
for i in 1 2 3; do apply "$B" "$RX" 10000000 2; check "application $i accepted" 201; done
apply "$B" "$RX" 10000000 2;                                     check "4th open application → 422" 422 .error.code OPEN_LOAN_LIMIT_REACHED
req GET /loans "$B" "";                                          L1=$(jq -r '.data[0].id' <<<"$BODY")
req POST "/loans/$L1/cancel" "$B" "" '{}' auto
apply "$B" "$RX" 10000000 2;                                     check "after cancelling one, a new application fits" 201
B=$(register_pharmacy lagos-mfb "greedyRace")
printf '  8 simultaneous applications from a borrower with 0 loans: %s\n' "$(parallel 8 POST /loans "$B" "{\"loan_product_id\":\"$RX\",\"amount\":10000000,\"tenor\":2,\"purpose\":\"inventory_restock\"}" auto)"
assert "exactly 3 accepted under contention" "$(count_code 201)" 3
assert "5 refused by the limit" "$(count_code 422)" 5
req GET /loans "$B" "";                                          assert "borrower holds exactly 3 loans" "$(jq '.data|length' <<<"$BODY")" 3

# ------------------------------------------------------------------ 21 uniqueness races
section "Concurrent duplicates that both pass validation (unique-index races)"
printf '  8 identical borrower sign-ups at once: %s\n' "$(parallel 8 POST /tenant/customers "" "{\"contact_name\":\"Twin\",\"email\":\"twin.$RUN@probe.test\",\"password\":\"$PW\",\"business_name\":\"Twin $RUN\",\"business_type\":\"pharmacy\",\"registration_number\":\"RC$RUN\",\"licence_number\":\"PCN/1\",\"licence_expires_at\":\"2030-01-01\",\"year_established\":2015,\"phone\":\"+23470$RUN\",\"city\":\"Ikeja\",\"state\":\"Lagos\",\"monthly_revenue\":900000000,\"monthly_expenses\":300000000}" none lagos-mfb)"
assert "exactly one sign-up created" "$(count_code 201)" 1
assert "losers get 422 (never 500)" "$(count_code 422)" 7
printf '  8 identical lender registrations at once: %s\n' "$(parallel 8 POST /institutions "" "{\"institution\":{\"name\":\"Race $RUN\",\"slug\":\"race-$RUN\",\"email\":\"r@race.test\"},\"admin\":{\"name\":\"A\",\"email\":\"a.$RUN@race.test\",\"password\":\"$PW\"}}" none)"
assert "exactly one lender created" "$(count_code 201)" 1
assert "losers get 422 (never 500)" "$(count_code 422)" 7
printf '  8 identical product codes at once: %s\n' "$(parallel 8 POST /loan-products "$L_ADMIN" "{\"name\":\"Dup\",\"code\":\"DUP-$RUN\",\"min_amount\":1000000,\"max_amount\":9000000,\"min_tenor\":1,\"max_tenor\":3,\"interest_rate_bps\":2400,\"interest_method\":\"flat\",\"repayment_frequency\":\"monthly\"}" none)"
assert "exactly one product created" "$(count_code 201)" 1
assert "no server errors" "$(count_code 500)" 0
printf '  8 identical staff emails at once: %s\n' "$(parallel 8 POST /staff "$L_ADMIN" "{\"name\":\"Dup\",\"email\":\"dup.$RUN@lagos-mfb.test\",\"password\":\"$PW\",\"role\":\"loan_officer\"}" none)"
assert "exactly one staff member created" "$(count_code 201)" 1
assert "no server errors" "$(count_code 500)" 0

# ------------------------------------------------------------------ 22 KYC
section "BVN / NIN verification (KYC) before borrowing"
B=$(register_unverified lagos-mfb "kyc")
apply "$B" "$RX" 20000000 4;                                     check "application before KYC → 422 KYC_REQUIRED" 422 .error.code KYC_REQUIRED
req POST /me/kyc "$B" "" "$(kyc_body 1234 ABC)" auto;            check "malformed BVN / NIN → 422" 422 '.error.details | has("bvn") and has("nin")' true
req POST /me/kyc "$B" "" "$(kyc_body)" none;                     check "KYC without Idempotency-Key → 400" 400
BVN=$(digits11); NIN=$(digits11)
req POST /me/kyc "$B" "" "$(kyc_body "$BVN" "$NIN")" auto;       check "KYC verified with NIBSS + NIMC (sandbox)" 200 .data.status verified
check "BVN only ever returned masked" 200 .data.owner.bvn.masked "*******${BVN: -4}"
assert "plain BVN absent from response" "$(grep -c "$BVN" <<<"$BODY")" 0
req POST /me/kyc "$B" "" "$(kyc_body)" auto;                     check "resubmitting after verification → 409" 409 .error.code KYC_ALREADY_VERIFIED
apply "$B" "$RX" 20000000 4;                                     check "application after KYC → 201" 201
B2=$(register_unverified lagos-mfb "kycTwin")
req POST /me/kyc "$B2" "" "$(kyc_body "$BVN" "$NIN")" auto;      check "same BVN for another business, same lender → 422" 422 '.error.details.bvn[0]' "This BVN is already linked to another business."
B4=$(register_unverified lagos-mfb "kycRace")
printf '  8 simultaneous KYC submissions for one borrower: %s\n' "$(parallel 8 POST /me/kyc "$B4" "$(kyc_body)" auto)"
assert "exactly one verification accepted" "$(count_code 200)" 1
assert "others refused with 409 (never 500)" "$(count_code 409)" 7

# ------------------------------------------------------------------ 23 KYC settings (lender's choice)
section "Lender-controlled KYC settings"
L_ADMIN=$(login admin@lagos-mfb.test lagos-mfb)
req PATCH /kyc-settings "$B" "" '{"kyc_required":false}';         check "borrower cannot change KYC settings → 403" 403
req PATCH /kyc-settings "$L_ADMIN" "" '{"bvn_check_enabled":false,"nin_check_enabled":false}'; check "require KYC with no checks → 422" 422
req PATCH /kyc-settings "$L_ADMIN" "" '{"nin_check_enabled":false}'; check "Lagos switches to BVN only" 200 '.data.enabled_checks | join(",")' bvn
req GET /tenant "" lagos-mfb;                                     check "storefront advertises BVN only" 200 '.data.kyc.checks | join(",")' bvn
B5=$(register_unverified lagos-mfb "bvnOnly")
req POST /me/kyc "$B5" "" "{\"first_name\":\"Ada\",\"last_name\":\"Probe\",\"date_of_birth\":\"1985-04-12\",\"phone\":\"+2348030000000\",\"bvn\":\"$(digits11)\"}" auto
check "KYC passes with BVN alone (no NIN sent)" 200 '.data.checks_passed | join(",")' bvn
req PATCH /kyc-settings "$L_ADMIN" "" '{"nin_check_enabled":true}'
req GET /me/kyc "$B5" "";                                         check "re-enabling NIN leaves it outstanding" 200 '.data.outstanding_checks | join(",")' nin
apply "$B5" "$RX" 20000000 4;                                     check "…so the borrower is blocked again" 422 .error.code KYC_REQUIRED
req PATCH /kyc-settings "$L_ADMIN" "" '{"kyc_required":false}'
apply "$B5" "$RX" 20000000 4;                                     check "KYC requirement off → application accepted" 201
req GET /tenant "" abuja-credit;                                  check "Abuja's settings untouched by Lagos" 200 .data.kyc.required_before_applying true
req PATCH /kyc-settings "$L_ADMIN" "" '{"kyc_required":true,"bvn_check_enabled":true,"nin_check_enabled":true,"phone_match_required":false}'; check "Lagos restored to defaults" 200

# ------------------------------------------------------------------ summary
printf '\n\033[1mRESULT: %d passed, %d failed\033[0m\n' "$PASS" "$FAIL"
[[ $FAIL == 0 ]]
