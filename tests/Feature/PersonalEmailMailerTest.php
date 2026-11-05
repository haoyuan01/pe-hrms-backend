<?php

namespace Tests\Feature;

use App\Mail\ForgotPasswordMail;
use App\Mail\LeaveApplicationMail;
use App\Models\User;
use App\Models\UserContact;
use App\Services\PersonalEmailMailer;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class PersonalEmailMailerTest extends TestCase
{
    private function recipient(?string $email, bool $hasContact = true): User
    {
        $user = new User(['email' => 'login@example.com']);
        $user->setRelation('contact', $hasContact ? new UserContact(['personal_email' => $email]) : null);

        return $user;
    }

    public function test_notifications_skip_missing_and_invalid_personal_addresses_without_login_fallback(): void
    {
        Mail::fake();

        foreach ([null, '', '  ', 'invalid', 'user@', 'one@example.com,two@example.com', "one@example.com\r\nBcc: two@example.com"] as $email) {
            $this->assertFalse(PersonalEmailMailer::send($this->recipient($email), new LeaveApplicationMail(['subject' => 'Test notification'])));
        }
        $this->assertFalse(PersonalEmailMailer::send($this->recipient(null, false), new LeaveApplicationMail(['subject' => 'Test notification'])));
        $this->assertFalse(PersonalEmailMailer::send(null, new LeaveApplicationMail(['subject' => 'Test notification'])));

        Mail::assertNothingSent();
    }

    public function test_valid_personal_addresses_are_trimmed_and_cc_is_validated_and_deduplicated(): void
    {
        Mail::fake();

        $this->assertTrue(PersonalEmailMailer::send(
            $this->recipient(' applicant@example.com '),
            new LeaveApplicationMail(['subject' => 'Test notification']),
            collect([
                $this->recipient(' accountant@example.com '),
                $this->recipient('ACCOUNTANT@example.com'),
                $this->recipient('applicant@example.com'),
                $this->recipient('bad-address'),
                $this->recipient(null, false),
            ])
        ));

        Mail::assertSent(LeaveApplicationMail::class, function ($mail) {
            return $mail->hasTo('applicant@example.com')
                && ! $mail->hasTo('login@example.com')
                && count($mail->cc) === 1
                && $mail->hasCc('ACCOUNTANT@example.com');
        });
        Mail::assertSentCount(1);
    }

    public function test_missing_primary_recipient_does_not_send_to_cc_only(): void
    {
        Mail::fake();

        $this->assertFalse(PersonalEmailMailer::send(
            $this->recipient(null), new LeaveApplicationMail(['subject' => 'Test notification']), [$this->recipient('accountant@example.com')]
        ));

        Mail::assertNothingSent();
    }

    public function test_reset_email_requires_valid_personal_contact(): void
    {
        Mail::fake();

        try {
            PersonalEmailMailer::send($this->recipient(null), new ForgotPasswordMail([]), required: true);
            $this->fail('Expected a personal email validation error.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('personal_email', $exception->errors());
        }

        Mail::assertNothingSent();
    }

    public function test_valid_address_can_be_sent_through_the_actual_mailer(): void
    {
        config(['mail.default' => 'array', 'mail.from.address' => 'portal@example.com']);

        $mail = new class extends \Illuminate\Mail\Mailable {
            public function build()
            {
                return $this->subject('Test')->html('<p>Test notification</p>');
            }
        };

        $this->assertTrue(PersonalEmailMailer::send($this->recipient(' person@example.com '), $mail));
        $message = app('mailer')->getSymfonyTransport()->messages()->last()->getOriginalMessage();
        $this->assertSame('person@example.com', $message->getTo()[0]->getAddress());
    }
}
