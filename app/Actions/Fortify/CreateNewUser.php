<?php

namespace App\Actions\Fortify;

use App\Actions\Teams\CreateTeam;
use App\Concerns\PasswordValidationRules;
use App\Concerns\ProfileValidationRules;
use App\Models\OtpCode;
use App\Models\User;
use App\Services\EmailService;
use App\Services\OtpService;
use App\Services\SmsService;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\CreatesNewUsers;

class CreateNewUser implements CreatesNewUsers
{
    use PasswordValidationRules, ProfileValidationRules;

    public function __construct(
        private CreateTeam $createTeam,
        private OtpService $otpService,
        private SmsService $smsService
    ) {
        //
    }

    /**
     * Validate and create a newly registered user.
     *
     * @param  array<string, string>  $input
     */
    public function create(array $input): User
    {
        if (! empty($input['phone'])) {
            $input['phone'] = $this->smsService->normalizePhoneNumber($input['phone']);
        }

        Validator::make($input, [
            ...$this->profileRules(),
            'password' => $this->passwordRules(),
        ])->validate();

        try {
            return DB::transaction(function () use ($input) {
                $phone = ! empty($input['phone'])
                    ? $input['phone']
                    : null;

                $user = User::create([
                    'name' => $input['name'],
                    'email' => strtolower(trim($input['email'])),
                    'phone' => $phone,
                    'password' => $input['password'],
                ]);

                $this->createTeam->handle($user, $user->name."'s Team", isPersonal: true);

                // Trigger registration verification OTPs / notifications based on settings
                if (EmailService::isEnabled()) {
                    if (EmailService::isOtpVerification()) {
                        $this->otpService->generateAndSend(
                            identifier: $user->email,
                            type: OtpCode::TYPE_REGISTRATION_EMAIL,
                            user: $user
                        );
                    } else {
                        $user->sendEmailVerificationNotification();
                    }
                }

                if ($phone && SmsService::isEnabled()) {
                    $this->otpService->generateAndSend(
                        identifier: $phone,
                        type: OtpCode::TYPE_REGISTRATION_SMS,
                        user: $user
                    );
                }

                return $user;
            });
        } catch (UniqueConstraintViolationException|QueryException $e) {
            $msg = $e->getMessage();
            if (str_contains($msg, 'phone') || str_contains($msg, 'users_phone_unique')) {
                throw ValidationException::withMessages([
                    'phone' => [__('This phone number is already registered in the system.')],
                ]);
            }
            if (str_contains($msg, 'email') || str_contains($msg, 'users_email_unique')) {
                throw ValidationException::withMessages([
                    'email' => [__('This email address is already registered in the system.')],
                ]);
            }

            throw ValidationException::withMessages([
                'email' => [__('A user with this information already exists.')],
            ]);
        }
    }
}
