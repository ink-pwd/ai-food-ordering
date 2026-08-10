<?php

namespace App\Enums;

enum CatalogSyncStatus: string
{
    case Running = 'running';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
}
