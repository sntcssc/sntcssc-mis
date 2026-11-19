<?php

namespace App\Services\Captcha;

class CaptchaAudioGenerator
{
    /**
     * Generate standard 16-bit PCM Mono WAV binary data for an acoustic CAPTCHA speech readout.
     *
     * @param  string  $text  The text or mathematical question to synthesize
     * @param  int  $sampleRate  Audio sampling rate in Hz (default 16,000 Hz)
     * @return string Raw WAV binary stream
     */
    public static function generateWav(string $text, int $sampleRate = 16000): string
    {
        $sanitizedText = mb_substr(trim($text), 0, 80);
        $audioDir = resource_path('audio/captcha/en');

        // Check if pre-rendered human voice assets are available
        if (is_dir($audioDir) && file_exists("{$audioDir}/0.wav")) {
            return self::generateFromVoiceClips($sanitizedText, $audioDir, $sampleRate);
        }

        // Fallback: Pure acoustic harmonic synthesizer
        return self::generateHarmonicTones($sanitizedText, $sampleRate);
    }

    /**
     * Concatenate pre-rendered studio human voice clips for crisp, understandable speech.
     */
    protected static function generateFromVoiceClips(string $text, string $audioDir, int $sampleRate = 16000): string
    {
        // Tokenize by spaces and punctuation, but preserve math operators
        $normalized = str_replace(['+', '-', '=', '?'], [' plus ', ' minus ', ' equals ', ' question '], $text);
        $rawTokens = preg_split('/[\s,\-_]+/', trim($normalized));

        $silenceDuration = 0.16; // 160ms between spoken words/characters
        $silenceBytes = str_repeat("\x00\x00", (int) ($sampleRate * $silenceDuration));

        $combinedPcm = '';

        // Prepend gentle chime or intro voice if available
        $introPath = "{$audioDir}/intro.wav";
        if (file_exists($introPath)) {
            $introPcm = self::extractPcmFromWav(file_get_contents($introPath));
            if ($introPcm !== '') {
                $combinedPcm .= $introPcm.$silenceBytes.$silenceBytes;
            }
        }

        foreach ($rawTokens as $rawToken) {
            $token = strtolower(trim($rawToken));
            if ($token === '') {
                continue;
            }

            // If token is a single multi-character string of letters/digits (e.g. "7B9KX"), split into characters
            if (strlen($token) > 1 && ctype_alnum($token) && ! in_array($token, ['plus', 'minus', 'equals', 'what', 'is', 'question', 'ten', 'eleven', 'twelve', 'thirteen', 'fourteen', 'fifteen', 'sixteen', 'seventeen', 'eighteen', 'nineteen', 'twenty'], true) && ! is_numeric($token)) {
                $subTokens = str_split($token);
            } else {
                $subTokens = [$token];
            }

            foreach ($subTokens as $sub) {
                $sub = strtolower(trim($sub));
                $clipPath = "{$audioDir}/{$sub}.wav";

                if (file_exists($clipPath)) {
                    $pcm = self::extractPcmFromWav(file_get_contents($clipPath));
                    if ($pcm !== '') {
                        $combinedPcm .= $pcm.$silenceBytes;

                        continue;
                    }
                }

                // If clip missing, synthesize tone for this character
                $combinedPcm .= self::synthesizeTonePcm($sub, 0.28, $sampleRate).$silenceBytes;
            }
        }

        return self::assembleWavHeader($combinedPcm, $sampleRate);
    }

    /**
     * Extract raw 16-bit PCM audio bytes from a standard WAV file.
     */
    protected static function extractPcmFromWav(string $wav): string
    {
        $dataPos = strpos($wav, 'data');
        if ($dataPos === false) {
            return '';
        }

        $size = unpack('V', substr($wav, $dataPos + 4, 4))[1] ?? 0;
        if ($size <= 0) {
            return substr($wav, $dataPos + 8);
        }

        return substr($wav, $dataPos + 8, $size);
    }

    /**
     * Synthesize clean single/dual tone PCM bytes for a character fallback.
     */
    protected static function synthesizeTonePcm(string $char, float $duration, int $sampleRate): string
    {
        $freqMap = [
            '0' => 261.63, '1' => 293.66, '2' => 329.63, '3' => 349.23, '4' => 392.00,
            '5' => 440.00, '6' => 493.88, '7' => 523.25, '8' => 587.33, '9' => 659.25,
            'a' => 277.18, 'b' => 311.13, 'c' => 369.99, 'd' => 415.30, 'e' => 466.16,
            'f' => 554.37, 'g' => 622.25, 'h' => 698.46, 'i' => 739.99, 'j' => 783.99,
            'k' => 830.61, 'l' => 880.00, 'm' => 261.63, 'n' => 293.66, 'o' => 329.63,
            'p' => 349.23, 'q' => 392.00, 'r' => 440.00, 's' => 493.88, 't' => 523.25,
            'u' => 587.33, 'v' => 659.25, 'w' => 698.46, 'x' => 739.99, 'y' => 783.99,
            'z' => 830.61,
        ];

        $freq = $freqMap[strtolower($char)] ?? 440.00;
        $numSamples = (int) ($duration * $sampleRate);
        $attackSamples = (int) min(100, $numSamples * 0.12);
        $decaySamples = (int) min(140, $numSamples * 0.18);

        $pcm = '';
        for ($i = 0; $i < $numSamples; $i++) {
            $envelope = 1.0;
            if ($i < $attackSamples && $attackSamples > 0) {
                $envelope = $i / $attackSamples;
            } elseif ($i > $numSamples - $decaySamples && $decaySamples > 0) {
                $envelope = ($numSamples - $i) / $decaySamples;
            }

            $t = $i / $sampleRate;
            $wave = sin(2 * M_PI * $freq * $t) * 0.5 * $envelope;
            $val = max(-32767, min(32767, (int) round($wave * 32767)));
            $pcm .= pack('s', $val);
        }

        return $pcm;
    }

    /**
     * Assemble full RIFF WAVE binary file with 16-bit PCM Mono header.
     */
    protected static function assembleWavHeader(string $pcm, int $sampleRate = 16000): string
    {
        $dataSize = strlen($pcm);

        return 'RIFF'
            .pack('V', 36 + $dataSize)
            .'WAVE'
            .'fmt '
            .pack('V', 16)             // Subchunk1Size (16 for PCM)
            .pack('v', 1)              // AudioFormat (1 = PCM)
            .pack('v', 1)              // NumChannels (1 = Mono)
            .pack('V', $sampleRate)    // SampleRate
            .pack('V', $sampleRate * 2)// ByteRate (SampleRate * NumChannels * BitsPerSample/8)
            .pack('v', 2)              // BlockAlign (NumChannels * BitsPerSample/8)
            .pack('v', 16)             // BitsPerSample (16 bits)
            .'data'
            .pack('V', $dataSize)
            .$pcm;
    }

    /**
     * Fallback synthesizer when no voice assets are present.
     */
    protected static function generateHarmonicTones(string $text, int $sampleRate = 16000): string
    {
        $chars = str_split(strtoupper($text));
        $combinedPcm = '';
        $silenceDuration = 0.15;
        $silenceBytes = str_repeat("\x00\x00", (int) ($sampleRate * $silenceDuration));

        foreach ($chars as $ch) {
            if ($ch === ' ') {
                $combinedPcm .= $silenceBytes;

                continue;
            }
            $combinedPcm .= self::synthesizeTonePcm($ch, 0.30, $sampleRate).$silenceBytes;
        }

        return self::assembleWavHeader($combinedPcm, $sampleRate);
    }
}
