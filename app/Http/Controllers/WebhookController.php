<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Laravel\Cashier\Http\Controllers\WebhookController as CashierController;

class WebhookController extends CashierController
{
    /**
     * Handle checkout session completed.
     */
    protected function handleCheckoutSessionCompleted($payload)
    {
        $session = $payload['data']['object'];
        $customerId = $session['customer'];
        $metadata = $session['metadata'] ?? [];

        if (!$customerId) {
            return $this->successMethod();
        }

        $user = User::where('stripe_id', $customerId)->first();

        if (!$user) {
            return $this->successMethod();
        }

        // Handle lifetime purchase
        if (isset($metadata['plan_type']) && $metadata['plan_type'] === 'lifetime') {
            $user->update(['lifetime_access' => true]);
        }

        return $this->successMethod();
    }

    /**
     * Handle subscription deleted.
     */
    protected function handleCustomerSubscriptionDeleted($payload)
    {
        // Subscription cancelled - user loses Pro access
        // Lifetime access is not affected (handled by lifetime_access flag)
        return $this->successMethod();
    }
}
