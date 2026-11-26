<?php

namespace App\Modules\Catalog\Console;

use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Services\CatalogService;
use Illuminate\Console\Command;

class ImportProductsCsvCommand extends Command
{
    protected $signature = 'catalog:import-csv
        {path : Path to UTF-8 (without BOM) CSV file}
        {--organization-id= : Target organization UUID}
        {--branch-id= : Target branch UUID for opening stock}
        {--dry-run : Validate and preview without writing to database}';

    protected $description = 'Import initial products (with or without barcode) and opening stock from CSV';

    public function handle(CatalogService $catalogService): int
    {
        $path = (string) $this->argument('path');
        $organizationId = trim((string) ($this->option('organization-id') ?? ''));
        $branchId = trim((string) ($this->option('branch-id') ?? ''));
        $dryRun = (bool) $this->option('dry-run');

        if ($organizationId === '') {
            $this->error('--organization-id is required.');
            return self::FAILURE;
        }

        if (!is_file($path) || !is_readable($path)) {
            $this->error("CSV file not readable: {$path}");
            return self::FAILURE;
        }

        $raw = file_get_contents($path);
        if ($raw === false) {
            $this->error("Failed to read CSV file: {$path}");
            return self::FAILURE;
        }

        // Strip UTF-8 BOM if present while keeping UTF-8 without BOM clean
        if (str_starts_with($raw, "\xEF\xBB\xBF")) {
            $raw = substr($raw, 3);
        }

        $lines = preg_split("/\r\n|\n|\r/", trim($raw)) ?: [];
        if (count($lines) < 2) {
            $this->error('CSV file has no data rows.');
            return self::FAILURE;
        }

        $headers = array_map(static fn ($h) => trim((string) $h), str_getcsv(array_shift($lines)));
        $imported = 0;
        $skipped = 0;

        foreach ($lines as $idx => $line) {
            $rowNumber = $idx + 2;
            if (trim($line) === '') {
                continue;
            }

            $values = str_getcsv($line);
            $row = [];
            foreach ($headers as $colIndex => $colName) {
                $row[$colName] = isset($values[$colIndex]) ? trim((string) $values[$colIndex]) : '';
            }

            $sku = $row['sku'] ?? '';
            $name = $row['name'] ?? '';
            $barcode = $row['barcode'] ?? '';

            if ($sku === '' || $name === '') {
                $this->warn("Row {$rowNumber}: Skipped (missing sku or name).");
                $skipped++;
                continue;
            }

            $exists = Product::query()
                ->where('organization_id', $organizationId)
                ->where('sku', $sku)
                ->exists();

            if ($exists) {
                $this->warn("Row {$rowNumber} ({$sku}): Skipped (SKU already exists in organization).");
                $skipped++;
                continue;
            }

            $openingQty = is_numeric($row['opening_qty'] ?? null)
                ? (float) $row['opening_qty']
                : (is_numeric($row['opening_quantity'] ?? null) ? (float) $row['opening_quantity'] : 0.0);

            $payload = [
                'sku' => $sku,
                'name' => $name,
                'unit' => ($row['unit'] ?? '') !== '' ? $row['unit'] : 'pcs',
                'cost_price' => is_numeric($row['cost_price'] ?? null) ? (float) $row['cost_price'] : 0.0,
                'selling_price' => is_numeric($row['selling_price'] ?? null) ? (float) $row['selling_price'] : 0.0,
                'tax_rate' => is_numeric($row['tax_rate'] ?? null) ? (float) $row['tax_rate'] : 7.00,
                'opening_quantity' => max(0.0, $openingQty),
                'barcodes' => $barcode !== '' ? [$barcode] : [],
            ];

            if ($dryRun) {
                $this->line("[DRY-RUN] Row {$rowNumber}: SKU={$sku}, Barcode=" . ($barcode !== '' ? $barcode : '(none)') . ", Qty={$payload['opening_quantity']}");
                $imported++;
                continue;
            }

            $catalogService->createProduct($organizationId, $payload, $branchId !== '' ? $branchId : null);
            $this->info("Row {$rowNumber}: Imported SKU={$sku}");
            $imported++;
        }

        $this->newLine();
        $this->info("Summary: imported={$imported}, skipped={$skipped}, dry_run=" . ($dryRun ? 'yes' : 'no'));
        return self::SUCCESS;
    }
}