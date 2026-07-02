<?php

declare(strict_types=1);

namespace App\Models;

use CodeIgniter\Model;

class InventoryQuantitySyncJobModel extends Model
{
    protected $table            = 'inventory_qty_sync_jobs';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $protectFields    = true;
    protected $allowedFields    = [
        'user_id',
        'activity_log_id',
        'status',
        'sheet_name',
        'progress_message',
        'result',
        'errors',
        'started_at',
        'finished_at',
    ];
    protected bool $allowEmptyInserts = false;
    protected bool $updateOnlyChanged = false;
    protected $useTimestamps         = true;
    protected array $casts            = [
        'result' => '?json',
        'errors' => '?json',
    ];

    public function getActive(): ?array
    {
        return $this->whereIn('status', ['queued', 'running'])
            ->orderBy('id', 'DESC')
            ->first();
    }

    public function getRunning(): ?array
    {
        $this->builder = null;

        return $this->where('status', 'running')
            ->orderBy('id', 'ASC')
            ->first();
    }

    public function getOldestQueued(): ?array
    {
        $this->builder = null;

        return $this->where('status', 'queued')
            ->orderBy('id', 'ASC')
            ->first();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function findStaleRunning(string $updatedBefore): array
    {
        $this->builder = null;

        return $this->where('status', 'running')
            ->where('updated_at <', $updatedBefore)
            ->findAll();
    }
}
