<?php

namespace App\Http\Controllers;

use App\Services\Storage\FilesystemUsageService;
use App\Services\Storage\MountService;
use App\Services\Storage\StorageOverviewService;
use Illuminate\View\View;

class StorageController extends Controller
{
    public function index(StorageOverviewService $overview, FilesystemUsageService $usage, MountService $mounts): View
    {
        return view('storage.index', ['sections' => $overview->sections(), 'storage' => $overview->devices(), 'usage' => $usage->usage(), 'activeMounts' => $mounts->active(), 'configuredMounts' => $mounts->configured()]);
    }
}
