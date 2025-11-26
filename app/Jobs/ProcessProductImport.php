<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use App\Imports\ProductImport;
use Maatwebsite\Excel\Facades\Excel;
use Illuminate\Support\Facades\Cache;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

class ProcessProductImport implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $filePath;
    protected $storeId;
    protected $columnMap;
    protected $progressKey;

    public function __construct($filePath, $storeId, $columnMap, $progressKey)
    {
        $this->filePath = $filePath;
        $this->storeId = $storeId;
        $this->columnMap = $columnMap;
        $this->progressKey = $progressKey;
    }

    public function handle()
    {
        // Initialize the importer
        $importer = new ProductImport($this->storeId, $this->columnMap, $this->filePath, $this->progressKey);
    
        // Calculate total rows
        $totalRows = $importer->getExcelRowCount($this->filePath);
    
        // Initialize progress in cache
        if ($this->progressKey) {
            Cache::put($this->progressKey, [
                'current' => 0,
                'total' => $totalRows,
                'status' => 'processing',
            ], 3600); // 1 hour expiration
        }
    
        // Start the import
        Excel::import($importer, $this->filePath);
    }
}