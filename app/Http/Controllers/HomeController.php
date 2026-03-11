<?php

namespace App\Http\Controllers;

use Illuminate\Contracts\View\View;

class HomeController extends Controller
{
    /**
     * Display the IdeaTub homepage.
     */
    public function index(): View
    {
        return view('home');
    }
}
