import csv
import json
import re
import sys
from collections import Counter, defaultdict
from datetime import datetime
from pathlib import Path


PLACEHOLDERS = {"", "N/A", "NA", "NONE", "NULL", "NO", "0", "-"}


def compact(value):
    return re.sub(r"\s+", " ", (value or "").strip())


def norm_name(value):
    return re.sub(r"[\s\.,;:_\-]+", "", compact(value)).casefold()


def norm_phone(value):
    digits = re.sub(r"\D", "", value or "")
    if digits.startswith("855"):
        digits = "0" + digits[3:]
    elif len(digits) == 9 and not digits.startswith("0"):
        digits = "0" + digits
    return digits if len(digits) >= 8 else ""


def norm_nid(value):
    raw = compact(value).upper()
    if raw in PLACEHOLDERS:
        return ""
    cleaned = re.sub(r"[^0-9A-Z\u1780-\u17ff]", "", raw)
    return cleaned if len(cleaned) >= 4 else ""


def parse_amount(value):
    cleaned = re.sub(r"[^0-9.\-]", "", value or "")
    try:
        return float(cleaned) if cleaned else 0.0
    except ValueError:
        return 0.0


def parse_date(value):
    value = compact(value)
    if not value:
        return ""
    try:
        return datetime.fromisoformat(value.replace("Z", "+00:00")).date().isoformat()
    except ValueError:
        return value[:10]


def read_sharepoint_csv(path):
    with path.open(encoding="utf-8-sig", newline="") as handle:
        reader = csv.reader(handle)
        next(reader, None)
        header = next(reader)
        rows = []
        for values in reader:
            values = values[: len(header)] + [""] * max(0, len(header) - len(values))
            rows.append(dict(zip(header, values)))
        return rows


class UnionFind:
    def __init__(self, size):
        self.parent = list(range(size))

    def find(self, item):
        while self.parent[item] != item:
            self.parent[item] = self.parent[self.parent[item]]
            item = self.parent[item]
        return item

    def union(self, left, right):
        left, right = self.find(left), self.find(right)
        if left != right:
            self.parent[right] = left


def group_by(rows, key_fn):
    result = defaultdict(list)
    for index, row in enumerate(rows):
        key = key_fn(row)
        if key:
            result[key].append(index)
    return result


def first_useful(rows, field, placeholder=False):
    values = [compact(row.get(field, "")) for row in rows]
    if placeholder:
        values = [value for value in values if value.upper() not in PLACEHOLDERS]
    else:
        values = [value for value in values if value]
    return Counter(values).most_common(1)[0][0] if values else ""


