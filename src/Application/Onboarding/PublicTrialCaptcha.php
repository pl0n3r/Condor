<?php

declare(strict_types=1);

namespace App\Application\Onboarding;

/** Fixed Turnstile endpoint: never fetch a caller-controlled URL. */
class PublicTrialCaptcha
{
    public function verify(string $response): bool
    {
        $secret = getenv('CONDOR_TRIAL_CAPTCHA_SECRET');
        $hostname = getenv('CONDOR_TRIAL_CAPTCHA_HOSTNAME');
        if (!is_string($secret) || strlen(trim($secret)) < 12
            || !is_string($hostname) || trim($hostname) === ''
            || strlen($response) < 10 || strlen($response) > 2048
        ) {
            return false;
        }

        $context = stream_context_create(['http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
            'content' => http_build_query(['secret' => trim($secret), 'response' => $response]),
            'timeout' => 4,
            'ignore_errors' => true,
            'follow_location' => 0,
        ]]);
        $body = @file_get_contents('https://challenges.cloudflare.com/turnstile/v0/siteverify', false, $context);
        if (!is_string($body) || strlen($body) > 8192) {
            return false;
        }
        $decoded = json_decode($body, true);

        return is_array($decoded)
            && ($decoded['success'] ?? null) === true
            && ($decoded['hostname'] ?? null) === trim($hostname)
            && ($decoded['action'] ?? null) === 'condor_trial';
    }
}
