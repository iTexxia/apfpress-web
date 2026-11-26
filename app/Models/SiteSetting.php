<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SiteSetting extends Model
{
    protected $guarded = [];

    public static function contactRecipient(): string
    {
        return (string) (static::query()->where('key', 'contact_recipient_email')->value('value')
            ?: config('apf.support_email'));
    }
}
