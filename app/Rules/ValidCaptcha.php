<?php

namespace App\Rules;

use App\Services\Captcha\CaptchaService;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

class ValidCaptcha implements ValidationRule
{
    public function __construct(public string $action = 'general') {}

    /**
     * Run the validation rule.
     *
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! CaptchaService::isEnabled($this->action)) {
            return;
        }

        $result = CaptchaService::verifyRequest(request(), $this->action);

        if ($result->isFailure()) {
            $fail($result->message ?? __('Security verification failed. Please try again.'));
        }
    }
}
