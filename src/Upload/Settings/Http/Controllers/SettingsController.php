<?php

namespace hexa_package_upload_portal\Upload\Settings\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\View\View;

class SettingsController extends Controller
{
    /**
     * Show settings page.
     *
     * @return View
     */
    public function index(): View
    {
        return view('upload-portal::settings.index');
    }

    /**
     * Save a setting.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function save(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'setting_key' => 'required|string|max:100',
            'setting_value' => 'nullable|string|max:5000',
        ]);

        if (class_exists(\hexa_core\Models\Setting::class)) {
            \hexa_core\Models\Setting::setValue($validated['setting_key'], $validated['setting_value']);
        }

        return response()->json(['success' => true, 'message' => 'Setting saved.']);
    }
}
