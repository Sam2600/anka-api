<?php

namespace App\Services;

use App\Models\Contract;
use App\Models\Deal;
use App\Models\Invoice;
use App\Models\Project;
use App\Models\Tenant;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Renders an Invoice as an .xlsx file matching the layout of the reference
 * template (AA_System_Invoice_2060530.xls). The output is code-generated
 * (not template-based) because we deviate from the reference in three
 * ways:
 *
 *   - No USD conversion block (rows 40-49 in the reference are dropped
 *     per the spec: "no currency change yet, do not change to $").
 *   - VAT is hardcoded at 5% (per spec OQ — not pulled from tenants.tax_rate).
 *   - Bank accounts are N rows from tenant_bank_accounts, not the fixed
 *     Kanbawza + AYA pair.
 *
 * Cell layout mirrors the reference where it makes sense:
 *
 *   A1                 "Invoice" title
 *   A3..A6             Tenant: name / address (multi-line) / phone
 *   H3..J5             Invoice # / Date / Payment Deadline (label + colon + value)
 *   G7..H9             "To," + customer name + customer address
 *   A11..A12           "Memo:" + memo text
 *   A17..I17           Table header: Description | Quantity | Cost | Price
 *   A20                Section header: "■{project name}" + " — Fee for {period}"
 *   A22..              One row per line item (label | qty | cost | amount)
 *   F{n}..J{n}         "{CURRENCY} SUB TOTAL" / "VAT 5%" / "{CURRENCY} TOTAL"
 *   A{n}..             Bank account blocks (one per tenant_bank_accounts row)
 *   A{n}               Closing thank-you line
 *
 * The exact row numbers shift based on how many line items + bank
 * accounts the tenant has, so the renderer tracks `$row` and writes
 * sequentially. Static row constants are avoided.
 */
class InvoiceXlsxService
{
    private const VAT_RATE = 0.05;
    private const CLOSING_MESSAGE = "Thank you very much for your business. We're looking forward to serving you again.";

