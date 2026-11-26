<?php

namespace Tests\Feature;

use App\Models\Cart;
use App\Models\CatalogItem;
use App\Models\DigitalAsset;
use App\Models\IntegrationSetting;
use App\Models\Inventory;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Stripe\WebhookSignature;
use Tests\TestCase;

class PaymentCheckoutTest extends TestCase
{
    use RefreshDatabase;

    public function test_checkout_only_displays_enabled_and_fully_configured_payment_providers(): void
    {
        [$user, $cart] = $this->digitalCart();
        IntegrationSetting::query()->create([
            'provider' => 'stripe', 'environment' => 'live', 'enabled' => true,
            'credentials' => ['secret' => 'sk_live_test', 'webhook_secret' => 'whsec_test'],
        ]);
        IntegrationSetting::query()->create([
            'provider' => 'paypal', 'environment' => 'live', 'enabled' => false,
            'credentials' => ['client_id' => 'client', 'client_secret' => 'secret', 'webhook_id' => 'hook'],
        ]);

        $this->actingAs($user)->withSession(['cart_id' => $cart->id])->get('/checkout')
            ->assertOk()
            ->assertSee('Credit or debit card')
            ->assertDontSee('Pay using your PayPal account');
    }

    public function test_paypal_checkout_creates_a_server_priced_order_and_redirects_to_approval(): void
    {
        Notification::fake();
        [$user, $cart] = $this->digitalCart();
        IntegrationSetting::query()->create([
            'provider' => 'paypal', 'environment' => 'sandbox', 'enabled' => true,
            'credentials' => ['client_id' => 'client', 'client_secret' => 'secret', 'webhook_id' => 'hook'],
        ]);
        Http::fake([
            'https://api-m.sandbox.paypal.com/v1/oauth2/token' => Http::response(['access_token' => 'access-token']),
            'https://api-m.sandbox.paypal.com/v2/checkout/orders' => Http::response([
                'id' => 'PAYPAL-CHECKOUT-1',
                'links' => [['rel' => 'payer-action', 'href' => 'https://paypal.example/approve']],
            ], 201),
        ]);

        $this->actingAs($user)->withSession(['cart_id' => $cart->id])->post('/checkout', $this->checkoutPayload('paypal'))
            ->assertRedirect('https://paypal.example/approve');

        $this->assertDatabaseHas('orders', [
            'cart_id' => $cart->id, 'user_id' => $user->id, 'subtotal_amount' => 2499,
            'shipping_amount' => 0, 'tax_amount' => 0, 'total_amount' => 2499,
            'payment_status' => 'unpaid', 'fulfillment_status' => 'not_required',
        ]);
        $this->assertDatabaseHas('payments', [
            'provider' => 'paypal', 'provider_checkout_id' => 'PAYPAL-CHECKOUT-1',
            'status' => 'pending', 'amount' => 2499, 'currency' => 'CAD',
        ]);
        Http::assertSentCount(2);

        $payment = Payment::query()->where('provider_checkout_id', 'PAYPAL-CHECKOUT-1')->firstOrFail();
        Http::fake([
            'https://api-m.sandbox.paypal.com/v1/oauth2/token' => Http::response(['access_token' => 'access-token']),
            'https://api-m.sandbox.paypal.com/v2/checkout/orders/PAYPAL-CHECKOUT-1/capture' => Http::response([
                'status' => 'COMPLETED',
                'purchase_units' => [['payments' => ['captures' => [[
                    'id' => 'PAYPAL-CAPTURE-1',
                    'amount' => ['value' => '24.99', 'currency_code' => 'CAD'],
                ]]]]],
            ]),
        ]);
        $returnUrl = URL::temporarySignedRoute('paypal.return', now()->addHour(), ['payment' => $payment]);

        $this->actingAs($user)->get($returnUrl)
            ->assertRedirect(route('checkout.success', $payment->order));

        $this->assertDatabaseHas('payments', [
            'id' => $payment->id, 'status' => 'succeeded', 'provider_payment_id' => 'PAYPAL-CAPTURE-1',
        ]);
        $this->assertDatabaseHas('orders', [
            'id' => $payment->order_id, 'status' => 'paid', 'payment_status' => 'paid',
        ]);
        $this->assertDatabaseHas('digital_entitlements', ['user_id' => $user->id]);
    }

