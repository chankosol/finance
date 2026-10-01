import fs from "node:fs/promises";
import { SpreadsheetFile, Workbook } from "@oai/artifact-tool";

const [jsonPath, outputPath, previewDir] = process.argv.slice(2);
const data = JSON.parse(await fs.readFile(jsonPath, "utf8"));
const workbook = Workbook.create();

const colors = { navy: "#17324D", blue: "#2563EB", pale: "#EAF2FF", line: "#D6DEE8", amber: "#FFF4CC", red: "#FDE8E8" };

function title(sheet, text, subtitle, width) {
  sheet.showGridLines = false;
  sheet.getRangeByIndexes(0, 0, 1, width).merge();
  sheet.getCell(0, 0).values = [[text]];
  sheet.getCell(0, 0).format = { fill: colors.navy, font: { bold: true, color: "#FFFFFF", size: 16 }, rowHeight: 30 };
  sheet.getRangeByIndexes(1, 0, 1, width).merge();
  sheet.getCell(1, 0).values = [[subtitle]];
  sheet.getCell(1, 0).format = { fill: colors.pale, font: { color: "#334155", italic: true }, wrapText: true, rowHeight: 34 };
}

function addDataSheet(name, subtitle, rows, columns, options = {}) {
  const sheet = workbook.worksheets.add(name);
  title(sheet, name, subtitle, columns.length);
  const headers = columns.map((column) => column.label);
  sheet.getRangeByIndexes(3, 0, 1, columns.length).values = [headers];
  sheet.getRangeByIndexes(3, 0, 1, columns.length).format = { fill: colors.blue, font: { bold: true, color: "#FFFFFF" }, wrapText: true, rowHeight: 34 };
  if (rows.length) {
    const values = rows.map((row) => columns.map((column) => row[column.key] ?? ""));
    sheet.getRangeByIndexes(4, 0, values.length, columns.length).values = values;
    sheet.getRangeByIndexes(4, 0, values.length, columns.length).format = { borders: { preset: "all", style: "thin", color: colors.line }, verticalAlignment: "top" };
    columns.forEach((column, index) => {
      const range = sheet.getRangeByIndexes(4, index, values.length, 1);
      if (column.numberFormat) range.format.numberFormat = column.numberFormat;
      if (column.wrap) range.format.wrapText = true;
    });
    if (options.highlightReview) {
      sheet.getRangeByIndexes(4, 0, values.length, columns.length).conditionalFormats.addCustom("=$A5=\"unmapped_repayment\"", { fill: colors.red });
    }
    sheet.tables.add(sheet.getRangeByIndexes(3, 0, values.length + 1, columns.length), true, `${name.replace(/[^A-Za-z0-9]/g, "")}Table`);
  }
  columns.forEach((column, index) => { sheet.getRangeByIndexes(0, index, Math.max(rows.length + 4, 5), 1).format.columnWidth = column.width || 16; });
  sheet.freezePanes.freezeRows(4);
  return sheet;
}

const summary = workbook.worksheets.add("Summary");
title(summary, "Narinn Data Import Preparation", "Prepared for one customer with many loans. Source metadata rows were removed and identity matches were normalized.", 6);
summary.getRange("A4:B10").values = [
  ["Metric", "Count"],
  ["Source loan rows", data.summary.source_loan_rows],
  ["Deduplicated customers", data.summary.deduplicated_customers],
  ["Duplicate customer rows consolidated", data.summary.customers_removed_as_duplicates],
  ["Follow-up / repayment rows", data.summary.repayment_followup_rows],
  ["Positive mapped payments", data.summary.positive_mapped_payments],
  ["Items requiring review", data.summary.review_items],
];
summary.getRange("A4:B4").format = { fill: colors.blue, font: { bold: true, color: "#FFFFFF" } };
summary.getRange("A5:B10").format.borders = { preset: "all", style: "thin", color: colors.line };
summary.getRange("D4:F4").merge();
summary.getRange("D4").values = [["Import order"]];
summary.getRange("D4:F4").format = { fill: colors.blue, font: { bold: true, color: "#FFFFFF" } };
summary.getRange("D5:F10").merge(true);
summary.getRange("D5:F10").values = [
  ["1. Review the Review sheet before importing."],
  ["2. Import Customers using import_customer_key as the temporary mapping key."],
  ["3. Import Loans and replace import_customer_key with the new customer database ID."],
  ["4. Import only Repayments rows marked YES; replace import_loan_key with the new loan database ID."],
  ["5. Zero-amount rows are follow-up history, not cash payments."],
  ["Matching rule: national ID, or exact normalized name plus phone. Conflicting evidence stays separate."],
];
summary.getRange("D5:F10").format = { fill: "#F8FAFC", wrapText: true, borders: { preset: "all", style: "thin", color: colors.line }, rowHeight: 34 };
summary.getRange("A1:F10").format.verticalAlignment = "top";
summary.getRange("A:A").format.columnWidth = 34;
summary.getRange("B:B").format.columnWidth = 16;
summary.getRange("C:C").format.columnWidth = 3;
summary.getRange("D:F").format.columnWidth = 24;
summary.freezePanes.freezeRows(3);