    public function render(Invoice $invoice): string
    {
        $invoice->loadMissing(['contract.deal', 'tenant']);
        $contract = $invoice->contract;
        $deal = $contract?->deal;
        $tenant = $invoice->tenant;
        $project = $contract ? Project::where('contract_id', $contract->id)->first() : null;

        $lineItems = is_array($invoice->line_items) && count($invoice->line_items) > 0
            ? $invoice->line_items
            : (new InvoiceLineItemBuilder)->buildForContract($contract);

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Invoice');

        // Title
        $sheet->setCellValue('A1', 'Invoice');
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(20);

        // Tenant block (top-left)
        $sheet->setCellValue('A3', (string) ($tenant->name ?? ''));
        $sheet->getStyle('A3')->getFont()->setBold(true);
        $sheet->setCellValue('A4', (string) ($tenant->address ?? ''));
        $sheet->getStyle('A4')->getAlignment()->setWrapText(true);
        $sheet->setCellValue('A5', (string) ($tenant->phone ? 'Tel: '.$tenant->phone : ''));

        // Invoice metadata (top-right)
        $sheet->setCellValue('H3', 'Invoice #');
        $sheet->setCellValue('I3', ':');
        $sheet->setCellValue('J3', (string) ($invoice->invoice_number ?? ''));
        $sheet->setCellValue('H4', 'Date');
        $sheet->setCellValue('I4', ':');
        $sheet->setCellValue('J4', $invoice->issue_date?->format('Y-m-d') ?? '');
        $sheet->setCellValue('H5', 'Payment Deadline');
        $sheet->setCellValue('I5', ':');
        $sheet->setCellValue('J5', $invoice->due_date?->format('Y-m-d') ?? '');
        $sheet->getStyle('H3:H5')->getFont()->setBold(true);

        // To: block (customer)
        $sheet->setCellValue('G7', 'To,');
        $sheet->getStyle('G7')->getFont()->setBold(true);
        $sheet->setCellValue('H7', (string) ($deal?->client ?? ''));
        $sheet->getStyle('H7')->getFont()->setBold(true);
        $sheet->setCellValue('H8', (string) ($deal?->customer_address ?? ''));
        $sheet->getStyle('H8')->getAlignment()->setWrapText(true);

        // Memo
        $sheet->setCellValue('A11', 'Memo:');
        $sheet->getStyle('A11')->getFont()->setBold(true);
        $sheet->setCellValue('A12', (string) ($invoice->memo ?? ''));
        $sheet->getStyle('A12')->getAlignment()->setWrapText(true);

        // Table header (Description | Quantity | Cost | Price)
        $sheet->setCellValue('A17', 'Description');
        $sheet->setCellValue('G17', 'Quantity');
        $sheet->setCellValue('H17', 'Cost');
        $sheet->setCellValue('I17', 'Price');
        $sheet->getStyle('A17:I17')->getFont()->setBold(true);
        $sheet->getStyle('A17:I17')->getBorders()->getBottom()->setBorderStyle(Border::BORDER_THIN);

        // Section header (project name + billing period)
        $sectionLabel = '■'.($project?->name ?? $deal?->name ?? 'Project');
        $periodLabel = $invoice->billing_period_label ?? '';
        $sheet->setCellValue('A20', $sectionLabel);
        $sheet->getStyle('A20')->getFont()->setBold(true);
        if ($periodLabel !== '') {
            $sheet->setCellValue('E20', $periodLabel);
            $sheet->getStyle('E20')->getFont()->setItalic(true);
        }

        // Line items: resources then overheads
        $row = 22;
        $resourceLines = array_values(array_filter($lineItems, fn ($l) => ($l['kind'] ?? 'resource') === 'resource'));
        $overheadLines = array_values(array_filter($lineItems, fn ($l) => ($l['kind'] ?? '') === 'overhead'));

        if (! empty($resourceLines)) {
            $sheet->setCellValue('A'.$row, 'Monthly');
            $sheet->getStyle('A'.$row)->getFont()->setItalic(true);
            $row++;
            foreach ($resourceLines as $li) {
                $this->writeLineRow($sheet, $row, $li);
                $row++;
            }
            $row++; // blank spacer
        }

        if (! empty($overheadLines)) {
            foreach ($overheadLines as $li) {
                $this->writeLineRow($sheet, $row, $li);
                $row++;
            }
            $row++; // blank spacer
        }

        // Subtotal / VAT / Total
        $subTotal = array_sum(array_map(fn ($l) => (float) ($l['amount'] ?? 0), $lineItems));
        $vat = round($subTotal * self::VAT_RATE, 2);
        $total = round($subTotal + $vat, 2);
        $currency = (string) ($tenant->currency ?? 'MMK');

        $sheet->setCellValue('F'.$row, $currency.' SUB TOTAL');
        $sheet->setCellValue('I'.$row, $subTotal);
        $sheet->getStyle('F'.$row.':I'.$row)->getFont()->setBold(true);
        $sheet->getStyle('I'.$row)->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_NUMBER_COMMA_SEPARATED1);
        $row++;
        $sheet->setCellValue('F'.$row, 'VAT 5%');
        $sheet->setCellValue('I'.$row, $vat);
        $sheet->getStyle('I'.$row)->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_NUMBER_COMMA_SEPARATED1);
        $row++;
        $sheet->setCellValue('F'.$row, $currency.' TOTAL');
        $sheet->setCellValue('I'.$row, $total);
        $sheet->getStyle('F'.$row.':I'.$row)->getFont()->setBold(true);
        $sheet->getStyle('F'.$row.':I'.$row)
            ->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('F2F2F2');
        $sheet->getStyle('I'.$row)->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_NUMBER_COMMA_SEPARATED1);
        $row += 3; // gap before bank info

        // Bank accounts (N rows from tenant_bank_accounts, sorted by sort_order
        // via the relationship). Each account block is 6 lines tall plus a
        // trailing blank for breathing room.
        $banks = $tenant ? $tenant->bankAccounts()->get() : collect();
        foreach ($banks as $bank) {
            $sheet->setCellValue('A'.$row, (string) $bank->label);
            $sheet->getStyle('A'.$row)->getFont()->setBold(true);
            $row++;
            $sheet->setCellValue('A'.$row, 'Account Name: '.($bank->account_name ?? ''));
            $row++;
            $sheet->setCellValue('A'.$row, 'Account No: '.($bank->account_no ?? ''));
            $row++;
            $sheet->setCellValue('A'.$row, 'Branch Name: '.($bank->branch_name ?? ''));
            $row++;
            if ($bank->branch_address) {
                $sheet->setCellValue('A'.$row, 'Branch Address: '.$bank->branch_address);
                $row++;
            }
            if ($bank->branch_no) {
                $sheet->setCellValue('A'.$row, 'Branch No: '.$bank->branch_no);
                $row++;
            }
            if ($bank->swift_code) {
                $sheet->setCellValue('A'.$row, 'SWIFT Code: '.$bank->swift_code);
                $row++;
            }
            $row++; // blank spacer between accounts
        }

        // Closing line
        $sheet->setCellValue('A'.$row, self::CLOSING_MESSAGE);
        $sheet->getStyle('A'.$row)->getFont()->setItalic(true);

        // Column widths — wide A, narrow B-F, mid G-I, narrow J
        $sheet->getColumnDimension('A')->setWidth(38);
        foreach (range('B', 'F') as $col) {
            $sheet->getColumnDimension($col)->setWidth(12);
        }
        $sheet->getColumnDimension('G')->setWidth(12);
        $sheet->getColumnDimension('H')->setWidth(18);
        $sheet->getColumnDimension('I')->setWidth(18);
        $sheet->getColumnDimension('J')->setWidth(20);

        // Write to a buffer and return the binary
        $writer = new Xlsx($spreadsheet);
        ob_start();
        $writer->save('php://output');
        return (string) ob_get_clean();
    }

    private function writeLineRow($sheet, int $row, array $line): void
    {
        $sheet->setCellValue('A'.$row, (string) ($line['label'] ?? ''));
        $sheet->setCellValue('G'.$row, (float) ($line['quantity'] ?? 0));
        $sheet->setCellValue('H'.$row, (float) ($line['cost'] ?? 0));
        $sheet->setCellValue('I'.$row, (float) ($line['amount'] ?? 0));
        $sheet->getStyle('G'.$row)->getNumberFormat()->setFormatCode('0.00');
        $sheet->getStyle('H'.$row)->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_NUMBER_COMMA_SEPARATED1);
        $sheet->getStyle('I'.$row)->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_NUMBER_COMMA_SEPARATED1);
    }
}
