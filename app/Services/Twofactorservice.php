<?php

namespace App\Services;

use App\Models\User;
use App\Mail\TwoFactorOtpMail;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;

class TwoFactorService
{
    protected Google2FA $google2fa;

    public function __construct()
    {
        $this->google2fa = new Google2FA();
    }

    // ─────────────────────────────────────────────────────────────
    //  EMAIL OTP
    // ─────────────────────────────────────────────────────────────

    public function sendEmailOtp(User $user): bool
    {
        $otp       = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $expiresAt = now()->addMinutes(10);

        $user->update([
            'two_factor_otp'            => bcrypt($otp),
            'two_factor_otp_expires_at' => $expiresAt,
        ]);

        Mail::to($user->email)->send(new TwoFactorOtpMail($otp, $user->name));

        return true;
    }

    public function verifyEmailOtp(User $user, string $otp): bool
    {
        if (!$user->two_factor_otp_expires_at || now()->isAfter($user->two_factor_otp_expires_at)) {
            return false;
        }

        if (!password_verify($otp, $user->two_factor_otp)) {
            return false;
        }

        $user->update([
            'two_factor_otp'            => null,
            'two_factor_otp_expires_at' => null,
        ]);

        return true;
    }

    // ─────────────────────────────────────────────────────────────
    //  AUTHENTICATOR APP (Google Authenticator / Authy)
    // ─────────────────────────────────────────────────────────────

    public function generateAuthenticatorSecret(User $user): array
    {
        $secret = $this->google2fa->generateSecretKey();

        $user->update([
            'two_factor_secret'    => encrypt($secret),
            'two_factor_confirmed' => false,
        ]);

        $qrCodeUrl = $this->google2fa->getQRCodeUrl(
            config('app.name'),
            $user->email,
            $secret
        );

        return [
            'secret'      => $secret,
            'qr_code_url' => $qrCodeUrl,
        ];
    }

    public function confirmAuthenticatorSetup(User $user, string $code): bool
    {
        if (!$user->two_factor_secret) {
            return false;
        }

        $secret = decrypt($user->two_factor_secret);
        $valid  = $this->google2fa->verifyKey($secret, $code);

        if ($valid) {
            $user->update(['two_factor_confirmed' => true]);
        }

        return $valid;
    }

    public function verifyAuthenticatorOtp(User $user, string $code): bool
    {
        if (!$user->two_factor_secret || !$user->two_factor_confirmed) {
            return false;
        }

        $secret = decrypt($user->two_factor_secret);

        return $this->google2fa->verifyKey($secret, $code);
    }

    // ─────────────────────────────────────────────────────────────
    //  PENDING LOGIN TOKEN (Cache)
    // ─────────────────────────────────────────────────────────────

    public function storePendingLogin(User $user): string
    {
        $token = Str::uuid()->toString();

        Cache::put(
            '2fa_pending_' . $token,
            ['user_id' => $user->id],
            now()->addMinutes(15)
        );

        return $token;
    }

    public function getUserFromPendingToken(string $token): ?User
    {
        $data = Cache::get('2fa_pending_' . $token);

        if (!$data) {
            return null;
        }

        return User::find($data['user_id']);
    }

    public function clearPendingToken(string $token): void
    {
        Cache::forget('2fa_pending_' . $token);
    }
}
