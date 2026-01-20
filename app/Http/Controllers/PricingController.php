<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Laravel\Cashier\Cashier;

class PricingController extends Controller
{
    /**
     * Display the pricing page.
     */
    public function index()
    {
        return view('pricing');
    }

    /**
     * Initiate Stripe checkout for Pro subscription.
     */
    public function checkoutPro(Request $request)
    {
        return $request->user()
            ->checkout([config('cashier.price_monthly')], [
                'success_url' => route('dashboard') . '?checkout=success',
                'cancel_url' => route('pricing') . '?checkout=cancelled',
                'metadata' => [
                    'plan_type' => 'pro',
                ],
            ]);
    }

    /**
     * Initiate Stripe checkout for Lifetime purchase.
     */
    public function checkoutLifetime(Request $request)
    {
        return $request->user()
            ->checkout([config('cashier.price_lifetime')], [
                'success_url' => route('dashboard') . '?checkout=success',
                'cancel_url' => route('pricing') . '?checkout=cancelled',
                'metadata' => [
                    'plan_type' => 'lifetime',
                ],
            ]);
    }
}
