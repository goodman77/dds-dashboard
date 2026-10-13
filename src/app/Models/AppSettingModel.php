<?php

declare(strict_types=1);

namespace App\Models;

use CodeIgniter\Model;

class AppSettingModel extends Model
{
    protected $table         = 'app_settings';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $allowedFields = ['setting_key', 'setting_value', 'updated_at'];
    protected $useTimestamps = false;

    public function getValue(string $key, ?string $default = null): ?string
    {
        $row = $this->where('setting_key', $key)->first();

        if ($row === null) {
            return $default;
        }

        $value = $row['setting_value'] ?? null;

        return $value === null || $value === '' ? $default : (string) $value;
    }

    public function setValue(string $key, string $value): bool
    {
        $existing = $this->where('setting_key', $key)->first();
        $payload  = [
            'setting_key'   => $key,
            'setting_value' => $value,
            'updated_at'    => date('Y-m-d H:i:s'),
        ];

        if ($existing === null) {
            return $this->insert($payload) !== false;
        }

        return $this->update((int) $existing['id'], $payload);
    }
}
