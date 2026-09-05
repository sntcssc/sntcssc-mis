<?php

namespace App\Http\Middleware;

use Illuminate\Foundation\Http\Middleware\TrimStrings as BaseTrimStrings;

class TrimStrings extends BaseTrimStrings
{
    /**
     * Transform the given value, preserving WebRTC session descriptions.
     *
     * SDP text MUST keep its trailing CRLF: trimming it produces an SDP whose
     * final line is not line-terminated, which Chrome's parser rejects with
     * "Failed to parse SessionDescription ... Invalid SDP line" and silently
     * breaks audio/video negotiation. This affects both HTTP signaling endpoints
     * and Livewire update requests used by 1-on-1 calls, so the check is
     * content-based rather than path-based.
     *
     * @param  string  $key
     * @param  mixed  $value
     * @return mixed
     */
    protected function transform($key, $value)
    {
        if (is_string($value) && str_starts_with($value, 'v=0')) {
            return $value;
        }

        return parent::transform($key, $value);
    }
}
