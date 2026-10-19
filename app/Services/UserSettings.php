<?php

namespace App\Services;

class UserSettings
{
    public const FIELDS = ['custom_instructions', 'memory_enabled', 'theme', 'nickname', 'occupation', 'about_you', 'personalization_enabled', 'response_style', 'response_length', 'response_language', 'accent_color', 'text_size', 'reduced_motion', 'send_on_enter', 'completion_notifications'];

    public static function rules(): array
    {
        return ['custom_instructions' => 'nullable|string|max:8000', 'memory_enabled' => 'sometimes|boolean', 'theme' => 'sometimes|in:dark,light,system', 'nickname' => 'nullable|string|max:80', 'occupation' => 'nullable|string|max:160', 'about_you' => 'nullable|string|max:4000', 'personalization_enabled' => 'sometimes|boolean', 'response_style' => 'sometimes|in:default,professional,friendly,candid,concise', 'response_length' => 'sometimes|in:default,short,detailed', 'response_language' => 'sometimes|in:auto,English,Bangla,Russian,Arabic,Hindi,Spanish,French,German', 'accent_color' => 'sometimes|in:default,blue,purple,green', 'text_size' => 'sometimes|in:small,default,large', 'reduced_motion' => 'sometimes|boolean', 'send_on_enter' => 'sometimes|boolean', 'completion_notifications' => 'sometimes|boolean'];
    }
}
