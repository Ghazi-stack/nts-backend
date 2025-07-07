<?php

namespace App\Http\Controllers\api;

use App\Models\Cart;
use App\Models\User;
use Illuminate\Http\Request;
use App\Mail\UserRegistrationMail;
use Illuminate\Support\Facades\DB;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function register(Request $request)
    {
          try {
                $validator = Validator::make($request->all(), [
                    'name'         => 'required|string|max:100',
                    'email'        => 'required|email|unique:users',
                    'password'     => 'required|min:6',
                    'phoneNumber' => 'required|string|max:20|unique:users', // Added unique check
                ]);
            
                if ($validator->fails()) {
                    return response()->json([
                        'status' => false,
                        'message' => 'Validation errors',
                        'errors' => $validator->errors()
                    ], 422);
                }
            
                $user = User::create([
                    'name'         => $request->name,
                    'email'        => $request->email,
                    'password'     => bcrypt($request->password),
                    'phoneNumber' => $request->phoneNumber,
                ]);
            
                try {
                    Mail::to($user->email)->send(new UserRegistrationMail($user));
                } catch (Exception $mailException) {
                    Log::error('Mail sending failed: ' . $mailException->getMessage());
                }
            
                return response()->json([
                    'status' => true,
                    'message' => 'User registered successfully',
                    'user' => [
                        $user
                    ],
                ], 201);
            
            } catch (Exception $e) {
                Log::error('Registration failed: ' . $e->getMessage());
            
                return response()->json([
                    'status' => false,
                    'message' => 'Something went wrong during registration. Please try again.',
                ], 500);
            }
        }

         public function login(Request $request)
    {
        try {
            $request->validate([
                'email'    => 'required|email',
                'password' => 'required',
            ]);

            $user = User::where('email', $request->email)->first();

            if (!$user || !Hash::check($request->password, $user->password)) {
                return response()->json([
                    'status' => false,
                    'message' => 'Invalid email or password',
                    'user' => [
                        $user
                    ]
                ], 401); // Unauthorized
            }

            $token = $user->createToken('api_token')->plainTextToken;
    
            return response()->json([
                'status' => true,
                'message' => 'Login successful',
                'token' => $token,
                'user'  => $user,
            ]);

        } catch (Exception $e) {
            Log::error('Login failed: ' . $e->getMessage());

            return response()->json([
                'status' => false,
                'message' => 'Something went wrong during login. Please try again.',
            ], 500);
        }
    }

    public function forgotPassword(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'email' => 'required|email|exists:users,email',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'status' => false,
                    'errors' => $validator->errors(),
                ], 422);
            }

            DB::table('password_resets')->where('email', $request->email)->delete();

            $status = Password::sendResetLink(
                $request->only('email')
            );

            return response()->json([
                'status' => $status === Password::RESET_LINK_SENT,
                'message' =>'Reset link sent to your email.',
            ]);
        } catch (Exception $e) {
            Log::error('Forgot password failed: ' . $e->getMessage());

            return response()->json([
                'status' => false,
                'message' => 'Something went wrong while sending the reset link.',
            ], 500);
        }
    }

    public function resetPassword(Request $request)
    {
        try {
            $request->validate([
                'email' => 'required|email|exists:users,email',
                'password' => 'required|confirmed|min:8',
                'token' => 'required'
            ]);

            $user = User::where('email', $request->email)->first();

            if (Hash::check($request->password, $user->password)) {
                return response()->json([
                    'status' => false,
                    'message' => 'New password cannot be the same as the old password.',
                ], 400);
            }

            $status = Password::reset(
                $request->only('email', 'password', 'password_confirmation', 'token'),
                function ($user, $password) {
                    $user->forceFill([
                        'password' => Hash::make($password),
                    ])->save();

                    try {
                        Mail::to($user->email)->send(new PasswordResetSuccessMail($user));
                    } catch (Exception $mailEx) {
                        Log::error('Password reset mail failed: ' . $mailEx->getMessage());
                    }
                }
            );

            return response()->json([
                'status' => $status === Password::PASSWORD_RESET,
                'message' => __($status),
            ], $status === Password::PASSWORD_RESET ? 200 : 400);

        } catch (Exception $e) {
            Log::error('Reset password failed: ' . $e->getMessage());

            return response()->json([
                'status' => false,
                'message' => 'Something went wrong while resetting the password.',
            ], 500);
        }
    }

    public function logout(Request $request)
    {
        try {
            $user = $request->user();
        
            // 🗑️ Delete all cart items for this user
            Cart::where('user_id', $user->id)->delete();
        
            // 🔐 Revoke the current token
            $user->currentAccessToken()->delete();
        
            return response()->json([
                'status' => true,
                'message' => 'Logout successful and cart cleared',
            ]);
        } catch (Exception $e) {
            Log::error('Logout failed: ' . $e->getMessage());
        
            return response()->json([
                'status' => false,
                'message' => 'Something went wrong during logout. Please try again.',
            ], 500);
        }
    }

}

