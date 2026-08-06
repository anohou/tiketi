<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Validation\Rule;

class LocaleController extends Controller
{
    /**
     * Store the selected locale and apply it to the current request.
     */
    public function update(Request $request)
    {
        $validated = $request->validate([
            'locale' => ['required', Rule::in(['fr', 'en'])],
        ]);

        $locale = $validated['locale'];

        App::setLocale($locale);
        session(['locale' => $locale]);

        if ($user = $request->user()) {
            $settings = $user->settings ?? [];
            $settings['locale'] = $locale;
            $user->settings = $settings;
            $user->save();
        }

        return back();
    }
}