addDataSheet("Customers", "One row per deduplicated customer. The source ID columns provide an audit trail.", data.customers, [
  { key: "import_customer_key", label: "import_customer_key", width: 20 },
  { key: "full_name", label: "full_name", width: 28 },
  { key: "gender", label: "gender", width: 12 },
  { key: "phone", label: "phone", width: 18 },
  { key: "nid", label: "national_id", width: 20 },
  { key: "address", label: "address", width: 30, wrap: true },
  { key: "note", label: "note", width: 28, wrap: true },
  { key: "loan_count", label: "loan_count", width: 12, numberFormat: "0" },
  { key: "match_basis", label: "match_basis", width: 18 },
  { key: "source_customer_ids", label: "source_customer_row_ids", width: 34, wrap: true },
  { key: "source_loan_ids", label: "source_loan_ids", width: 34, wrap: true },
]);

addDataSheet("Loans", "Every original customer export row is retained as a separate loan and linked to one customer key.", data.loans, [
  { key: "import_loan_key", label: "import_loan_key", width: 22 },
  { key: "import_customer_key", label: "import_customer_key", width: 20 },
  { key: "source_loan_id", label: "source_loan_id", width: 16 },
  { key: "source_row_id", label: "source_row_id", width: 16 },
  { key: "principal", label: "principal", width: 16, numberFormat: "#,##0.00" },
  { key: "currency_code", label: "currency_code", width: 14 },
  { key: "interest_rate", label: "interest_rate", width: 14, numberFormat: "0.00" },
  { key: "term_months", label: "term_months", width: 14, numberFormat: "0" },
  { key: "scheduled_repayment", label: "scheduled_repayment", width: 20, numberFormat: "#,##0.00" },
  { key: "start_date", label: "start_date", width: 14 },
  { key: "first_due_date", label: "first_due_date", width: 16 },
  { key: "loan_type", label: "loan_type", width: 24 },
  { key: "status_detail", label: "status_detail", width: 24 },
  { key: "collateral", label: "collateral", width: 22 },
  { key: "collateral_id", label: "collateral_id", width: 18 },
  { key: "staff_id", label: "staff_id", width: 12 },
  { key: "note", label: "note", width: 28, wrap: true },
]);

addDataSheet("Repayments", "Mapped to source loans. Import only rows where import_as_payment is YES; the other rows are follow-up history or unresolved.", data.repayments, [
  { key: "source_followup_id", label: "source_followup_id", width: 18 },
  { key: "source_loan_id", label: "source_loan_id", width: 16 },
  { key: "import_loan_key", label: "import_loan_key", width: 22 },
  { key: "pay_date", label: "pay_date", width: 14 },
  { key: "amount", label: "amount", width: 16, numberFormat: "#,##0.00" },
  { key: "import_as_payment", label: "import_as_payment", width: 18 },
  { key: "followup_time", label: "followup_time", width: 14 },
  { key: "followup_result", label: "followup_result", width: 22 },
  { key: "followup_reason", label: "followup_reason", width: 24 },
  { key: "next_followup_date", label: "next_followup_date", width: 18 },
  { key: "staff_id", label: "staff_id", width: 12 },
  { key: "note", label: "note", width: 24, wrap: true },
]);

addDataSheet("Review", "These cases were deliberately not auto-merged or auto-mapped. Resolve them before a final database import.", data.review, [
  { key: "issue_type", label: "issue_type", width: 30 },
  { key: "match_value", label: "match_value", width: 24 },
  { key: "source_ids", label: "source_ids", width: 34, wrap: true },
  { key: "names", label: "names_or_phones", width: 50, wrap: true },
  { key: "recommendation", label: "recommendation", width: 52, wrap: true },
], { highlightReview: true });

await fs.mkdir(previewDir, { recursive: true });
const previewRanges = {
  Summary: "A1:F10",
  Customers: "A1:K24",
  Loans: "A1:Q20",
  Repayments: "A1:L24",
  Review: "A1:E24",
};
for (const [sheetName, range] of Object.entries(previewRanges)) {
  const preview = await workbook.render({ sheetName, range, scale: sheetName === "Summary" ? 1.5 : 0.8, format: "png" });
  await fs.writeFile(`${previewDir}/${sheetName}.png`, new Uint8Array(await preview.arrayBuffer()));
}

const check = await workbook.inspect({ kind: "table", range: "Summary!A1:F10", include: "values,formulas", tableMaxRows: 12, tableMaxCols: 8 });
console.log(check.ndjson);
const errors = await workbook.inspect({ kind: "match", searchTerm: "#REF!|#DIV/0!|#VALUE!|#NAME\\?|#N/A", options: { useRegex: true, maxResults: 100 }, summary: "final formula error scan" });
console.log(errors.ndjson);

const output = await SpreadsheetFile.exportXlsx(workbook);
await output.save(outputPath);
