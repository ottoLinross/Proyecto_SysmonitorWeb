<?php

namespace App\Http\Controllers;

use App\Services\Storage\FilesystemUsageService;
use App\Services\Storage\StorageOverviewService;
use Illuminate\View\View;

class StorageController extends Controller
{
    public function index(StorageOverviewService $overview, FilesystemUsageService $usage): View
    {
        return view('storage.index', ['sections' => $overview->sections(), 'storage' => $overview->devices(), 'usage' => $usage->usage()]);
    }
}
