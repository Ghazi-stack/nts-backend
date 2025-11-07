<?php

namespace App\Jobs;

use App\Imports\ProductImport;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Maatwebsite\Excel\Facades\Excel;

class ProcessProductImport implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $filePath;
    protected $storeId;
    protected $columnMap;

    public function __construct($filePath, $storeId, $columnMap = [])
    {
        $this->filePath = $filePath;
        $this->storeId = $storeId;
        $this->columnMap = $columnMap;
    }

    public function handle()
    {
        Excel::import(new ProductImport($this->storeId, $this->columnMap), $this->filePath);
    }
}
