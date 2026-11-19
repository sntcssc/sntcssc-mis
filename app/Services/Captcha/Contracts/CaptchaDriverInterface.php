<?php

namespace App\Services\Captcha\Contracts;

use App\Services\Captcha\CaptchaResult;
use Illuminate\Http\Request;

interface CaptchaDriverInterface
{
    /**
     * Get the driver unique name identifier.
     */
    public function getName(): string;

    /**
     * Render the client HTML / Widget markup.
     */
    public function render(string $action = 'general', array $options = []): string;

    /**
     * Verify incoming verification response / tokens.
     */
    public function verify(Request $request, string $action = 'general', array $options = []): CaptchaResult;

    /**
     * Generate dynamic challenge payload (for custom captcha or decoupled API).
     */
    public function generateChallenge(array $options = []): array;
}
