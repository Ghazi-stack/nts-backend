<?php

namespace App\Http\Controllers\api;


use App\Http\Controllers\Controller;
use App\Models\Store;
use App\Http\Resources\StoreResource;
use App\Http\Resources\UserResource;
use App\Http\Resources\CustomerResource;
use App\Services\TwoFactorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

class TwoFactorController extends Controller
{
    public function __construct(protected TwoFactorService $twoFactorService) {}
 
    // ─────────────────────────────────────────────────────────────
    //  STATUS
    // ─────────────────────────────────────────────────────────────

    /**
     * GET /api/2fa/status
     */
    public function status(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'status'               => true,
            'two_factor_enabled'   => $user->two_factor_enabled,
            'two_factor_type'      => $user->two_factor_type,
            'two_factor_confirmed' => $user->two_factor_confirmed,
        ]);
    }
 
    // ─────────────────────────────────────────────────────────────
    //  ENABLE EMAIL OTP
    // ─────────────────────────────────────────────────────────────

    /**
     * POST /api/2fa/enable/email
     */
    public function enableEmail(Request $request): JsonResponse
    {
        $user = $request->user();

        $user->update([
            'two_factor_enabled'        => true,
            'two_factor_type'           => 'email',
            'two_factor_secret'         => null,
            'two_factor_confirmed'      => false,
            'two_factor_otp'            => null,
            'two_factor_otp_expires_at' => null,
        ]);

        return response()->json([
            'status'  => true,
            'message' => 'Email OTP two-factor authentication enabled successfully.',
            'type'    => 'email',
        ]);
    }
 
    // ─────────────────────────────────────────────────────────────
    //  SETUP AUTHENTICATOR APP
    // ─────────────────────────────────────────────────────────────

    /**
     * POST /api/2fa/setup/authenticator
     */
    public function setupAuthenticator(Request $request): JsonResponse
    {
        $user = $request->user();
        $data = $this->twoFactorService->generateAuthenticatorSecret($user);

        return response()->json([
            'status'      => true,
            'message'     => 'Scan this QR code with your authenticator app, then confirm with the 6-digit code.',
            'secret'      => $data['secret'],
            'qr_code_url' => $data['qr_code_url'],
        ]);
    }

    /**
     * POST /api/2fa/confirm/authenticator
     * Body: { "code": "123456" }
     */
    public function confirmAuthenticator(Request $request): JsonResponse
    {
        $request->validate([
            'code' => ['required', 'string', 'size:6', 'regex:/^[0-9]+$/'],
        ]);

        $user  = $request->user();
        $valid = $this->twoFactorService->confirmAuthenticatorSetup($user, $request->code);

        if (!$valid) {
            return response()->json([
                'status'  => false,
                'message' => 'Invalid code. Make sure your authenticator app time is synced.',
            ], 422);
        }

        $user->update([
            'two_factor_enabled' => true,
            'two_factor_type'    => 'authenticator',
        ]);

        return response()->json([
            'status'  => true,
            'message' => 'Authenticator app setup successful. Two-factor authentication is now active.',
            'type'    => 'authenticator',
        ]);
    }
 
    // ─────────────────────────────────────────────────────────────
    //  DISABLE 2FA
    // ─────────────────────────────────────────────────────────────

    /**
     * POST /api/2fa/disable
     * Body: { "password": "user_password" }
     */
    public function disable(Request $request): JsonResponse
    {
        $request->validate([
            'password' => ['required', 'string', 'current_password'],
        ]);

        $request->user()->update([
            'two_factor_enabled'        => false,
            'two_factor_type'           => null,
            'two_factor_secret'         => null,
            'two_factor_confirmed'      => false,
            'two_factor_otp'            => null,
            'two_factor_otp_expires_at' => null,
        ]);

        return response()->json([
            'status'  => true,
            'message' => 'Two-factor authentication disabled successfully.',
        ]);
    }
 
    // ─────────────────────────────────────────────────────────────
    //  VERIFY OTP (Login ke baad — real token milega)
    // ─────────────────────────────────────────────────────────────

    /**
     * POST /api/2fa/verify
     * Body: { "pending_token": "uuid", "code": "123456" }
     */
    public function verify(Request $request): JsonResponse
    {
        $request->validate([
            'pending_token' => ['required', 'string'],
            'code'          => ['required', 'string', 'size:6', 'regex:/^[0-9]+$/'],
        ]);

        // Pending token se user dhundo
        $user = $this->twoFactorService->getUserFromPendingToken($request->pending_token);

        if (!$user) {
            return response()->json([
                'status'  => false,
                'message' => 'Session expired or invalid token. Please login again.',
            ], 401);
        }

        // Type ke hisab se verify
        $valid = false;

        if ($user->two_factor_type === 'email') {
            $valid = $this->twoFactorService->verifyEmailOtp($user, $request->code);
        } elseif ($user->two_factor_type === 'authenticator') {
            $valid = $this->twoFactorService->verifyAuthenticatorOtp($user, $request->code);
        }

        if (!$valid) {
            return response()->json([
                'status'  => false,
                'message' => 'Invalid or expired verification code. Please try again.',
            ], 422);
        }

        // ✅ Valid → pending token delete, real token do
        $this->twoFactorService->clearPendingToken($request->pending_token);

        // Token create + 24hr expiry (same as your existing login)
        $tokenName   = $user->userRole === 1 ? 'adminToken' : 'customerToken';
        $tokenResult = $user->createToken($tokenName);
        $plainText   = $tokenResult->plainTextToken;

        $expireAt    = now()->addHours(24);
        $accessToken = PersonalAccessToken::findToken($plainText);
        if ($accessToken) {
            $accessToken->expires_at = $expireAt;
            $accessToken->save();
        }

        // Admin response
        if ($user->userRole === 1) {
            $stores = Store::where('user_id', $user->id)->get();

            return response()->json([
                'status'   => true,
                'message'  => 'Two-factor authentication successful.',
                'token'    => $plainText,
                'expireAt' => $expireAt->toDateTimeString(),
                'user'     => new UserResource($user),
                'stores'   => StoreResource::collection($stores),
            ]);
        }

        // Customer response
        return response()->json([
            'status'   => true,
            'message'  => 'Two-factor authentication successful.',
            'token'    => $plainText,
            'expireAt' => $expireAt->toDateTimeString(),
            'customer' => new CustomerResource($user->customer),
        ]);
    }
 
    // ─────────────────────────────────────────────────────────────
    //  RESEND OTP (Email OTP)
    // ─────────────────────────────────────────────────────────────

    /**
     * POST /api/2fa/resend-otp
     * Body: { "pending_token": "uuid" }
     */
    public function resendOtp(Request $request): JsonResponse
    {
        $request->validate([
            'pending_token' => ['required', 'string'],
        ]);

        $user = $this->twoFactorService->getUserFromPendingToken($request->pending_token);

        if (!$user) {
            return response()->json([
                'status'  => false,
                'message' => 'Session expired or invalid token. Please login again.',
            ], 401);
        }

        if ($user->two_factor_type !== 'email') {
            return response()->json([
                'status'  => false,
                'message' => 'Resend OTP is only available for email type.',
            ], 422);
        }

        $this->twoFactorService->sendEmailOtp($user);

        return response()->json([
            'status'  => true,
            'message' => 'OTP has been resent to your email.',
        ]);
    }
}
