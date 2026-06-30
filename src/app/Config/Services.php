<?php

namespace Config;

use CodeIgniter\Config\BaseService;

/**
 * Services Configuration file.
 */
class Services extends BaseService
{
    public static function net32(bool $getShared = true): \App\Libraries\Net32\Net32
    {
        if ($getShared) {
            return static::getSharedInstance('net32');
        }

        $config = config('Net32');

        return new \App\Libraries\Net32\Net32(
            new \App\Libraries\Net32\Net32Client($config),
            $config,
        );
    }

    public static function googleSheets(bool $getShared = true): \App\Libraries\GoogleSheets\GoogleSheetsClient
    {
        if ($getShared) {
            return static::getSharedInstance('googleSheets');
        }

        return new \App\Libraries\GoogleSheets\GoogleSheetsClient(config('GoogleSheets'));
    }

    public static function inventoryImport(bool $getShared = true): \App\Services\InventoryImportService
    {
        if ($getShared) {
            return static::getSharedInstance('inventoryImport');
        }

        return new \App\Services\InventoryImportService(
            static::googleSheets(false),
            model(\App\Models\InventoryModel::class),
            static::net32(false)->products(),
            new \App\Services\InventorySheetParser(),
        );
    }

    public static function binLocationSync(bool $getShared = true): \App\Services\BinLocationSyncService
    {
        if ($getShared) {
            return static::getSharedInstance('binLocationSync');
        }

        return new \App\Services\BinLocationSyncService(
            static::inventoryImport(false),
        );
    }

    public static function inventoryImportJob(bool $getShared = true): \App\Services\InventoryImportJobService
    {
        if ($getShared) {
            return static::getSharedInstance('inventoryImportJob');
        }

        return new \App\Services\InventoryImportJobService(
            model(\App\Models\InventoryImportJobModel::class),
            static::inventoryImport(false),
        );
    }

    public static function inventoryQuantityCheck(bool $getShared = true): \App\Services\InventoryQuantityCheckService
    {
        if ($getShared) {
            return static::getSharedInstance('inventoryQuantityCheck');
        }

        return new \App\Services\InventoryQuantityCheckService(
            model(\App\Models\InventoryModel::class),
            static::net32(false)->products(),
        );
    }

    public static function activityLog(bool $getShared = true): \App\Services\ActivityLogService
    {
        if ($getShared) {
            return static::getSharedInstance('activityLog');
        }

        return new \App\Services\ActivityLogService(
            model(\App\Models\ActivityLogModel::class),
        );
    }

    public static function userManagement(bool $getShared = true): \App\Services\UserManagementService
    {
        if ($getShared) {
            return static::getSharedInstance('userManagement');
        }

        return new \App\Services\UserManagementService(
            model(\App\Models\UserModel::class),
        );
    }

    public static function userProfile(bool $getShared = true): \App\Services\UserProfileService
    {
        if ($getShared) {
            return static::getSharedInstance('userProfile');
        }

        return new \App\Services\UserProfileService(
            model(\App\Models\UserModel::class),
        );
    }
}
