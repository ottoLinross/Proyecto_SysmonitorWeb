<?php

namespace App\Http\Controllers;

use App\Services\Storage\ActiveSessionsService;
use App\Services\Storage\FileExplorerService;
use App\Services\Storage\FilesystemUsageService;
use App\Services\Storage\MountService;
use App\Services\Storage\StorageOverviewService;
use App\Services\Storage\SystemUsersService;
use Illuminate\Http\Request;
use Illuminate\View\View;

class StorageController extends Controller
{
    public function index(StorageOverviewService $overview, FilesystemUsageService $usage, MountService $mounts, SystemUsersService $users, ActiveSessionsService $sessions, FileExplorerService $explorer, Request $request): View
    {
        return view('storage.index', ['explorer' => $explorer->browse($request->query('path', '')), 'activeSessions' => $sessions->sessions(), 'systemUsers' => $users->users(), 'sections' => $overview->sections(), 'storage' => $overview->devices(), 'usage' => $usage->usage(), 'activeMounts' => $mounts->active(), 'configuredMounts' => $mounts->configured()]);
    }
}
