<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class DashboardController extends Controller
{
    /**
     * Display the user dashboard.
     */
    public function index()
    {
        $user = auth()->user();
        
        $subscriptionStatus = 'free';
        if ($user->lifetime_access) {
            $subscriptionStatus = 'lifetime';
        } elseif ($user->subscribed('default')) {
            $subscriptionStatus = 'pro';
        }

        $operationsRemaining = $user->operationsRemainingToday();
        $operationsToday = $user->operations()
            ->whereDate('created_at', today())
            ->count();

        return view('dashboard', [
            'subscriptionStatus' => $subscriptionStatus,
            'operationsRemaining' => $operationsRemaining,
            'operationsToday' => $operationsToday,
        ]);
    }
}
