<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\IntegrationSetting;
use App\Services\PaymentConfiguration;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class IntegrationController extends Controller
{
    public function index(PaymentConfiguration $configuration): JsonResponse
    {
        return response()->json(IntegrationSetting::query()->get()->map(
            fn (IntegrationSetting $setting) => $this->resource($setting, $configuration),
        ));
    }

    public function update(Request $request, string $provider, PaymentConfiguration $configuration): JsonResponse
    {
        abort_unless(in_array($provider, ['stripe', 'paypal'], true), 404);
        $data = $request->validate([
            'environment' => ['required', Rule::in(['sandbox', 'live'])], 'enabled' => ['required', 'boolean'],
            'credentials' => ['nullable', 'array'], 'credentials.*' => ['nullable', 'string', 'max:1000'],
        ]);
        $setting = IntegrationSetting::query()->firstOrCreate(['provider' => $provider]);
        $credentials = array_filter($data['credentials'] ?? [], fn ($value) => $value !== null && $value !== '');
        $effectiveCredentials = array_filter(array_merge(
            (array) config("services.$provider", []),
            (array) ($setting->credentials ?? []),
            $credentials,
        ), fn ($value) => $value !== null && $value !== '');
        $missing = $configuration->missingCredentials($provider, $effectiveCredentials);
        if ($data['enabled'] && $missing !== []) {
            throw ValidationException::withMessages([
                'credentials' => 'Add the required '.implode(', ', array_map(fn (string $key) => str_replace('_', ' ', $key), $missing)).' before enabling '.$provider.'.',
            ]);
        }
        $setting->update([
            'environment' => $data['environment'], 'enabled' => $data['enabled'],
            'credentials' => array_merge($setting->credentials ?? [], $credentials),
            'health_status' => 'untested', 'health_message' => null,
        ]);

        return response()->json($this->resource($setting->fresh(), $configuration) + ['saved' => true]);
    }

    private function resource(IntegrationSetting $setting, PaymentConfiguration $configuration): array
    {
        $effective = $configuration->get($setting->provider);
        $missing = $configuration->missingCredentials($setting->provider, $effective);

        return [
            'provider' => $setting->provider,
            'environment' => $setting->environment,
            'enabled' => $setting->enabled,
            'ready' => $setting->enabled && $missing === [],
            'configured' => $missing === [],
            'missing_credentials' => $missing,
            'health_status' => $setting->health_status,
            'health_message' => $setting->health_message,
            'last_tested_at' => $setting->last_tested_at,
        ];
    }
}
