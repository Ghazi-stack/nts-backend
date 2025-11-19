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
use Illuminate\Support\Facades\Password;
use Laravel\Sanctum\PersonalAccessToken;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function register(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'firstName'            => 'nullable|string|max:100',
                'lastName'             => 'nullable|string|max:100',
                'email'                => 'nullable|email|unique:users,email',
                'phoneNumber' => 'nullable|string|max:20|unique:customers,phone',
                'password'             => 'nullable|string|min:6|confirmed',
                'companyName'          => 'nullable|string|max:255',
                'addressLine1'         => 'nullable|string|max:255',
                'addressLine2'         => 'nullable|string|max:255',
                'suburb'               => 'nullable|string|max:255',
                'country'              => 'nullable|string|max:100',
                'state'                => 'nullable|string|max:100',
                'zip'                  => 'nullable|string|max:20',
                'storeName'            => 'nullable|string|max:255',
                'businessSize'         => 'nullable|string|max:100',
                'region'               => 'nullable|string|max:100',
                'userRole'             => 'nullable|in:1,2'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'status' => false,
                    'message' => 'Validation errors',
                    'errors' => $validator->errors()
                ], 422);
            }

            DB::beginTransaction();

            // ✅ Step 1: Create User
            $user = User::create([
                'name'         => $request->firstName . ' ' . $request->lastName,
                'email'        => $request->email,
                'password'     => bcrypt($request->password),
                'userRole'     => $request->input('userRole', 2), // default: customer
                'store_name'   => $request->storeName,
                'business_size'=> $request->businessSize,
                'region'       => $request->region,
                'store_id'     => current_store_id(), // Assuming a function to get current store
            ]);

            $storeId = current_store_id(); 

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

            // ✅ Step 3: Link user and customer
            $user->customer_id = $customer->id;
            $user->save();

            DB::commit();

            // ✅ Step 4: Send registration email
            try {
                Mail::to($user->email)->send(new UserRegistrationMail($user));
            } catch (Exception $mailException) {
                \Log::error('Mail sending failed: ' . $mailException->getMessage());
            }

            return response()->json([
                'status' => true,
                'message' => 'User and Customer registered successfully',
                'user' => new UserResource($user),
                'customer' => new CustomerResource($customer)
            ], 201);

        } catch (Exception $e) {
            DB::rollBack();
            \Log::error('Registration failed: ' . $e->getMessage());

            return response()->json([
                'status' => false,
                'message' => 'Something went wrong during registration. Please try again.',
                'error' => $e->getMessage()
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


    public function login(Request $request)
    {
        try {
            // ✅ Validate request inputs (camelCase)
            $request->validate([
                'email' => 'required|email',
                'password' => 'required',
                // 'storeId' => 'nullable|exists:stores,id', // Only required for customer
            ]);

            $email = $request->email;
            $password = $request->password;

            // ✅ Get storeId from either headers or request body
            $storeId = $request->header('storeId', $request->input('storeId'));

            // Admin Login
            $admin = User::where('email', $email)
                         ->where('userRole', 1)
                         ->first();

            if ($admin) {
                if (!Hash::check($password, $admin->password)) {
                    return response()->json([
                        'status' => false,
                        'message' => 'Invalid email or password.'
                    ], 401);
                }

                $tokenResult = $admin->createToken('adminToken');
                $plainTextToken = $tokenResult->plainTextToken;

                $expireAt = now()->addHours(12);
                $accessToken = PersonalAccessToken::findToken($plainTextToken);
                if ($accessToken) {
                    $accessToken->expires_at = $expireAt;
                    $accessToken->save();
                }

                $stores = StoreResource::collection($admin->stores()->get());

                return response()->json([
                    'status' => true,
                    'message' => 'Admin logged in successfully.',
                    'token' => $plainTextToken,
                    'expireAt' => $expireAt->toDateTimeString(),
                    'user' => new UserResource($admin),
                    'stores' => $stores,
                ]);
            }

            // Customer Login
            if (!$storeId) {
                return response()->json([
                    'status' => false,
                    'message' => 'Store ID is required to login as customer.',
                ], 422);
            }

            $customer = User::where('email', $email)
                            ->where('store_id', $storeId)
                            ->where('userRole', 2)
                            ->first();

            if (!$customer || !Hash::check($password, $customer->password)) {
                return response()->json([
                    'status' => false,
                    'message' => 'Invalid email, password, or store.',
                    'user' => null
                ], 401);
            }

            $tokenResult = $customer->createToken('customerToken');
            $plainTextToken = $tokenResult->plainTextToken;

            $expireAt = now()->addHours(12);
            $accessToken = PersonalAccessToken::findToken($plainTextToken);
            if ($accessToken) {
                $accessToken->expires_at = $expireAt;
                $accessToken->save();
            }

            return response()->json([
                'status' => true,
                'message' => 'Customer logged in successfully.',
                'token' => $plainTextToken,
                'expireAt' => $expireAt->toDateTimeString(),
                'customer' => new CustomerResource($customer->customer),
            ]);

        } catch (\Exception $e) {
            \Log::error('Login failed: ' . $e->getMessage());

            return response()->json([
                'status' => false,
                'message' => 'Something went wrong during login.',
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
        // ✅ Validate inputs
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

        // ✅ Check current password
        if (!Hash::check($request->currentPassword, $user->password)) {
            return response()->json([
                'status' => false,
                'message' => 'Current password is incorrect.',
            ], 401);
        }

        // ✅ Prevent reusing the same password
        if (Hash::check($request->newPassword, $user->password)) {
            return response()->json([
                'status' => false,
                'message' => 'New password cannot be the same as the current password.',
            ], 400);
        }

        // ✅ Update password
        $user->password = Hash::make($request->newPassword);
        $user->save();

        return response()->json([
            'status' => true,
            'message' => 'Password updated successfully.',
        ], 200);

    } catch (\Exception $e) {
        \Log::error('Password update failed: ' . $e->getMessage());

        return response()->json([
            'status' => false,
            'message' => 'Something went wrong while updating the password.',
        ], 500);
    }
}


}

