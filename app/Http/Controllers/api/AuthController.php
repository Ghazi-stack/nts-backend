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
use App\Mail\AdminForgotPasswordMail;
use App\Models\Store;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Laravel\Sanctum\PersonalAccessToken;
use Illuminate\Support\Facades\Validator;
use Exception;
use App\Models\Permission;
use App\Mail\PasswordResetSuccessMail;
use App\Mail\StoreAccessMail;
use App\Models\Order;
use App\Models\StaffLog;
use App\Services\TwoFactorService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\Rule;
use Illuminate\Support\Str;

class AuthController extends Controller
{

    public function register(Request $request)
    {
        try {
            $storeId = current_store_id();

            // Fallback headers
            if (!$storeId) {
                $storeId = $request->header('storeId')
                    ?: $request->header('Store-Id')
                    ?: $request->header('X-Store-Id');
            }
            if ($storeId) {
                $storeId = (int) $storeId;
            }

            $isOwnerRegistration = $request->userRole == 1 && !$storeId;
            $isSubUser           = $request->userRole == 1 && $storeId;

            // ---------- Dynamic Email Rules ----------
            $emailRules = ['required', 'email'];

            if (!$isOwnerRegistration) {
                // Sub-user / Customer → unique only inside same store
                $emailRules[] = Rule::unique('users', 'email')->where(function ($query) use ($storeId) {
                    return $query->where('store_id', $storeId);
                });
            }

            $validator = Validator::make($request->all(), [
                'firstName'     => 'required|string|max:100',
                'lastName'      => 'nullable|string|max:100',
                'email'         => $emailRules,
                'phoneNumber'   => 'nullable|string|max:20',
                'password'      => $isSubUser ? 'nullable|string|min:6' : 'required|string|min:6',
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
                'permissions'   => $isSubUser ? 'required|array|min:1' : 'nullable',
                'permissions.*' => 'exists:permissions,id',
                'base_url'      => 'nullable|string|max:255',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'status'  => false,
                    'message' => 'Validation errors',
                    'errors'  => $validator->errors()
                ], 422);
            }

            DB::beginTransaction();

            if ($request->userRole == 1) {

                // ===================== OWNER REGISTRATION =====================
                if ($isOwnerRegistration) {

                    $existingUser = User::where('email', $request->email)->first();

                    // ---------- Additional Store for Existing Owner ----------
                    if ($existingUser) {

                        if ($existingUser->userRole != 1) {
                            return response()->json([
                                'status'  => false,
                                'message' => 'This email is already registered as a customer.',
                            ], 422);
                        }

                        if (!Hash::check($request->password, $existingUser->password)) {
                            return response()->json([
                                'status'  => false,
                                'message' => 'Invalid password. For additional stores use the original registration password.',
                            ], 401);
                        }

                        $store = Store::create([
                            'name'     => $request->storeName,
                            'user_id'  => $existingUser->id,
                            'base_url' => $request->base_url,
                        ]);

                        // Assign ALL permissions for new store
                        $allPermissions = Permission::all();
                        $inserts = [];
                        foreach ($allPermissions as $perm) {
                            $inserts[] = [
                                'user_id'       => $existingUser->id,
                                'permission_id' => $perm->id,
                                'store_id'      => $store->id,
                                'created_at'    => now(),
                                'updated_at'    => now(),
                            ];
                        }
                        DB::table('user_permissions')->insert($inserts);

                        DB::commit();

                        $userPermissions = Permission::orderBy('group')
                            ->orderBy('sort_order')
                            ->get()
                            ->groupBy('group')
                            ->map(function ($items, $group) {
                                return [
                                    'group' => $group,
                                    'permissions' => $items->map(function ($p) {
                                        return [
                                            'id'    => $p->id,
                                            'name'  => $p->name,
                                            'label' => $p->label,
                                            'slug'  => $p->slug,
                                        ];
                                    })->values(),
                                ];
                            })->values();

                        try {
                            Mail::to($existingUser->email)->send(new UserRegistrationMail($existingUser, $store->id));
                            $adminEmail = env('MAIL_FROM_SB_ADMIN', 'aroz.ali@shiwantek.com');
                            Mail::to($adminEmail)->send(new UserRegistrationMail($existingUser, $store->id));
                        } catch (\Exception $mailError) {
                            Log::error('REGISTRATION EMAIL FAILED', [
                                'error'      => $mailError->getMessage(),
                                'user_email' => $existingUser->email,
                            ]);
                        }

                        $token    = $existingUser->createToken('auth_token')->plainTextToken;
                        $expireAt = Carbon::now()->addDays(1)->format('Y-m-d H:i:s');

                        return response()->json([
                            'status'      => true,
                            'message'     => 'New store created successfully for existing owner',
                            'token'       => $token,
                            'expireAt'    => $expireAt,
                            'user'        => new UserResource($existingUser),
                            'store'       => $store,
                            'permissions' => $userPermissions,
                            'isOwner'     => true,
                        ], 201);
                    }

                    // ---------- First Time Owner Registration ----------
                    $user = User::create([
                        'name'          => $request->firstName . ' ' . $request->lastName,
                        'email'         => $request->email,
                        'password'      => bcrypt($request->password),
                        'userRole'      => 1,
                        'phone_number'  => $request->phoneNumber,
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

                    // Assign ALL permissions
                    $allPermissions = Permission::all();
                    $inserts = [];
                    foreach ($allPermissions as $perm) {
                        $inserts[] = [
                            'user_id'       => $user->id,
                            'permission_id' => $perm->id,
                            'store_id'      => $store->id,
                            'created_at'    => now(),
                            'updated_at'    => now(),
                        ];
                    }
                    DB::table('user_permissions')->insert($inserts);

                    DB::commit();

                    $userPermissions = Permission::orderBy('group')
                        ->orderBy('sort_order')
                        ->get()
                        ->groupBy('group')
                        ->map(function ($items, $group) {
                            return [
                                'group' => $group,
                                'permissions' => $items->map(function ($p) {
                                    return [
                                        'id'    => $p->id,
                                        'name'  => $p->name,
                                        'label' => $p->label,
                                        'slug'  => $p->slug,
                                    ];
                                })->values(),
                            ];
                        })->values();

                    try {
                        Mail::to($user->email)->send(new UserRegistrationMail($user, $store->id));
                        $adminEmail = env('MAIL_FROM_SB_ADMIN', 'aroz.ali@shiwantek.com');
                        Mail::to($adminEmail)->send(new UserRegistrationMail($user, $store->id));
                    } catch (\Exception $mailError) {
                        Log::error('REGISTRATION EMAIL FAILED', [
                            'error'      => $mailError->getMessage(),
                            'user_email' => $user->email,
                        ]);
                    }

                    $token    = $user->createToken('auth_token')->plainTextToken;
                    $expireAt = Carbon::now()->addDays(1)->format('Y-m-d H:i:s');

                    return response()->json([
                        'status'      => true,
                        'message'     => 'Store owner registered successfully',
                        'token'       => $token,
                        'expireAt'    => $expireAt,
                        'user'        => new UserResource($user),
                        'store'       => $store,
                        'permissions' => $userPermissions,
                        'isOwner'     => true,
                    ], 201);
                }

                // ===================== SUB-USER REGISTRATION (storeId header) =====================
                if ($isSubUser) {

                    // Check if sub-user already exists (multi-store)
                    $existingUser = User::where('email', $request->email)
                        ->where('userRole', 1)
                        ->first();

                    if ($existingUser) {
                        // Check if already has permissions for this store
                        $alreadyInStore = DB::table('user_permissions')
                            ->where('user_id', $existingUser->id)
                            ->where('store_id', $storeId)
                            ->exists();

                        if ($alreadyInStore) {
                            return response()->json([
                                'status'  => false,
                                'message' => 'This user already has access to this store.',
                            ], 409);
                        }

                        // Assign selected permissions for this store
                        $inserts = [];
                        foreach ($request->permissions as $permId) {
                            $inserts[] = [
                                'user_id'       => $existingUser->id,
                                'permission_id' => $permId,
                                'store_id'      => $storeId,
                                'created_at'    => now(),
                                'updated_at'    => now(),
                            ];
                        }
                        DB::table('user_permissions')->insert($inserts);

                        DB::commit();

                        // Send store access email
                        try {
                            $token = app('auth.password.broker')->createToken($existingUser);

                            $resetLink = 'https://admin-dashboard-nu-hazel-12.vercel.app/forgot-password?token=' . $token .
                                '&email=' . urlencode($existingUser->email);

                            Mail::to($existingUser->email)->send(
                                new StoreAccessMail(
                                    $existingUser->name,
                                    $existingUser->email,
                                    $resetLink,
                                    $storeId
                                )
                            );
                        } catch (\Exception $mailError) {
                            Log::error('SUB-USER STORE ACCESS EMAIL FAILED', [
                                'error' => $mailError->getMessage(),
                                'email' => $existingUser->email,
                            ]);
                        }

                        // Get assigned permissions for response
                        $userPermissions = Permission::whereIn('id', $request->permissions)
                            ->orderBy('group')
                            ->orderBy('sort_order')
                            ->get()
                            ->groupBy('group')
                            ->map(function ($items, $group) {
                                return [
                                    'group' => $group,
                                    'permissions' => $items->map(function ($p) {
                                        return [
                                            'id'    => $p->id,
                                            'name'  => $p->name,
                                            'label' => $p->label,
                                            'slug'  => $p->slug,
                                        ];
                                    })->values(),
                                ];
                            })->values();

                        // Get all stores for this user
                        $storeIds = DB::table('user_permissions')
                            ->where('user_id', $existingUser->id)
                            ->distinct()
                            ->pluck('store_id');
                        $allStores = Store::whereIn('id', $storeIds)->get();

                        $token = $existingUser->createToken('auth_token')->plainTextToken;
                        $expireAt = Carbon::now()->addDays(1)->format('Y-m-d H:i:s');

                        return response()->json([
                            'status'      => true,
                            'message'     => 'Existing user added to store with permissions. Store access email sent.',
                            'token'       => $token,
                            'expireAt'    => $expireAt,
                            'user'        => new UserResource($existingUser),
                            'store'       => Store::find($storeId),
                            'stores'      => $allStores,
                            'permissions' => $userPermissions,
                            'isOwner'     => false,
                        ], 201);
                    } else {
                        // New sub-user — random password, store access email jayega
                        $user = User::create([
                            'name'          => $request->firstName . ' ' . $request->lastName,
                            'email'         => $request->email,
                            'password'      => bcrypt(Str::random(16)),
                            'userRole'      => 1,
                            'phone_number'  => $request->phoneNumber,
                            'store_name'    => $request->storeName,
                            'business_size' => $request->businessSize,
                            'region'        => $request->region,
                            'store_id'      => $storeId,
                            'customer_id'   => null,
                        ]);

                        // Assign selected permissions
                        $inserts = [];
                        foreach ($request->permissions as $permId) {
                            $inserts[] = [
                                'user_id'       => $user->id,
                                'permission_id' => $permId,
                                'store_id'      => $storeId,
                                'created_at'    => now(),
                                'updated_at'    => now(),
                            ];
                        }
                        DB::table('user_permissions')->insert($inserts);

                        DB::commit();

                        // Send store access email
                        try {
                            $token = app('auth.password.broker')->createToken($user);

                            $resetLink = 'https://admin-dashboard-nu-hazel-12.vercel.app/forgot-password?token=' . $token .
                                '&email=' . urlencode($user->email);

                            Mail::to($user->email)->send(
                                new StoreAccessMail(
                                    $request->firstName,
                                    $user->email,
                                    $resetLink,
                                    $storeId
                                )
                            );
                        } catch (\Exception $mailError) {
                            Log::error('SUB-USER STORE ACCESS EMAIL FAILED', [
                                'error' => $mailError->getMessage(),
                                'email' => $user->email,
                            ]);
                        }

                        // Get assigned permissions for response
                        $userPermissions = Permission::whereIn('id', $request->permissions)
                            ->orderBy('group')
                            ->orderBy('sort_order')
                            ->get()
                            ->groupBy('group')
                            ->map(function ($items, $group) {
                                return [
                                    'group' => $group,
                                    'permissions' => $items->map(function ($p) {
                                        return [
                                            'id'    => $p->id,
                                            'name'  => $p->name,
                                            'label' => $p->label,
                                            'slug'  => $p->slug,
                                        ];
                                    })->values(),
                                ];
                            })->values();

                        $token = $user->createToken('auth_token')->plainTextToken;
                        $expireAt = Carbon::now()->addDays(1)->format('Y-m-d H:i:s');

                        return response()->json([
                            'status'      => true,
                            'message'     => 'Sub-user registered successfully. Store access email sent.',
                            'token'       => $token,
                            'expireAt'    => $expireAt,
                            'user'        => new UserResource($user),
                            'store'       => Store::find($storeId),
                            'stores'      => [Store::find($storeId)],
                            'permissions' => $userPermissions,
                            'isOwner'     => false,
                        ], 201);
                    }
                }
            }

            // ===================== CUSTOMER REGISTRATION =====================
            $storeId = current_store_id();

            // Check if guest customer exists with same email
            $existingGuestCustomer = Customer::where('store_id', $storeId)
                ->where('email', $request->email)
                ->where('user_type', 'guest')
                ->first();

            $user = User::create([
                'name'          => $request->firstName . ' ' . $request->lastName,
                'email'         => $request->email,
                'password'      => bcrypt($request->password),
                'userRole'      => 2,
                'phone_number'  => $request->phoneNumber,
                'store_name'    => $request->storeName,
                'business_size' => $request->businessSize,
                'region'        => $request->region,
                'store_id'      => $storeId,
            ]);

            if ($existingGuestCustomer) {
                // Guest customer exists — update it, don't create new
                $existingGuestCustomer->update([
                    'first_name'      => $request->firstName,
                    'last_name'       => $request->lastName,
                    'phone'           => $request->phoneNumber,
                    'company_name'    => $request->companyName,
                    'address'         => $request->addressLine1,
                    'address_line_2'  => $request->addressLine2,
                    'city'            => $request->suburb,
                    'country'         => $request->country,
                    'state'           => $request->state,
                    'zip'             => $request->zip,
                    'user_type'       => null, // guest → registered
                ]);

                $customer = $existingGuestCustomer;

                // Update all guest orders for this email
                Order::where('store_id', $storeId)
                    ->where('customer_id', $customer->id)
                    ->where('user_type', 'guest')
                    ->update([
                        'user_id'   => $user->id,
                        'user_type' => null,
                    ]);
            } else {
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
            }

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
                'status'      => true,
                'message'     => 'Customer registered successfully',
                'token'       => $token,
                'expireAt'    => $expireAt,
                'store'       => Store::find($storeId),
                'user'        => new UserResource($user),
                'customer'    => new CustomerResource($customer),
                'permissions' => null,
                'isOwner'     => false,
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Registration failed: ' . $e->getMessage() . ' | Line: ' . $e->getLine());

            return response()->json([
                'status'  => false,
                'message' => 'Something went wrong during registration.',
                'error'   => $e->getMessage(),
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
            $storeId  = $request->header('storeId', $request->input('storeId'));

            /*
        |--------------------------------------------------------------------------
        | ADMIN / SUB-USER LOGIN (userRole = 1)
        |--------------------------------------------------------------------------
        */
            $admin = User::where('email', $email)
                ->where('userRole', 1)
                ->first();

            if (!$admin) {
                return response()->json([
                    'status'  => false,
                    'message' => 'Invalid email or password.',
                ], 401);
            }

            if (!Hash::check($password, $admin->password)) {
                return response()->json([
                    'status'  => false,
                    'message' => 'Invalid email or password.',
                ], 401);
            }

            // ── 2FA CHECK ──────────────────────────────────────────
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
            // ── END 2FA ────────────────────────────────────────────

            // ===================== GET ALL STORES =====================
            $isOwner = false;

            $ownedStores = Store::where('user_id', $admin->id)->get();
            if ($ownedStores->isNotEmpty()) {
                $isOwner = true;
            }

            $permStoreIds = DB::table('user_permissions')
                ->where('user_id', $admin->id)
                ->distinct()
                ->pluck('store_id')
                ->filter()
                ->toArray();

            $permStores = !empty($permStoreIds)
                ? Store::whereIn('id', $permStoreIds)->get()
                : collect();

            $stores = $ownedStores->merge($permStores)->unique('id')->values();

            if ($stores->isEmpty() && $admin->store_id) {
                $stores = Store::where('id', $admin->store_id)->get();
            }

            // ===================== TOKEN =====================
            $tokenResult    = $admin->createToken('adminToken');
            $plainTextToken = $tokenResult->plainTextToken;

            $expireAt    = now()->addHours(24);
            $accessToken = PersonalAccessToken::findToken($plainTextToken);

            if ($accessToken) {
                $accessToken->expires_at = $expireAt;
                $accessToken->save();
            }

            // ===================== PERMISSIONS =====================
            $permissionStoreId = $storeId ?: $admin->store_id;
            if (!$permissionStoreId && $stores->isNotEmpty()) {
                $permissionStoreId = $stores->first()->id;
            }

            $adminPermissions = $admin->permissions()
                ->when($permissionStoreId, function ($q) use ($permissionStoreId) {
                    $q->where('user_permissions.store_id', $permissionStoreId);
                })
                ->orderBy('group')
                ->orderBy('sort_order')
                ->get()
                ->groupBy('group')
                ->map(function ($items, $group) {
                    return [
                        'group' => $group,
                        'permissions' => $items->map(function ($p) {
                            return [
                                'id'    => $p->id,
                                'name'  => $p->name,
                                'label' => $p->label,
                                'slug'  => $p->slug,
                            ];
                        })->values(),
                    ];
                })->values();

            // ===================== STAFF LOG: LOGIN =====================
            StaffLog::record([
                'store_id'    => $permissionStoreId ?? ($stores->first()->id ?? null),
                'user_id'     => $admin->id,
                'username'    => $admin->email,
                'action'      => '(Successfully logged in)',
                'action_type' => 'login',
                'ip_address'  => $request->ip(),
            ]);

            return response()->json([
                'status'      => true,
                'message'     => $isOwner
                    ? 'Admin logged in successfully.'
                    : 'Sub-user logged in successfully.',
                'token'       => $plainTextToken,
                'expireAt'    => $expireAt->toDateTimeString(),
                'user'        => new UserResource($admin),
                'stores'      => StoreResource::collection($stores),
                'permissions' => $adminPermissions,
                'isOwner'     => $isOwner,
            ]);
        } catch (\Exception $e) {
            Log::error('Login failed: ' . $e->getMessage());

            return response()->json([
                'status'  => false,
                'message' => 'Something went wrong during login.',
            ], 500);
        }
    }

    public function customerLogin(Request $request)
    {
        try {
            $request->validate([
                'email'    => 'required|email',
                'password' => 'required',
            ]);

            $email    = $request->email;
            $password = $request->password;
            $storeId  = $request->header('storeId', $request->input('storeId'));

            /*
        |--------------------------------------------------------------------------
        | CUSTOMER LOGIN (userRole = 2)
        |--------------------------------------------------------------------------
        */

            $customer = User::where('email', $email)
                ->where('userRole', 2)
                ->first();

            if (!$customer) {
                return response()->json([
                    'status'  => false,
                    'message' => 'Invalid email or password.',
                ], 401);
            }

            if (!Hash::check($password, $customer->password)) {
                return response()->json([
                    'status'  => false,
                    'message' => 'Invalid email or password.',
                ], 401);
            }

            /*
        |--------------------------------------------------------------------------
        | STORE ID
        |--------------------------------------------------------------------------
        */

            if (!$storeId) {
                return response()->json([
                    'status'  => false,
                    'message' => 'Store ID is required for customer login.',
                ], 422);
            }

            $store = Store::where('id', $storeId)->first();

            if (!$store) {
                return response()->json([
                    'status'  => false,
                    'message' => 'Invalid store.',
                ], 404);
            }

            /*
        |--------------------------------------------------------------------------
        | FORCE PASSWORD RESET
        |--------------------------------------------------------------------------
        */

            if ($customer->customer && $customer->customer->force_password_reset) {

                DB::table('password_reset_tokens')
                    ->where('email', $customer->email)
                    ->delete();

                Password::sendResetLink([
                    'email' => $customer->email
                ]);

                return response()->json([
                    'status'               => false,
                    'force_password_reset' => true,
                    'message'              => 'Password reset is required. A reset link has been sent to your email.',
                ], 403);
            }

            /*
        |--------------------------------------------------------------------------
        | 2FA CHECK
        |--------------------------------------------------------------------------
        */

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

            /*
        |--------------------------------------------------------------------------
        | SINGLE SESSION — purana token expire
        |--------------------------------------------------------------------------
        */

            $customer->tokens()->where('name', 'customerToken')->delete();

            /*
        |--------------------------------------------------------------------------
        | TOKEN
        |--------------------------------------------------------------------------
        */

            $tokenResult    = $customer->createToken('customerToken');
            $plainTextToken = $tokenResult->plainTextToken;

            $expireAt = now()->addHours(24);

            $accessToken = PersonalAccessToken::findToken($plainTextToken);

            if ($accessToken) {
                $accessToken->expires_at = $expireAt;
                $accessToken->save();
            }

            /*
        |--------------------------------------------------------------------------
        | RESPONSE
        |--------------------------------------------------------------------------
        */

            return response()->json([
                'status'      => true,
                'message'     => 'Customer logged in successfully.',
                'token'       => $plainTextToken,
                'expireAt'    => $expireAt->toDateTimeString(),
                'store'       => new StoreResource($store),
                'customer'    => new CustomerResource($customer->customer),
                'permissions' => null,
            ]);
        } catch (\Exception $e) {

            Log::error('Customer login failed: ' . $e->getMessage());

            return response()->json([
                'status'  => false,
                'message' => 'Something went wrong during login.',
            ], 500);
        }
    }

    public function checkCustomerToken(Request $request)
    {
        try {
            $storeId = $request->header('storeId', $request->input('storeId'));
            $token   = $request->bearerToken()
                ?? $request->header('token')
                ?? $request->input('token');

            if (!$storeId) {
                return response()->json([
                    'status'  => false,
                    'message' => 'Store ID is required.',
                ], 422);
            }

            if (!$token) {
                return response()->json([
                    'status'  => false,
                    'message' => 'Token expired',
                ], 401);
            }

            $store = Store::where('id', $storeId)->first();

            if (!$store) {
                return response()->json([
                    'status'  => false,
                    'message' => 'Invalid store.',
                ], 404);
            }

            $accessToken = PersonalAccessToken::findToken($token);

            if (!$accessToken) {
                return response()->json([
                    'status'  => false,
                    'message' => 'Token expired',
                ], 401);
            }

            if ($accessToken->expires_at && $accessToken->expires_at->isPast()) {
                $accessToken->delete();

                return response()->json([
                    'status'  => false,
                    'message' => 'Token expired',
                ], 401);
            }

            $user = $accessToken->tokenable;

            if (
                !$user ||
                $user->userRole != 2 ||
                $accessToken->name !== 'customerToken'
            ) {
                return response()->json([
                    'status'  => false,
                    'message' => 'Token expired',
                ], 401);
            }

            return response()->json([
                'status'  => true,
                'message' => 'Already existed',
                'store'   => new StoreResource($store),
                'customer' => $user->customer ? new CustomerResource($user->customer) : null,
            ]);
        } catch (\Exception $e) {
            Log::error('Customer token check failed: ' . $e->getMessage());

            return response()->json([
                'status'  => false,
                'message' => 'Something went wrong during token check.',
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


    public function getAdminUsers(Request $request)
    {
        try {
            $storeId = current_store_id();

            if (!$storeId) {
                return response()->json([
                    'status'  => false,
                    'message' => 'Store not selected or token invalid.'
                ], 400);
            }

            $currentUser = auth('sanctum')->user();

            // 1. Check: Current user ke paas is store ka access hai ya nahi
            $hasAccess = DB::table('user_permissions')
                ->where('user_id', $currentUser->id)
                ->where('store_id', $storeId)
                ->exists();

            // Owner check bhi (agar permissions table mein nahi bhi ho to)
            $isOwner = Store::where('id', $storeId)
                ->where('user_id', $currentUser->id)
                ->exists();

            if (!$hasAccess && !$isOwner) {
                return response()->json([
                    'status'  => false,
                    'message' => 'You do not have access to this store.',
                ], 403);
            }

            // 2. Is store ke saare admin/sub-users nikalo
            $userIds = DB::table('user_permissions')
                ->where('store_id', $storeId)
                ->distinct()
                ->pluck('user_id');

            $users = User::whereIn('id', $userIds)
                ->where('userRole', 1)
                ->where('id', '!=', $currentUser->id)   // khud ko exclude
                ->latest()
                ->get();

            return response()->json([
                'status'  => true,
                'message' => 'Admin users fetched successfully.',
                'data'    => UserResource::collection($users),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status'  => false,
                'message' => 'Failed to fetch admin users.',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    public function getAdminUser($id)
    {
        try {
            $storeId = current_store_id();

            if (!$storeId) {
                return response()->json([
                    'status'  => false,
                    'message' => 'Store not selected or token invalid.'
                ], 400);
            }

            $hasAccess = DB::table('user_permissions')
                ->where('user_id', $id)
                ->where('store_id', $storeId)
                ->exists();

            if (!$hasAccess) {
                return response()->json([
                    'status'  => false,
                    'message' => 'User not found in this store.',
                ], 404);
            }

            $user = User::where('id', $id)
                ->where('userRole', 1)
                ->firstOrFail();

            // Get user permissions for this store
            $userPermissions = $user->permissions()
                ->where('user_permissions.store_id', $storeId)
                ->orderBy('group')
                ->orderBy('sort_order')
                ->get()
                ->groupBy('group')
                ->map(function ($items, $group) {
                    return [
                        'group' => $group,
                        'permissions' => $items->map(function ($p) {
                            return [
                                'id'    => $p->id,
                                'name'  => $p->name,
                                'label' => $p->label,
                                'slug'  => $p->slug,
                            ];
                        })->values(),
                    ];
                })->values();

            return response()->json([
                'status'      => true,
                'message'     => 'Admin user fetched successfully.',
                'data'        => new UserResource($user),
                'permissions' => $userPermissions,
            ]);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'status'  => false,
                'message' => 'Admin user not found.',
            ], 404);
        } catch (\Exception $e) {
            return response()->json([
                'status'  => false,
                'message' => 'Failed to fetch admin user.',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }
    public function updateAdminUser(Request $request, $id)
    {
        try {
            $storeId = current_store_id();

            if (!$storeId) {
                return response()->json([
                    'status' => false,
                    'message' => 'Store not selected or token invalid.'
                ], 400);
            }

            $user = User::where('store_id', $storeId)
                ->where('userRole', 1)
                ->findOrFail($id);

            $request->validate([
                'firstName'     => 'nullable|string|max:100',
                'lastName'      => 'nullable|string|max:100',
                'email'         => 'nullable|email|unique:users,email,' . $user->id,
                //'password'      => 'nullable|string|min:6',
                'permissions'   => 'nullable|array',
                'permissions.*' => 'exists:permissions,id',
            ]);

            if ($request->filled('firstName') || $request->filled('lastName')) {
                $user->name = trim(
                    ($request->firstName ?? explode(' ', $user->name)[0] ?? '') . ' ' .
                        ($request->lastName ?? explode(' ', $user->name)[1] ?? '')
                );
            }

            if ($request->filled('email')) {
                $user->email = $request->email;
            }

            // if ($request->filled('password')) {
            //     $user->password = bcrypt($request->password);
            // }

            $user->save();

            // Update permissions
            if ($request->has('permissions')) {
                DB::table('user_permissions')
                    ->where('user_id', $user->id)
                    ->where('store_id', $storeId)
                    ->delete();

                $inserts = [];
                foreach ($request->permissions as $permId) {
                    $inserts[] = [
                        'user_id'       => $user->id,
                        'permission_id' => $permId,
                        'store_id'      => $storeId,
                        'created_at'    => now(),
                        'updated_at'    => now(),
                    ];
                }

                if (!empty($inserts)) {
                    DB::table('user_permissions')->insert($inserts);
                }
            }

            // Get updated permissions
            $userPermissions = $user->permissions()
                ->where('user_permissions.store_id', $storeId)
                ->orderBy('group')
                ->orderBy('sort_order')
                ->get()
                ->groupBy('group')
                ->map(function ($items, $group) {
                    return [
                        'group' => $group,
                        'permissions' => $items->map(function ($p) {
                            return [
                                'id'    => $p->id,
                                'name'  => $p->name,
                                'label' => $p->label,
                                'slug'  => $p->slug,
                            ];
                        })->values(),
                    ];
                })->values();

            return response()->json([
                'status'      => true,
                'message'     => 'Admin user updated successfully.',
                'data'        => new UserResource($user),
                'permissions' => $userPermissions,
            ]);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'status' => false,
                'message' => 'Admin user not found.',
            ], 404);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Failed to update admin user.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function deleteAdminUser($id)
    {
        try {
            $storeId = current_store_id();

            if (!$storeId) {
                return response()->json([
                    'status' => false,
                    'message' => 'Store not selected or token invalid.'
                ], 400);
            }

            $currentUser = auth('sanctum')->user();

            if ($currentUser->id == $id) {
                return response()->json([
                    'status' => false,
                    'message' => 'You cannot delete your own account.',
                ], 403);
            }

            $user = User::where('store_id', $storeId)
                ->where('userRole', 1)
                ->findOrFail($id);

            // Delete permissions
            DB::table('user_permissions')
                ->where('user_id', $user->id)
                ->where('store_id', $storeId)
                ->delete();

            // Delete tokens
            $user->tokens()->delete();

            // Delete user
            $user->delete();

            return response()->json([
                'status' => true,
                'message' => 'Admin user deleted successfully.',
            ]);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'status' => false,
                'message' => 'Admin user not found.',
            ], 404);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Failed to delete admin user.',
                'error' => $e->getMessage(),
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

    public function adminForgotPassword(Request $request)
    {
        try {

            $validator = Validator::make($request->all(), [
                'email' => 'required|email'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'status' => false,
                    'errors' => $validator->errors()
                ], 422);
            }

            $user = User::where('email', $request->email)->first();

            if (!$user) {
                return response()->json([
                    'status' => false,
                    'message' => 'User not found.'
                ], 404);
            }

            DB::table('password_reset_tokens')
                ->where('email', $user->email)
                ->delete();

            $plainToken = Str::random(64);

            DB::table('password_reset_tokens')->insert([
                'email' => $user->email,
                'token' => Hash::make($plainToken),
                'created_at' => Carbon::now(),
            ]);

            $resetLink = "https://admin-dashboard-nu-hazel-12.vercel.app/forgot-password?token={$plainToken}&email={$user->email}";

            Mail::to($user->email)->send(
                new AdminForgotPasswordMail(
                    $user->name,
                    $user->email,
                    $resetLink,
                    current_store_id()
                )
            );

            return response()->json([
                'status' => true,
                'message' => 'Password reset link sent successfully.'
            ]);
        } catch (\Exception $e) {

            Log::error($e);

            return response()->json([
                'status' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    public function adminResetPassword(Request $request)
    {
        try {

            $request->validate([
                'email' => 'required|email',
                'token' => 'required',
                'password' => 'required|min:8|confirmed',
            ]);

            $reset = DB::table('password_reset_tokens')
                ->where('email', $request->email)
                ->first();

            if (!$reset) {
                return response()->json([
                    'status' => false,
                    'message' => 'Invalid reset request.'
                ], 400);
            }

            // Check Token
            if (!Hash::check($request->token, $reset->token)) {
                return response()->json([
                    'status' => false,
                    'message' => 'Invalid or expired token.'
                ], 400);
            }

            // Expire after 60 minutes
            if (Carbon::parse($reset->created_at)->addMinutes(60)->isPast()) {

                DB::table('password_reset_tokens')
                    ->where('email', $request->email)
                    ->delete();

                return response()->json([
                    'status' => false,
                    'message' => 'Reset link has expired.'
                ], 400);
            }

            $user = User::where('email', $request->email)->first();

            if (!$user) {
                return response()->json([
                    'status' => false,
                    'message' => 'User not found.'
                ], 404);
            }

            $user->update([
                'password' => Hash::make($request->password)
            ]);

            DB::table('password_reset_tokens')
                ->where('email', $request->email)
                ->delete();

            Mail::to($user->email)->send(
                new PasswordResetSuccessMail($user)
            );

            return response()->json([
                'status' => true,
                'message' => 'Password reset successfully.'
            ]);
        } catch (\Exception $e) {

            return response()->json([
                'status' => false,
                'message' => $e->getMessage()
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
