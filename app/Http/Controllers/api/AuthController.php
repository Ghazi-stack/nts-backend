<?php

namespace App\Http\Controllers\api;

use App\Models\Cart;
use App\Models\User;
use App\Models\Customer;
use Illuminate\Http\Request;
use App\Mail\UserRegistrationMail;
use Illuminate\Support\Facades\DB;
use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use App\Http\Resources\StoreResource;
use App\Http\Resources\CustomerResource;
use App\Models\Store;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Laravel\Sanctum\PersonalAccessToken;
use Illuminate\Support\Facades\Validator;
use Exception;
use App\Mail\PasswordResetSuccessMail;
use App\Services\TwoFactorService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

class AuthController extends Controller
{

    public function register(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'firstName'     => 'required|string|max:100',
                'lastName'      => 'nullable|string|max:100',
                'email'         => 'required|email|unique:users,email',
                'phoneNumber'   => 'nullable|string|max:20|unique:customers,phone',
                'password'      => 'required|string|min:6',
                'companyName'   => 'nullable|string|max:255',
                'addressLine1'  => 'nullable|string|max:255',
                'addressLine2'  => 'nullable|string|max:255',
                'suburb'        => 'nullable|string|max:255',
                'country'       => 'nullable|string|max:100',
                'state'         => 'nullable|string|max:100',
                'zip'           => 'nullable|string|max:20',
                'storeName'     => 'nullable|string|max:255',
                'businessSize'  => 'nullable|string|max:100',
                'region'        => 'nullable|string|max:100',
                'userRole'      => 'required|in:1,2',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'status' => false,
                    'message' => 'Validation errors',
                    'errors' => $validator->errors()
                ], 422);
            }

            DB::beginTransaction();

            if ($request->userRole == 1) {
                $user = User::create([
                    'name'          => $request->firstName . ' ' . $request->lastName,
                    'email'         => $request->email,
                    'password'      => bcrypt($request->password),
                    'userRole'      => 1,
                    'store_name'    => $request->storeName,
                    'base_url'      => $request->base_url,
                    'business_size' => $request->businessSize,
                    'region'        => $request->region,
                    'store_id'      => null,
                    'customer_id'   => null,
                ]);

                $store = Store::create([
                    'name'     => $request->storeName,
                    'user_id'  => $user->id,
                    'base_url' => $request->base_url,
                ]);

                $user->store_id = $store->id;
                $user->save();

                DB::commit();

                // Send Registration Email to Store Owner
                try {
                    Mail::to($user->email)->send(new UserRegistrationMail($user, $store->id));

                    $adminEmail = env('MAIL_FROM_SB_ADMIN', 'aroz.ali@shiwantek.com');
                    Mail::to($adminEmail)->send(new UserRegistrationMail($user, $store->id));
                } catch (\Exception $mailError) {
                    Log::error('REGISTRATION EMAIL FAILED', [
                        'error' => $mailError->getMessage(),
                        'user_email' => $user->email,
                    ]);
                }

                $token = $user->createToken('auth_token')->plainTextToken;
                $expireAt = Carbon::now()->addDays(1)->format('Y-m-d H:i:s');

                return response()->json([
                    'status'   => true,
                    'message'  => 'Store owner registered successfully',
                    'token'    => $token,
                    'expireAt' => $expireAt,
                    'user'     => new UserResource($user),
                    'store'    => $store,
                ], 201);
            }

            // ===================== CUSTOMER REGISTRATION =====================
            $storeId = current_store_id();

            $user = User::create([
                'name'          => $request->firstName . ' ' . $request->lastName,
                'email'         => $request->email,
                'password'      => bcrypt($request->password),
                'userRole'      => 2,
                'store_name'    => $request->storeName,
                'business_size' => $request->businessSize,
                'region'        => $request->region,
                'store_id'      => $storeId,
            ]);

            $customer = Customer::create([
                'store_id'        => $storeId,
                'first_name'      => $request->firstName,
                'last_name'       => $request->lastName,
                'email'           => $request->email,
                'phone'           => $request->phoneNumber,
                'company_name'    => $request->companyName,
                'address'         => $request->addressLine1,
                'address_line_2'  => $request->addressLine2,
                'city'            => $request->suburb,
                'country'         => $request->country,
                'state'           => $request->state,
                'zip'             => $request->zip,
                'store_credit'    => 0,
                'receive_review_emails' => false,
                'force_password_reset'  => false,
            ]);

            $user->customer_id = $customer->id;
            $user->save();

            DB::commit();

            // Send Registration Email to Customer + Admin
            try {
                Mail::to($user->email)->send(new UserRegistrationMail($user, $storeId));

                $adminEmail = env('MAIL_FROM_SB_ADMIN', 'aroz.ali@shiwantek.com');
                Mail::to($adminEmail)->send(new UserRegistrationMail($user, $storeId));
            } catch (\Exception $mailError) {
                Log::error('REGISTRATION EMAIL FAILED', [
                    'error' => $mailError->getMessage(),
                    'user_email' => $user->email,
                ]);
            }

            $token = $user->createToken('auth_token')->plainTextToken;
            $expireAt = Carbon::now()->addDays(1)->format('Y-m-d H:i:s');

            return response()->json([
                'status'   => true,
                'message'  => 'Customer registered successfully',
                'token'    => $token,
                'expireAt' => $expireAt,
                'store'    => Store::find($storeId),
                'user'     => new UserResource($user),
                'customer' => new CustomerResource($customer),
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Registration failed: ' . $e->getMessage() . ' | Line: ' . $e->getLine());

            return response()->json([
                'status' => false,
                'message' => 'Something went wrong during registration.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }


    protected TwoFactorService $twoFactorService;

    public function __construct(TwoFactorService $twoFactorService)
    {
        $this->twoFactorService = $twoFactorService;
    }

    public function login(Request $request)
    {
        try {
            $request->validate([
                'email'    => 'required|email',
                'password' => 'required',
            ]);

            $email    = $request->email;
            $password = $request->password;

            // storeId header ya body se
            $storeId = $request->header('storeId', $request->input('storeId'));

            /*
            |--------------------------------------------------------------------------
            | ADMIN LOGIN (userRole = 1)
            |--------------------------------------------------------------------------
            */
            $admin = User::where('email', $email)
                ->where('userRole', 1)
                ->first();

            if ($admin) {

                if (!Hash::check($password, $admin->password)) {
                    return response()->json([
                        'status'  => false,
                        'message' => 'Invalid email or password.',
                    ], 401);
                }

                // ── 2FA CHECK (Admin) ──────────────────────────────────────────
                if ($admin->two_factor_enabled) {

                    if ($admin->two_factor_type === 'email') {
                        $this->twoFactorService->sendEmailOtp($admin);
                    }

                    $pendingToken = $this->twoFactorService->storePendingLogin($admin);

                    return response()->json([
                        'status'              => true,
                        'two_factor_required' => true,
                        'two_factor_type'     => $admin->two_factor_type,
                        'pending_token'       => $pendingToken,
                        'message'             => $admin->two_factor_type === 'email'
                            ? 'OTP sent to your email. Please verify to continue.'
                            : 'Please enter the code from your authenticator app.',
                    ], 200);
                }
                // ── END 2FA CHECK ──────────────────────────────────────────────

                $stores = Store::where('user_id', $admin->id)->get();

                $tokenResult    = $admin->createToken('adminToken');
                $plainTextToken = $tokenResult->plainTextToken;

                $expireAt    = now()->addHours(24);
                $accessToken = PersonalAccessToken::findToken($plainTextToken);
                if ($accessToken) {
                    $accessToken->expires_at = $expireAt;
                    $accessToken->save();
                }

                return response()->json([
                    'status'   => true,
                    'message'  => 'Admin logged in successfully.',
                    'token'    => $plainTextToken,
                    'expireAt' => $expireAt->toDateTimeString(),
                    'user'     => new UserResource($admin),
                    'stores'   => StoreResource::collection($stores),
                ]);
            }

            /*
            |--------------------------------------------------------------------------
            | CUSTOMER LOGIN (userRole = 2)
            |--------------------------------------------------------------------------
            */
            if (!$storeId) {
                return response()->json([
                    'status'  => false,
                    'message' => 'Store ID is required for customer login.',
                ], 422);
            }

            // Store validate
            $store = Store::where('id', $storeId)->first();
            if (!$storeId) {
                return response()->json([
                    'status'  => false,
                    'message' => 'Invalid store.',
                ], 404);
            }

            $customer = User::where('email', $email)
                ->where('userRole', 2)
                ->where('store_id', $storeId)
                ->first();

            if (!$customer || !Hash::check($password, $customer->password)) {
                return response()->json([
                    'status'  => false,
                    'message' => 'Invalid email, password, or store.',
                ], 401);
            }

            // Force Password Reset Check
            if (
                $customer->customer &&
                $customer->customer->force_password_reset
            ) {

                DB::table('password_reset_tokens')
                    ->where('email', $customer->email)
                    ->delete();

                Password::sendResetLink([
                    'email' => $customer->email
                ]);

                return response()->json([
                    'status' => false,
                    'force_password_reset' => true,
                    'message' => 'Password reset is required. A reset link has been sent to your email.',
                ], 403);
            }
            // ── 2FA CHECK (Customer) ───────────────────────────────────────────
            if ($customer->two_factor_enabled) {

                if ($customer->two_factor_type === 'email') {
                    $this->twoFactorService->sendEmailOtp($customer);
                }

                $pendingToken = $this->twoFactorService->storePendingLogin($customer);

                return response()->json([
                    'status'              => true,
                    'two_factor_required' => true,
                    'two_factor_type'     => $customer->two_factor_type,
                    'pending_token'       => $pendingToken,
                    'message'             => $customer->two_factor_type === 'email'
                        ? 'OTP sent to your email. Please verify to continue.'
                        : 'Please enter the code from your authenticator app.',
                ], 200);
            }
            // ── END 2FA CHECK ──────────────────────────────────────────────────

            $tokenResult    = $customer->createToken('customerToken');
            $plainTextToken = $tokenResult->plainTextToken;

            $expireAt    = now()->addHours(24);
            $accessToken = PersonalAccessToken::findToken($plainTextToken);
            if ($accessToken) {
                $accessToken->expires_at = $expireAt;
                $accessToken->save();
            }

            return response()->json([
                'status'   => true,
                'message'  => 'Customer logged in successfully.',
                'token'    => $plainTextToken,
                'expireAt' => $expireAt->toDateTimeString(),
                'store'    => new StoreResource($store),
                'customer' => new CustomerResource($customer->customer),
            ]);
        } catch (\Exception $e) {
            Log::error('Login failed: ' . $e->getMessage());

            return response()->json([
                'status'  => false,
                'message' => 'Something went wrong during login.',
            ], 500);
        }
    }

    // public function login(Request $request)
    // {
    //     try {
    //         $request->validate([
    //             'email' => 'required|email',
    //             'password' => 'required',
    //         ]);

    //         $user = User::where('email', $request->email)->first();

    //         if (!$user || !Hash::check($request->password, $user->password)) {
    //             return response()->json([
    //                 'status' => false,
    //                 'message' => 'Invalid email or password',
    //                 'user' => null
    //             ], 401);
    //         }

    //         $roleMessage = $user->userRole == 1 ? 'Admin login successful' : 'Customer login successful';

    //          // ✅ Create token
    //         $tokenResult = $user->createToken('api_token');
    //         $plainTextToken = $tokenResult->plainTextToken;

    //         // ✅ Set expiry before checking token
    //         $expireAt = now()->addHours(12);
    //         $accessToken = \Laravel\Sanctum\PersonalAccessToken::findToken($plainTextToken);

    //         if ($accessToken) {
    //             $accessToken->expires_at = $expireAt;
    //             $accessToken->save();
    //         }

    //         // ✅ Load stores
    //         $stores = StoreResource::collection($user->stores()->get());

    //         // ✅ Return with expireAt
    //         return response()->json([
    //             'status' => true,
    //             'message' => $roleMessage,
    //             'token' => $plainTextToken,
    //             'expireAt' => $expireAt->toDateTimeString(), // ✅ Now this is always set
    //             'user' => $user,
    //             'stores' => $stores,
    //         ]);
    //     } catch (\Exception $e) {
    //         \Log::error('Login failed: ' . $e->getMessage());

    //         return response()->json([
    //             'status' => false,
    //             'message' => 'Something went wrong during login.',
    //         ], 500);
    //     }
    // }



    // public function login(Request $request)
    // {
    //     try {
    //         $request->validate([
    //             'email' => 'required|email',
    //             'password' => 'required',
    //         ]);

    //         $email = $request->email;
    //         $password = $request->password;

    //         // storeId header ya body se
    //         $storeId = $request->header('storeId', $request->input('storeId'));

    //         /*
    //     |--------------------------------------------------------------------------
    //     | ADMIN LOGIN (userRole = 1)
    //     |--------------------------------------------------------------------------
    //     */
    //         $admin = User::where('email', $email)
    //             ->where('userRole', 1)
    //             ->first();

    //         if ($admin) {

    //             if (!Hash::check($password, $admin->password)) {
    //                 return response()->json([
    //                     'status' => false,
    //                     'message' => 'Invalid email or password.',
    //                 ], 401);
    //             }

    //             $stores = Store::where('user_id', $admin->id)->get();

    //             $tokenResult = $admin->createToken('adminToken');
    //             $plainTextToken = $tokenResult->plainTextToken;

    //             $expireAt = now()->addHours(24);
    //             $accessToken = PersonalAccessToken::findToken($plainTextToken);
    //             if ($accessToken) {
    //                 $accessToken->expires_at = $expireAt;
    //                 $accessToken->save();
    //             }

    //             return response()->json([
    //                 'status' => true,
    //                 'message' => 'Admin logged in successfully.',
    //                 'token' => $plainTextToken,
    //                 'expireAt' => $expireAt->toDateTimeString(),
    //                 'user' => new UserResource($admin),
    //                 'stores' => StoreResource::collection($stores),
    //             ]);
    //         }

    //         /*
    //     |--------------------------------------------------------------------------
    //     | CUSTOMER LOGIN (userRole = 2)
    //     |--------------------------------------------------------------------------
    //     */
    //         if (!$storeId) {
    //             return response()->json([
    //                 'status' => false,
    //                 'message' => 'Store ID is required for customer login.',
    //             ], 422);
    //         }

    //         // Store validate
    //         $store = Store::where('id', $storeId)->first();
    //         if (!$storeId) {
    //             return response()->json([
    //                 'status' => false,
    //                 'message' => 'Invalid store.',
    //             ], 404);
    //         }

    //         $customer = User::where('email', $email)
    //             ->where('userRole', 2)
    //             ->where('store_id', $storeId)
    //             ->first();

    //         if (!$customer || !Hash::check($password, $customer->password)) {
    //             return response()->json([
    //                 'status' => false,
    //                 'message' => 'Invalid email, password, or store.',
    //             ], 401);
    //         }

    //         $tokenResult = $customer->createToken('customerToken');
    //         $plainTextToken = $tokenResult->plainTextToken;

    //         $expireAt = now()->addHours(24);
    //         $accessToken = PersonalAccessToken::findToken($plainTextToken);
    //         if ($accessToken) {
    //             $accessToken->expires_at = $expireAt;
    //             $accessToken->save();
    //         }

    //         return response()->json([
    //             'status' => true,
    //             'message' => 'Customer logged in successfully.',
    //             'token' => $plainTextToken,
    //             'expireAt' => $expireAt->toDateTimeString(),
    //             'store' => new StoreResource($store),
    //             'customer' => new CustomerResource($customer->customer),
    //         ]);
    //     } catch (\Exception $e) {
    //         Log::error('Login failed: ' . $e->getMessage());

    //         return response()->json([
    //             'status' => false,
    //             'message' => 'Something went wrong during login.',
    //         ], 500);
    //     }
    // }



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

            DB::table('password_reset_tokens')->where('email', $request->email)->delete();

            $status = Password::sendResetLink(
                $request->only('email')
            );

            return response()->json([
                'status' => $status === Password::RESET_LINK_SENT,
                'message' => 'Reset link sent to your email.',
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
                'password' => 'required|min:8',
                'token' => 'required'
            ]);

            $request->merge([
                'password_confirmation' => $request->password
            ]);

            $status = Password::reset(
                $request->only('email', 'password', 'password_confirmation', 'token'),
                function ($user, $password) {
                    $user->forceFill([
                        'password' => Hash::make($password),
                    ])->save();
                }
            );

            if ($status === Password::PASSWORD_RESET) {
                $user = \App\Models\User::where('email', $request->email)->first();
                if ($user) {
                    Mail::to($user->email)->send(new PasswordResetSuccessMail($user));
                }
            }

            return response()->json([
                'status' => $status === Password::PASSWORD_RESET,
                'message' => __($status),
            ], $status === Password::PASSWORD_RESET ? 200 : 400);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => $e->getMessage(),
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

    public function updateName(Request $request)
    {
        try {
            // ✅ Validate input
            $validator = Validator::make($request->all(), [
                'firstName' => 'nullable|string|max:100',
                'lastName'  => 'nullable|string|max:100',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'status' => false,
                    'message' => 'Validation errors',
                    'errors' => $validator->errors()
                ], 422);
            }

            // ✅ Get authenticated user
            $user = $request->user();

            // ✅ Update concatenated name
            $user->name = trim($request->firstName . ' ' . $request->lastName);
            $user->save();

            return response()->json([
                'status' => true,
                'message' => 'Name updated successfully',
                'user' => new UserResource($user),
            ], 200);
        } catch (\Exception $e) {
            \Log::error('Update name failed: ' . $e->getMessage());

            return response()->json([
                'status' => false,
                'message' => 'Something went wrong while updating the name',
            ], 500);
        }
    }

    public function updateEmail(Request $request)
    {
        try {
            // ✅ Validate inputs
            $validator = Validator::make($request->all(), [
                'newEmail' => 'nullable|email|unique:users,email',
                'password' => 'required|string',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'status' => false,
                    'message' => 'Validation errors',
                    'errors' => $validator->errors()
                ], 422);
            }

            $user = $request->user(); // currently logged-in user

            // ✅ Check password
            if (!Hash::check($request->password, $user->password)) {
                return response()->json([
                    'status' => false,
                    'message' => 'Incorrect password.',
                ], 401);
            }

            DB::beginTransaction();

            // ✅ Update email in users table
            $user->email = $request->newEmail;
            $user->save();

            // ✅ Update email in customers table if linked
            if ($user->customer) {
                $user->customer->email = $request->newEmail;
                $user->customer->save();
            }

            DB::commit();

            return response()->json([
                'status' => true,
                'message' => 'Email updated successfully.',
                'user' => new UserResource($user),
            ], 200);
        } catch (\Exception $e) {
            DB::rollBack();
            \Log::error('Email update failed: ' . $e->getMessage());

            return response()->json([
                'status' => false,
                'message' => 'Something went wrong while updating email.',
            ], 500);
        }
    }

    public function updatePassword(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'currentPassword' => 'required|string',
                'newPassword'     => 'required|string',
                'confirmPassword' => 'required|string|same:newPassword',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'status' => false,
                    'message' => 'Validation errors',
                    'errors' => $validator->errors(),
                ], 422);
            }

            $user = $request->user();

            if (!$user) {
                return response()->json([
                    'status' => false,
                    'message' => 'Unauthenticated.',
                ], 401);
            }

            // Check current password
            if (!Hash::check($request->currentPassword, $user->password)) {
                return response()->json([
                    'status' => false,
                    'message' => 'Current password is incorrect.',
                ], 401);
            }

            // Prevent reusing the same password
            if (Hash::check($request->newPassword, $user->password)) {
                return response()->json([
                    'status' => false,
                    'message' => 'New password cannot be the same as the current password.',
                ], 400);
            }

            // Update password
            $user->password = Hash::make($request->newPassword);
            $user->save();

            // Delete all tokens - logout from all devices
            $user->tokens()->delete();

            return response()->json([
                'status' => true,
                'message' => 'Password updated successfully. Please login again.',
            ], 200);
        } catch (\Exception $e) {
            \Log::error('Password update failed: ' . $e->getMessage());

            return response()->json([
                'status' => false,
                'message' => 'Something went wrong while updating the password.',
            ], 500);
        }
    }

    public function loginAsCustomer(Request $request)
    {
        try {
            $request->validate([
                'customer_id' => 'required|integer',
            ]);

            $storeId = current_store_id();
            if (!$storeId) {
                return response()->json([
                    'status' => false,
                    'message' => 'Store not selected.'
                ], 400);
            }

            $user = User::where('customer_id', $request->customer_id)
                ->where('store_id', $storeId)
                ->first();

            if (!$user) {
                return response()->json([
                    'status' => false,
                    'message' => 'Customer user not found.'
                ], 404);
            }

            $token = $user->createToken('customer_login')->plainTextToken;

            return response()->json([
                'status' => true,
                'message' => 'Customer logged in successfully.',
                'token'  => $token,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    public function getCustomerProfile(Request $request)
    {
        try {
            $user = auth('sanctum')->user();
            if (!$user) {
                return response()->json([
                    'status' => false,
                    'message' => 'User not authenticated.'
                ], 401);
            }

            $storeId = current_store_id();
            $store = Store::find($storeId);
            $customer = $user->customer;

            // Current token ka expiry nikalo
            $currentToken = $user->currentAccessToken();
            $expireAt = $currentToken->expires_at ?? now()->addHours(24)->toDateTimeString();

            return response()->json([
                'status'   => true,
                'message'  => 'Customer profile retrieved successfully.',
                'token'    => $request->bearerToken(),
                'expireAt' => $expireAt,
                'store'    => $store ? new StoreResource($store) : null,
                'customer' => $customer ? new CustomerResource($customer) : null,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }
}
