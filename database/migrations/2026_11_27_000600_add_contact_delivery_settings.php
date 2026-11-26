<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('site_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->text('value')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::table('contact_inquiries', function (Blueprint $table) {
            $table->string('notification_recipient')->nullable()->after('status');
            $table->timestamp('notified_at')->nullable()->after('notification_recipient');
            $table->text('notification_error')->nullable()->after('notified_at');
        });
    }

    public function down(): void
    {
        Schema::table('contact_inquiries', function (Blueprint $table) {
            $table->dropColumn(['notification_recipient', 'notified_at', 'notification_error']);
        });

        Schema::dropIfExists('site_settings');
    }
};
