<?php

namespace App\Http\Controllers;

use Illuminate\View\View;

class CpuMemoryController extends Controller
{
    public function index(): View
    {
        return view('system.cpu-memory');
    }
}
