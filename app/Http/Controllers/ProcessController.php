<?php

namespace App\Http\Controllers;

use Illuminate\View\View;

class ProcessController extends Controller
{
    public function index(): View
    {
        return view('processes.index');
    }
}
