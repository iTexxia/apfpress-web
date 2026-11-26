<?php

namespace Tests\Feature;

use App\Models\ContactInquiry;
use App\Models\SiteSetting;
use App\Models\User;
use App\Notifications\ContactInquiryNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class ContactFormTest extends TestCase
{
    use RefreshDatabase;

    public function test_contact_form_stores_and_emails_the_inquiry_to_the_configured_recipient(): void
    {
        Notification::fake();
        SiteSetting::query()->create(['key' => 'contact_recipient_email', 'value' => 'editorial@example.com']);

        $this->from('/contact')->post('/contact', [
            'name' => 'Reader Example',
            'email' => 'reader@example.com',
            'subject' => 'Course adoption',
            'message' => 'Could you provide an inspection copy?',
            'website' => '',
        ])->assertRedirect('/contact')->assertSessionHas('success');

        $inquiry = ContactInquiry::query()->sole();
        $this->assertSame('editorial@example.com', $inquiry->notification_recipient);
        $this->assertNotNull($inquiry->notified_at);
        $this->assertNull($inquiry->notification_error);

        Notification::assertSentOnDemand(
            ContactInquiryNotification::class,
            fn (ContactInquiryNotification $notification, array $channels, object $notifiable) =>
                $notifiable->routes['mail'] === 'editorial@example.com'
                && $notification->inquiry->is($inquiry),
        );
    }

    public function test_contact_form_validates_fields_and_rejects_the_honeypot(): void
    {
        Notification::fake();

        $this->post('/contact', [
            'name' => '', 'email' => 'invalid', 'subject' => '', 'message' => '', 'website' => 'bot.example',
        ])->assertSessionHasErrors(['name', 'email', 'subject', 'message', 'website']);

        $this->assertDatabaseCount('contact_inquiries', 0);
        Notification::assertNothingSent();
    }

    public function test_only_an_owner_can_change_contact_routing(): void
    {
        $owner = User::factory()->create(['role' => 'owner', 'status' => 'active']);
        $editor = User::factory()->create(['role' => 'editor', 'status' => 'active']);

        $this->actingAs($editor)->putJson('/admin/api/contact-settings', [
            'recipient_email' => 'editorial@example.com',
        ])->assertForbidden();

        $this->actingAs($owner)->putJson('/admin/api/contact-settings', [
            'recipient_email' => 'inquiries@example.com',
        ])->assertOk()->assertJsonPath('recipient_email', 'inquiries@example.com');

        $this->assertDatabaseHas('site_settings', [
            'key' => 'contact_recipient_email', 'value' => 'inquiries@example.com', 'updated_by' => $owner->id,
        ]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'contact.recipient_updated', 'user_id' => $owner->id]);
    }
}
