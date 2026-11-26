<?php

namespace App\Services;

use App\Models\IntegrationSetting;

class PaymentConfiguration
{
    private const REQUIRED_CREDENTIALS = [
        'stripe' => ['secret', 'webhook_secret'],
        'paypal' => ['client_id', 'client_secret', 'webhook_id'],
    ];

    public function get(string $provider): array
    {
        $setting = IntegrationSetting::query()->where('provider', $provider)->first();
        $environment = $setting?->environment ?? config("services.$provider.environment", 'sandbox');
        $credentials = array_filter(array_merge(
            (array) config("services.$provider", []),
            (array) ($setting?->credentials ?? []),
        ), fn ($value) => $value !== null && $value !== '');

        return $credentials + [
            'enabled' => $setting ? $setting->enabled : (bool) ($credentials['secret'] ?? $credentials['client_secret'] ?? false),
            'environment' => $environment,
        ];
    }

    /** @return string[] */
    public function missingCredentials(string $provider, ?array $credentials = null): array
    {
        $credentials ??= $this->get($provider);

        return array_values(array_filter(
            self::REQUIRED_CREDENTIALS[$provider] ?? ['unsupported_provider'],
            fn (string $key) => empty($credentials[$key]),
        ));
    }

    public function isReady(string $provider): bool
    {
        $configuration = $this->get($provider);

        return (bool) ($configuration['enabled'] ?? false)
            && $this->missingCredentials($provider, $configuration) === [];
    }

    /** @return string[] */
    public function availableProviders(): array
    {
        return array_values(array_filter(['stripe', 'paypal'], fn (string $provider) => $this->isReady($provider)));
    }
}