def main(customer_path, repayment_path, output_path):
    source = read_sharepoint_csv(Path(customer_path))
    repayments_source = read_sharepoint_csv(Path(repayment_path))
    uf = UnionFind(len(source))

    nid_groups = group_by(source, lambda row: norm_nid(row.get("CusNID")))
    pair_groups = group_by(
        source,
        lambda row: (norm_name(row.get("CusName")), norm_phone(row.get("CusPhone")))
        if norm_name(row.get("CusName")) and norm_phone(row.get("CusPhone"))
        else None,
    )
    name_groups = group_by(source, lambda row: norm_name(row.get("CusName")))

    for groups in (nid_groups, pair_groups):
        for indexes in groups.values():
            for index in indexes[1:]:
                uf.union(indexes[0], index)

    # Name-only matching is accepted only when the rows do not contain conflicting
    # real phone numbers or national IDs.
    for indexes in name_groups.values():
        phones = {norm_phone(source[i].get("CusPhone")) for i in indexes} - {""}
        nids = {norm_nid(source[i].get("CusNID")) for i in indexes} - {""}
        if len(phones) <= 1 and len(nids) <= 1:
            for index in indexes[1:]:
                uf.union(indexes[0], index)

    components = defaultdict(list)
    for index in range(len(source)):
        components[uf.find(index)].append(index)
    ordered_components = sorted(components.values(), key=lambda indexes: min(indexes))

    customers = []
    index_to_customer = {}
    for number, indexes in enumerate(ordered_components, 1):
        rows = [source[index] for index in indexes]
        customer_key = f"CUST-{number:04d}"
        for index in indexes:
            index_to_customer[index] = customer_key
        phones = [norm_phone(row.get("CusPhone")) for row in rows]
        phones = [value for value in phones if value]
        nids = [norm_nid(row.get("CusNID")) for row in rows]
        nids = [value for value in nids if value]
        customers.append({
            "import_customer_key": customer_key,
            "full_name": first_useful(rows, "CusName"),
            "gender": first_useful(rows, "CusSex"),
            "phone": Counter(phones).most_common(1)[0][0] if phones else "",
            "nid": Counter(nids).most_common(1)[0][0] if nids else "",
            "address": first_useful(rows, "CusAddress", placeholder=True),
            "note": first_useful(rows, "Note", placeholder=True),
            "source_customer_ids": ", ".join(row.get("Customer ID", "") for row in rows),
            "source_loan_ids": ", ".join(row.get("CustomerID", "") for row in rows),
            "loan_count": len(rows),
            "match_basis": "national_id" if any(len(v) > 1 for v in nid_groups.values() if set(v).issubset(set(indexes))) else ("name_and_phone" if len(rows) > 1 else "single"),
        })

    source_id_groups = defaultdict(list)
    loans = []
    for index, row in enumerate(source):
        old_loan_id = compact(row.get("CustomerID"))
        source_id_groups[old_loan_id].append(index)
        loan_key = f"LOAN-{old_loan_id or row.get('Customer ID', index + 1)}-{row.get('Customer ID', index + 1)}"
        currency = "KHR" if "៛" in (row.get("LoanAmount", "") + row.get("LoanRepayment", "")) else "USD"
        loans.append({
            "import_loan_key": loan_key,
            "import_customer_key": index_to_customer[index],
            "source_loan_id": old_loan_id,
            "source_row_id": compact(row.get("Customer ID")),
            "principal": parse_amount(row.get("LoanAmount")),
            "currency_code": currency,
            "interest_rate": parse_amount(row.get("InterestRate")),
            "term_months": int(parse_amount(row.get("LoanTenor"))) if parse_amount(row.get("LoanTenor")) else 0,
            "scheduled_repayment": parse_amount(row.get("LoanRepayment")),
            "start_date": parse_date(row.get("LoanDate")),
            "first_due_date": parse_date(row.get("ScheduleRepayment")),
            "loan_type": compact(row.get("TypeOfLoan")),
            "status_detail": compact(row.get("LoanStatus")),
            "collateral": compact(row.get("Collateral")),
            "collateral_id": compact(row.get("CollateralID")),
            "staff_id": compact(row.get("StaffID")),
            "note": compact(row.get("Note")),
        })

    reviews = []
    phone_groups = group_by(source, lambda row: norm_phone(row.get("CusPhone")))
    for phone_value, indexes in phone_groups.items():
        names = {norm_name(source[i].get("CusName")) for i in indexes}
        if len(indexes) > 1 and len(names) > 1:
            reviews.append({
                "issue_type": "shared_phone_different_names",
                "match_value": phone_value,
                "source_ids": ", ".join(source[i].get("Customer ID", "") for i in indexes),
                "names": " | ".join(sorted({compact(source[i].get("CusName")) for i in indexes})),
                "recommendation": "Kept separate unless name or national ID also matched. Review manually.",
            })

    for name_value, indexes in name_groups.items():
        phones = {norm_phone(source[i].get("CusPhone")) for i in indexes} - {""}
        nids = {norm_nid(source[i].get("CusNID")) for i in indexes} - {""}
        if len(indexes) > 1 and (len(phones) > 1 or len(nids) > 1):
            reviews.append({
                "issue_type": "same_name_conflicting_identity",
                "match_value": compact(source[indexes[0]].get("CusName")),
                "source_ids": ", ".join(source[i].get("Customer ID", "") for i in indexes),
                "names": " | ".join(sorted({compact(source[i].get("CusPhone")) for i in indexes})),
                "recommendation": "Kept separate where stronger phone/NID evidence conflicts. Review manually.",
            })

    loan_by_old_id = {}
    for old_id, indexes in source_id_groups.items():
        if old_id and len(indexes) == 1:
            loan_by_old_id[old_id] = loans[indexes[0]]["import_loan_key"]
        elif old_id and len(indexes) > 1:
            reviews.append({
                "issue_type": "duplicate_source_loan_id",
                "match_value": old_id,
                "source_ids": ", ".join(source[i].get("Customer ID", "") for i in indexes),
                "names": " | ".join(compact(source[i].get("CusName")) for i in indexes),
                "recommendation": "Repayments cannot be assigned automatically until this source ID is resolved.",
            })

    repayments = []
    for row in repayments_source:
        old_loan_id = compact(row.get("CustomerID"))
        loan_key = loan_by_old_id.get(old_loan_id, "")
        amount = parse_amount(row.get("RepaymentAmount"))
        repayments.append({
            "source_followup_id": compact(row.get("Followup ID")),
            "source_loan_id": old_loan_id,
            "import_loan_key": loan_key,
            "pay_date": parse_date(row.get("FollowupDate")),
            "amount": amount,
            "import_as_payment": "YES" if amount > 0 and loan_key else "NO",
            "followup_time": compact(row.get("FollowupTime")),
            "followup_result": compact(row.get("FollowupResult")),
            "followup_reason": compact(row.get("FollowupReason")),
            "next_followup_date": parse_date(row.get("DateNextFollowup")),
            "staff_id": compact(row.get("StaffID")),
            "note": compact(row.get("Note")),
        })
        if not loan_key:
            reviews.append({
                "issue_type": "unmapped_repayment",
                "match_value": old_loan_id,
                "source_ids": compact(row.get("Followup ID")),
                "names": "",
                "recommendation": "No unique source loan ID was found. Assign a loan manually before import.",
            })

    summary = {
        "source_loan_rows": len(source),
        "deduplicated_customers": len(customers),
        "customers_removed_as_duplicates": len(source) - len(customers),
        "repayment_followup_rows": len(repayments),
        "positive_mapped_payments": sum(1 for row in repayments if row["import_as_payment"] == "YES"),
        "review_items": len(reviews),
    }
    payload = {"summary": summary, "customers": customers, "loans": loans, "repayments": repayments, "review": reviews}
    Path(output_path).write_text(json.dumps(payload, ensure_ascii=False, indent=2), encoding="utf-8")


if __name__ == "__main__":
    main(*sys.argv[1:4])
