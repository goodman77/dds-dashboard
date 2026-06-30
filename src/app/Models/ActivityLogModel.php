<?php

declare(strict_types=1);

namespace App\Models;

use CodeIgniter\Model;

class ActivityLogModel extends Model
{
    protected $table            = 'activity_logs';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $protectFields    = true;
    protected $allowedFields    = [
        'user_id',
        'action',
        'status',
        'message',
        'details',
        'reference_id',
        'created_at',
    ];
    protected bool $allowEmptyInserts = false;
    protected bool $updateOnlyChanged = false;
    protected $useTimestamps         = true;
    protected $createdField          = 'created_at';
    protected $updatedField          = '';
    protected array $casts            = [
        'details' => '?json',
    ];

    /**
     * @param array<string, mixed>|null $details
     */
    public function record(
        string $action,
        string $status,
        string $message,
        ?array $details = null,
        ?int $userId = null,
        ?int $referenceId = null,
    ): int {
        $this->insert([
            'user_id'      => $userId,
            'action'       => $action,
            'status'       => $status,
            'message'      => mb_substr($message, 0, 500),
            'details'      => $details,
            'reference_id' => $referenceId,
        ]);

        return (int) $this->getInsertID();
    }

    public function updateEntry(int $id, string $status, string $message, ?array $details = null): bool
    {
        return $this->update($id, [
            'status'  => $status,
            'message' => mb_substr($message, 0, 500),
            'details' => $details,
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function paginateLogs(?string $action, int $perPage = 50, string $group = 'logs'): array
    {
        $this->builder = null;

        if ($action !== null && $action !== '') {
            $this->where('action', $action);
        } else {
            $this->whereIn('action', array_keys($this->actionLabels()));
        }

        return $this->orderBy('created_at', 'DESC')
            ->orderBy('id', 'DESC')
            ->paginate($perPage, $group);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getRecent(int $limit = 5): array
    {
        $this->builder = null;

        return $this->whereIn('action', array_keys($this->actionLabels()))
            ->orderBy('created_at', 'DESC')
            ->orderBy('id', 'DESC')
            ->limit($limit)
            ->findAll();
    }

    /**
     * @return array<string, string>
     */
    public function actionLabels(): array
    {
        return [
            'inventory_import' => 'Inventory Import',
            'edit_qty'         => 'Edit',
        ];
    }
}