    public function test_disabled_provider_is_rejected_before_an_order_is_created(): void
    {
        [$user, $cart] = $this->digitalCart();
        IntegrationSetting::query()->create([
            'provider' => 'paypal', 'environment' => 'live', 'enabled' => false,
            'credentials' => ['client_id' => 'client', 'client_secret' => 'secret', 'webhook_id' => 'hook'],
        ]);

        $this->actingAs($user)->withSession(['cart_id' => $cart->id])->post('/checkout', $this->checkoutPayload('paypal'))
            ->assertSessionHasErrors('provider');

        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_signed_stripe_webhook_marks_the_matching_payment_paid_once(): void
    {
        Notification::fake();
        [$user, $cart] = $this->digitalCart();
        IntegrationSetting::query()->create([
            'provider' => 'stripe', 'environment' => 'live', 'enabled' => true,
            'credentials' => ['secret' => 'sk_live_test', 'webhook_secret' => 'whsec_test'],
        ]);
        $offering = $cart->items()->firstOrFail()->offering;
        $order = Order::query()->create([
            'number' => 'APF-STRIPE-TEST', 'cart_id' => $cart->id, 'user_id' => $user->id,
            'status' => 'pending', 'payment_status' => 'unpaid', 'fulfillment_status' => 'not_required',
            'email' => $user->email, 'currency' => 'CAD', 'subtotal_amount' => 2499,
            'shipping_amount' => 0, 'tax_amount' => 0, 'total_amount' => 2499,
        ]);
        $order->items()->create([
            'offering_id' => $offering->id, 'sku' => $offering->sku,
            'name' => 'Payment Test Book — PDF edition', 'kind' => 'ebook',
            'quantity' => 1, 'unit_amount' => 2499, 'total_amount' => 2499,
        ]);
        $payment = $order->payments()->create([
            'provider' => 'stripe', 'provider_checkout_id' => 'cs_test_apf',
            'status' => 'pending', 'amount' => 2499, 'currency' => 'CAD',
        ]);
        $payload = json_encode([
            'id' => 'evt_apf_checkout',
            'object' => 'event',
            'type' => 'checkout.session.completed',
            'data' => ['object' => [
                'id' => 'cs_test_apf', 'object' => 'checkout.session',
                'amount_total' => 2499, 'payment_intent' => 'pi_test_apf',
                'metadata' => ['payment_id' => (string) $payment->id],
            ]],
        ], JSON_THROW_ON_ERROR);
        $signature = WebhookSignature::generateSignatureHeader($payload, 'whsec_test');

        $this->call('POST', '/payments/webhooks/stripe', [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_STRIPE_SIGNATURE' => $signature,
        ], $payload)->assertOk()->assertSee('Accepted');

        $this->assertDatabaseHas('payments', [
            'id' => $payment->id, 'status' => 'succeeded', 'provider_payment_id' => 'pi_test_apf',
        ]);
        $this->assertDatabaseHas('orders', [
            'id' => $order->id, 'status' => 'paid', 'payment_status' => 'paid',
        ]);
        $this->assertDatabaseHas('payment_events', [
            'provider' => 'stripe', 'provider_event_id' => 'evt_apf_checkout', 'status' => 'processed',
        ]);
        $this->assertDatabaseHas('digital_entitlements', ['user_id' => $user->id]);

        $this->call('POST', '/payments/webhooks/stripe', [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_STRIPE_SIGNATURE' => $signature,
        ], $payload)->assertOk()->assertSee('Already processed');
        $this->assertDatabaseCount('payment_events', 1);
    }

    public function test_admin_cannot_enable_an_incomplete_payment_provider(): void
    {
        $owner = User::factory()->create(['role' => 'owner', 'status' => 'active']);
        IntegrationSetting::query()->create(['provider' => 'stripe', 'environment' => 'live', 'enabled' => false]);

        $this->actingAs($owner)->putJson('/admin/api/integrations/stripe', [
            'environment' => 'live', 'enabled' => true, 'credentials' => ['secret' => 'sk_live_test'],
        ])->assertUnprocessable()->assertJsonValidationErrors('credentials');

        $this->assertFalse(IntegrationSetting::query()->where('provider', 'stripe')->firstOrFail()->enabled);
    }

    /** @return array{User, Cart} */
    private function digitalCart(): array
    {
        $user = User::factory()->create(['status' => 'active']);
        $item = CatalogItem::query()->create([
            'type' => 'book', 'slug' => 'payment-test-book', 'title' => 'Payment Test Book',
            'status' => 'published', 'published_at' => now(),
        ]);
        $offering = $item->offerings()->create([
            'kind' => 'ebook', 'name' => 'PDF edition', 'sku' => 'PAY-EB-001',
            'price_amount' => 2499, 'currency' => 'CAD', 'purchase_mode' => 'online',
            'tax_class' => 'books', 'active' => true, 'access_duration_days' => 365,
        ]);
        Inventory::query()->create([
            'offering_id' => $offering->id, 'on_hand' => 0, 'reserved' => 0,
            'track_inventory' => false, 'allow_backorder' => false,
        ]);
        DigitalAsset::query()->create([
            'offering_id' => $offering->id, 'path' => 'digital/payment-test.pdf',
            'file_name' => 'payment-test.pdf', 'mime_type' => 'application/pdf',
            'version' => 1, 'active' => true, 'is_current' => true,
        ]);
        $cart = Cart::query()->create([
            'user_id' => $user->id, 'status' => 'active', 'currency' => 'CAD', 'expires_at' => now()->addDay(),
        ]);
        $cart->items()->create(['offering_id' => $offering->id, 'quantity' => 1]);

        return [$user, $cart];
    }

    private function checkoutPayload(string $provider): array
    {
        return [
            'provider' => $provider,
            'name' => 'Reader Example',
            'company' => '',
            'line_1' => '4 Carnegie Court',
            'line_2' => '',
            'city' => 'Toronto',
            'region' => 'ON',
            'postal_code' => 'M2M 1W2',
            'country' => 'CA',
            'phone' => '',
            'note' => '',
        ];
    }
}
