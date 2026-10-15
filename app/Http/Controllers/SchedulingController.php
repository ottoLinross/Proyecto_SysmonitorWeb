<?php

namespace App\Http\Controllers;

use Illuminate\View\View;

class SchedulingController extends Controller
{
    public function index(): View
    {
        return view('scheduling.index');
    }
}
