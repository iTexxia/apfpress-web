<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SiteSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ContactSettingsController extends Controller
{
    public function show(): JsonResponse
    {
        return response()->json($this->payload());
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'recipient_email' => ['required', 'email:rfc', 'max:255'],
        ]);
        $before = SiteSetting::query()->where('key', 'contact_recipient_email')->value('value');

        $setting = SiteSetting::query()->updateOrCreate(
            ['key' => 'contact_recipient_email'],
            ['value' => $data['recipient_email'], 'updated_by' => $request->user()->id],
        );

        DB::table('audit_logs')->insert([
            'user_id' => $request->user()->id,
            'action' => 'contact.recipient_updated',
            'auditable_type' => SiteSetting::class,
            'auditable_id' => $setting->id,
            'before' => json_encode(['recipient_email' => $before], JSON_THROW_ON_ERROR),
            'after' => json_encode(['recipient_email' => $setting->value], JSON_THROW_ON_ERROR),
            'ip_address' => $request->ip(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json($this->payload());
    }

    private function payload(): array
    {
        return [
            'recipient_email' => SiteSetting::contactRecipient(),
            'default_recipient_email' => config('apf.support_email'),
            'from_email' => config('mail.from.address'),
        ];
    }
}
