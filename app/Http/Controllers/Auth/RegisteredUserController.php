<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\RegistrationGate;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules;
use Illuminate\View\View;

class RegisteredUserController extends Controller
{
    /**
     * Show the registration form.
     */
    public function create(): View|RedirectResponse
    {
        if (! RegistrationGate::isOpen()) {
            return redirect()
                ->route('login')
                ->with('error', 'Registration is currently closed.');
        }

        return view('auth.register', [
            'betaCodeRequired' => RegistrationGate::requiresBetaCode(),
        ]);
    }

    /**
     * Handle an incoming registration request.
     */
    public function store(Request $request): RedirectResponse
    {
        if (! RegistrationGate::isOpen()) {
            return redirect()
                ->route('login')
                ->with('error', 'Registration is currently closed.');
        }

        $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users'],
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
            'beta_code' => RegistrationGate::requiresBetaCode() ? ['required', 'string'] : ['nullable', 'string'],
        ]);

        RegistrationGate::assertBetaCode($request->input('beta_code'));

        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($request->password),
        ]);

        event(new Registered($user));

        Auth::login($user);

        return redirect(route('idea.index'));
    }
}
