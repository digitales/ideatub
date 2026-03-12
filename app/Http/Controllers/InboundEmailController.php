<?php

namespace App\Http\Controllers;

use App\Models\UserInboundAddress;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class InboundEmailController extends Controller
{
    public function index(Request $request): View
    {
        $this->authorize('viewAny', UserInboundAddress::class);

        $user = $request->user();
        $primaryEmail = $user->email ?? '';
        $inboundAddresses = $user->userInboundAddresses()->orderBy('created_at')->get();
        $captureAddress = config('services.postmark_inbound.capture_address', '');

        return view('settings.inbound-emails', [
            'primaryEmail' => $primaryEmail,
            'inboundAddresses' => $inboundAddresses,
            'captureAddress' => $captureAddress,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', UserInboundAddress::class);

        $validated = $request->validate([
            'email' => 'required|email|max:255',
        ]);
        $email = mb_strtolower(trim($validated['email']));

        if (UserInboundAddress::query()->where('email', $email)->exists()) {
            return redirect()
                ->route('settings.inbound-emails.index')
                ->withInput()
                ->with('error', 'That email address is already registered for capture by another account.');
        }

        $request->user()->userInboundAddresses()->create(['email' => $email]);

        return redirect()
            ->route('settings.inbound-emails.index')
            ->with('success', 'Inbound email address added.');
    }

    public function destroy(Request $request, UserInboundAddress $userInboundAddress): RedirectResponse
    {
        $this->authorize('delete', $userInboundAddress);

        $userInboundAddress->delete();

        return redirect()
            ->route('settings.inbound-emails.index')
            ->with('success', 'Inbound email address removed.');
    }
}
