<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Maatwebsite\Excel\Facades\Excel;
use App\Imports\ProductImport;

class ImportProductsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'import:products 
        {storeId}
        {progressKey}
        {filePath}
        {ignoredFields?}
        {offset=0}
        {limit=500}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Import products in background without queues';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $storeId       = $this->argument('storeId');
        $progressKey   = $this->argument('progressKey');
        $filePath      = $this->argument('filePath');
        $ignoredFields = $this->argument('ignoredFields');
        $offset        = (int) $this->argument('offset');
        $limit         = (int) $this->argument('limit');

        $ignoredFields = $ignoredFields
            ? json_decode($ignoredFields, true)
            : [];

        Excel::import(
            new ProductImport(
                $storeId,
                $progressKey,
                $filePath,
                $ignoredFields,
                $offset,
                $limit
            ),
            $filePath
        );

        return Command::SUCCESS;
    }
}
