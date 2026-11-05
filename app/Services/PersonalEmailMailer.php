<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

class PersonalEmailMailer
{
    /**
     * Send only to valid personal contact addresses. Never fall back to login email.
     * Optional notifications are skipped when the primary recipient is unavailable.
     */
    public static function send(?User $user, Mailable $mail, iterable $cc = [], bool $required = false): bool
    {
        $to = self::address($user);

        if ($to === null) {
            if ($required) {
                throw ValidationException::withMessages([
                    'personal_email' => 'A valid personal email address is required in your contact details before this email can be sent.',
                ]);
            }

            Log::warning('Email notification skipped: missing or invalid personal email.', [
                'user_id' => $user?->id,
                'mailable' => $mail::class,
            ]);

            return false;
        }

        $addresses = [];
        foreach ($cc as $recipient) {
            $address = self::address($recipient);
            if ($address !== null && strcasecmp($address, $to) !== 0) {
                $addresses[strtolower($address)] = $address;
            }
        }

        Mail::to($to)->cc(array_values($addresses))->send($mail);

        return true;
    }

    private static function address(?User $user): ?string
    {
        $email = $user?->contact?->personal_email;
        if (! is_string($email)) {
            return null;
        }

        $email = trim($email);

        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false ? $email : null;
    }
}
