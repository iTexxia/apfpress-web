<?php

namespace App\Http\Controllers;

use App\Models\ContactInquiry;
use App\Models\SiteSetting;
use App\Notifications\ContactInquiryNotification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Throwable;

class ContactController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email:rfc', 'max:255'],
            'subject' => ['required', 'string', 'max:160'],
            'message' => ['required', 'string', 'max:5000'],
            'website' => ['nullable', 'max:0'],
        ]);
        unset($data['website']);

        $recipient = SiteSetting::contactRecipient();
        $inquiry = ContactInquiry::query()->create($data + [
            'status' => 'new',
            'notification_recipient' => $recipient,
        ]);

        try {
            Notification::route('mail', $recipient)->notifyNow(new ContactInquiryNotification($inquiry));
            $inquiry->update(['notified_at' => now(), 'notification_error' => null]);
        } catch (Throwable $exception) {
            $inquiry->update(['notification_error' => Str::limit($exception->getMessage(), 2000)]);
            report($exception);
        }

        return back()->with('success', 'Thank you. Your message has been received by our editorial team.');
    }
}
