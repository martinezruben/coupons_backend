<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\MailConfigurationService;
use Illuminate\Http\Request;

class MailTestController extends Controller
{
    public function __construct(protected MailConfigurationService $mailService) {}

    public function validateSmtp()
    {
        $this->authorize('system_settings.edit');

        return response()->json($this->mailService->validateSmtpConnection());
    }

    public function validateOffice365()
    {
        $this->authorize('system_settings.edit');

        return response()->json($this->mailService->validateOffice365Connection());
    }

    public function sendTest(Request $request)
    {
        $this->authorize('system_settings.edit');

        $validated = $request->validate([
            'to_address' => 'required|email',
        ]);

        return response()->json(
            $this->mailService->testEmail($validated['to_address'])
        );
    }

    protected function authorize(string $permission)
    {
        $user = auth('admin')->user();
        abort_unless($user, 403);
        abort_unless($user->can($permission), 403);
    }
}
