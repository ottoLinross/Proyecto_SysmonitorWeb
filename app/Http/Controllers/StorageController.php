<?php

namespace App\Http\Controllers;

use App\Services\Storage\ActiveSessionsService;
use App\Services\Storage\FileExplorerService;
use App\Services\Storage\FilesystemUsageService;
use App\Services\Storage\MountService;
use App\Services\Storage\StorageOverviewService;
use App\Services\Storage\StorageSummaryService;
use App\Services\Storage\SystemUsersService;
use Illuminate\Http\Request;
use Illuminate\View\View;

class StorageController extends Controller
{
    public function index(StorageOverviewService $overview, FilesystemUsageService $usage, MountService $mounts, SystemUsersService $users, ActiveSessionsService $sessions, FileExplorerService $explorer, Request $request, StorageSummaryService $summary): View
    {
        $sources = [
            'devices' => $overview->devices(),
            'usage' => $usage->usage(),
            'mounts' => $mounts->active(),
            'configured' => $mounts->configured(),
            'users' => $users->users(),
            'sessions' => $sessions->sessions(),
            'explorer' => $explorer->browse($request->query('path', '')),
        ];

        return view('storage.index', [
            'sections' => $overview->sections(),
            'storage' => $sources['devices'],
            'usage' => $sources['usage'],
            'activeMounts' => $sources['mounts'],
            'configuredMounts' => $sources['configured'],
            'systemUsers' => $sources['users'],
            'activeSessions' => $sources['sessions'],
            'explorer' => $sources['explorer'],
            'summary' => $summary->summarize($sources),
        ]);
    }
}
