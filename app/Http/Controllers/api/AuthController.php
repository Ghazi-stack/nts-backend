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
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\URL;
use App\Mail\MagicLinkMail;
use App\Services\StoreRegistryService;
use App\Services\TenantProvisioningService;
use App\Models\CentralUser;

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
            $isStaffUser         = $request->userRole >= 3 && $storeId; // Sales Staff, Manager, etc.

            // ---------- Dynamic Email Rules ----------
            $emailRules = ['required', 'email'];

            if (!$isOwnerRegistration) {
                $emailRules[] = Rule::unique('users', 'email')->where(function ($query) use ($storeId) {
                    return $query->where('store_id', $storeId);
                });
            }

            $validator = Validator::make($request->all(), [
                'firstName'     => 'required|string|max:100',
                'lastName'      => 'nullable|string|max:100',
                'email'         => $emailRules,
                'phoneNumber'   => 'nullable|string|max:20',
                'password'      => ($isSubUser || $isStaffUser) ? 'nullable|string|min:6' : 'required|string|min:6',
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
                'userRole'      => 'required|integer|exists:roles,user_role_id',
                'permissions'   => ($isSubUser || $isStaffUser) ? 'required|array|min:1' : 'nullable',
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

            // ===================== OWNER REGISTRATION =====================
            if ($isOwnerRegistration) {

                $existingUser = User::where('email', $request->email)->first();

                // Additional Store for Existing Owner
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

                    $registryStore = app(StoreRegistryService::class)
                    ->registerPendingStore($store, (int) $existingUser->id);

                    $registryStore = app(TenantProvisioningService::class)
                    ->provisionDatabase((int) $registryStore->id);

                    // Assign ALL permissions
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
                        $adminEmail = env('MAIL_FROM_SB_ADMIN', 'hkinza575@gmail.com');
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
                        'store_hash' => $registryStore->store_hash,
                        'provisioning_status' => $registryStore->provisioning_status,
                    ], 201);
                }

                // First Time Owner Registration
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

                $registryStore = app(StoreRegistryService::class)
                ->registerPendingStore($store, (int) $user->id);

                $registryStore = app(TenantProvisioningService::class)
                ->provisionDatabase((int) $registryStore->id);

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
                    $adminEmail = env('MAIL_FROM_SB_ADMIN', 'hkinza575@gmail.com');
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
                    'store_hash' => $registryStore->store_hash,
                    'provisioning_status' => $registryStore->provisioning_status,
                ], 201);
            }

            // ===================== SUB-USER + STAFF REGISTRATION =====================
            if ($isSubUser || $isStaffUser) {

                $existingUser = User::where('email', $request->email)
                    ->where('userRole', '!=', 2)
                    ->first();

                if ($existingUser) {
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
                        Log::error('STAFF STORE ACCESS EMAIL FAILED', [
                            'error' => $mailError->getMessage(),
                            'email' => $existingUser->email,
                        ]);
                    }

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
                }

                // New Staff / Sub-user
                $user = User::create([
                    'name'          => $request->firstName . ' ' . $request->lastName,
                    'email'         => $request->email,
                    'password'      => bcrypt(Str::random(16)),
                    'userRole'      => $request->userRole,
                    'phone_number'  => $request->phoneNumber,
                    'store_name'    => $request->storeName,
                    'business_size' => $request->businessSize,
                    'region'        => $request->region,
                    'store_id'      => $storeId,
                    'customer_id'   => null,
                ]);

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
                    Log::error('STAFF STORE ACCESS EMAIL FAILED', [
                        'error' => $mailError->getMessage(),
                        'email' => $user->email,
                    ]);
                }

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
                    'message'     => 'Staff user registered successfully. Store access email sent.',
                    'token'       => $token,
                    'expireAt'    => $expireAt,
                    'user'        => new UserResource($user),
                    'store'       => Store::find($storeId),
                    'stores'      => [Store::find($storeId)],
                    'permissions' => $userPermissions,
                    'isOwner'     => false,
                ], 201);
            }

            // ===================== CUSTOMER REGISTRATION =====================
            $storeId = current_store_id();

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
                    'user_type'       => null,
                ]);

                $customer = $existingGuestCustomer;

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

            try {
                Mail::to($user->email)->send(new UserRegistrationMail($user, $storeId));

                $adminEmail = env('MAIL_FROM_SB_ADMIN', 'hkinza575@gmail.com');
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

    // public function login(Request $request)
    // {
    //     try {
    //         $request->validate([
    //             'email'    => 'required|email',
    //             'password' => 'required',
    //         ]);

    //         $email    = $request->email;
    //         $password = $request->password;
    //         $storeId  = $request->header('storeId', $request->input('storeId'));

    //         /*
    //     |--------------------------------------------------------------------------
    //     | ADMIN / SUB-USER / STAFF LOGIN (userRole != 2)
    //     |--------------------------------------------------------------------------
    //     */
    //         // $admin = User::where('email', $email)
    //         //     ->where('userRole', '!=', 2) // Customer ko is function se block
    //         //     ->first();

    //         $admin = CentralUser::where('email', $email)->first();

    //         if (!$admin) {
    //             return response()->json([
    //                 'status'  => false,
    //                 'message' => 'Invalid email or password.',
    //             ], 401);
    //         }

    //         // Check if userRole exists in roles table
    //         $roleExists = DB::connection('central')
    //         ->table('roles')
    //         ->where('user_role_id', $admin->userRole)
    //         ->exists();

    //         if (!$roleExists) {
    //             return response()->json([
    //                 'status'  => false,
    //                 'message' => 'Invalid user role.',
    //             ], 401);
    //         }

    //         if (!Hash::check($password, $admin->password)) {
    //             return response()->json([
    //                 'status'  => false,
    //                 'message' => 'Invalid email or password.',
    //             ], 401);
    //         }

    //         // ── 2FA CHECK ──────────────────────────────────────────
    //         if ($admin->two_factor_enabled) {

    //             if ($admin->two_factor_type === 'email') {
    //                 $this->twoFactorService->sendEmailOtp($admin);
    //             }

    //             $pendingToken = $this->twoFactorService->storePendingLogin($admin);

    //             return response()->json([
    //                 'status'              => true,
    //                 'two_factor_required' => true,
    //                 'two_factor_type'     => $admin->two_factor_type,
    //                 'pending_token'       => $pendingToken,
    //                 'message'             => $admin->two_factor_type === 'email'
    //                     ? 'OTP sent to your email. Please verify to continue.'
    //                     : 'Please enter the code from your authenticator app.',
    //             ], 200);
    //         }
    //         // ── END 2FA ────────────────────────────────────────────

    //         // ===================== GET ALL STORES =====================
    //         $isOwner = false;

    //         $ownedStores = Store::where('user_id', $admin->id)->get();
    //         if ($ownedStores->isNotEmpty()) {
    //             $isOwner = true;
    //         }

    //         $permStoreIds = DB::connection('central')
    //             ->table('user_permissions')
    //             ->where('user_id', $admin->id)
    //             ->distinct()
    //             ->pluck('store_id')
    //             ->filter()
    //             ->toArray();

    //         $permStores = !empty($permStoreIds)
    //             ? Store::whereIn('id', $permStoreIds)->get()
    //             : collect();

    //         $stores = $ownedStores->merge($permStores)->unique('id')->values();

    //         if ($stores->isEmpty() && $admin->store_id) {
    //             $stores = Store::where('id', $admin->store_id)->get();
    //         }

    //         // ===================== TOKEN =====================
    //         $tokenResult    = $admin->createToken('adminToken');
    //         $plainTextToken = $tokenResult->plainTextToken;

    //         $expireAt    = now()->addHours(24);
    //         $accessToken = PersonalAccessToken::findToken($plainTextToken);

    //         if ($accessToken) {
    //             $accessToken->expires_at = $expireAt;
    //             $accessToken->save();
    //         }

    //         // ===================== PERMISSIONS =====================
    //         $permissionStoreId = $storeId ?: $admin->store_id;
    //         if (!$permissionStoreId && $stores->isNotEmpty()) {
    //             $permissionStoreId = $stores->first()->id;
    //         }

    //         $adminPermissions = $admin->permissions()
    //             ->when($permissionStoreId, function ($q) use ($permissionStoreId) {
    //                 $q->where('user_permissions.store_id', $permissionStoreId);
    //             })
    //             ->orderBy('group')
    //             ->orderBy('sort_order')
    //             ->get()
    //             ->groupBy('group')
    //             ->map(function ($items, $group) {
    //                 return [
    //                     'group' => $group,
    //                     'permissions' => $items->map(function ($p) {
    //                         return [
    //                             'id'    => $p->id,
    //                             'name'  => $p->name,
    //                             'label' => $p->label,
    //                             'slug'  => $p->slug,
    //                         ];
    //                     })->values(),
    //                 ];
    //             })->values();

    //         // ===================== STAFF LOG: LOGIN =====================
    //         StaffLog::record([
    //             'store_id'    => $permissionStoreId ?? ($stores->first()->id ?? null),
    //             'user_id'     => $admin->id,
    //             'username'    => $admin->email,
    //             'action'      => '(Successfully logged in)',
    //             'action_type' => 'login',
    //             'ip_address'  => $request->ip(),
    //         ]);

    //         return response()->json([
    //             'status'      => true,
    //             'message'     => $isOwner
    //                 ? 'Admin logged in successfully.'
    //                 : 'User logged in successfully.',
    //             'token'       => $plainTextToken,
    //             'expireAt'    => $expireAt->toDateTimeString(),
    //             'user'        => new UserResource($admin),
    //             'stores'      => StoreResource::collection($stores),
    //             'permissions' => $adminPermissions,
    //             'isOwner'     => $isOwner,
    //         ]);
    //     } catch (\Exception $e) {
    //         Log::error('Login failed: ' . $e->getMessage());

    //         return response()->json([
    //             'status'  => false,
    //             'message' => 'Something went wrong during login.',
    //         ], 500);
    //     }
    // }

    
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

            $central = DB::connection('central');

            /*
            |--------------------------------------------------------------------------
            | CENTRAL STAFF AUTHENTICATION
            |--------------------------------------------------------------------------
            */

            $admin = CentralUser::where('email', $email)->first();

            if (!$admin || !Hash::check($password, $admin->password)) {
                return response()->json([
                    'status'  => false,
                    'message' => 'Invalid email or password.',
                ], 401);
            }

            $roleExists = $central->table('roles')
                ->where('user_role_id', $admin->userRole)
                ->exists();

            if (!$roleExists) {
                return response()->json([
                    'status'  => false,
                    'message' => 'Invalid user role.',
                ], 401);
            }

            /*
            |--------------------------------------------------------------------------
            | TWO-FACTOR AUTHENTICATION
            |--------------------------------------------------------------------------
            */

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

            /*
            |--------------------------------------------------------------------------
            | CENTRAL STORE OWNERSHIP AND MEMBERSHIPS
            |--------------------------------------------------------------------------
            */

            $ownedStores = $central->table('stores')
                ->where('user_id', $admin->id)
                ->get();

            $isOwner = $ownedStores->isNotEmpty();

            $membershipStoreIds = $central->table('store_user')
                ->where('user_id', $admin->id)
                ->pluck('store_id')
                ->map(fn ($id) => (int) $id)
                ->all();

            $permissionStoreIds = $central->table('user_permissions')
                ->where('user_id', $admin->id)
                ->distinct()
                ->pluck('store_id')
                ->map(fn ($id) => (int) $id)
                ->all();

            $accessibleStoreIds = array_values(array_unique(array_merge(
                $ownedStores->pluck('id')->map(fn ($id) => (int) $id)->all(),
                $membershipStoreIds,
                $permissionStoreIds
            )));

            $stores = !empty($accessibleStoreIds)
                ? $central->table('stores')
                    ->whereIn('id', $accessibleStoreIds)
                    ->orderBy('id')
                    ->get()
                : collect();

            /*
            |--------------------------------------------------------------------------
            | RESOLVE REQUESTED STORE
            |--------------------------------------------------------------------------
            */

            $selectedStore = null;

            if ($storeId !== null && $storeId !== '') {
                // Prefer mapping the frontend's legacy store ID.
                $selectedStore = $central->table('stores')
                    ->where('legacy_store_id', $storeId)
                    ->first();

                // If no legacy mapping exists, try the central registry ID.
                if (!$selectedStore) {
                    $selectedStore = $stores->first(
                        fn ($store) => (int) $store->id === (int) $storeId
                    );
                }

                if (
                    !$selectedStore ||
                    !in_array(
                        (int) $selectedStore->id,
                        $accessibleStoreIds,
                        true
                    )
                ) {
                    return response()->json([
                        'status'  => false,
                        'message' => 'You do not have access to this store.',
                    ], 403);
                }
            }

            $permissionStoreId = $selectedStore
                ? (int) $selectedStore->id
                : null;

            /*
            |--------------------------------------------------------------------------
            | CENTRAL STORE-SCOPED PERMISSIONS
            |--------------------------------------------------------------------------
            */

            \Log::info('Admin login permission diagnostics', [
                'user_id' => $admin->id,
                'user_connection' => $admin->getConnectionName(),
                'selected_store_id' => $selectedStore->id ?? null,
                'requested_store_id' => $storeId,
                'permission_store_id' => $permissionStoreId,
                'permission_connection' => $admin->permissions()->getRelated()->getConnectionName(),
                'pivot_count' => $permissionStoreId
                    ? $central->table('user_permissions')
                        ->where('user_id', $admin->id)
                        ->where('store_id', $permissionStoreId)
                        ->count()
                    : null,
            ]);

            $adminPermissions = $permissionStoreId
                ? $admin->permissions()
                    ->wherePivot('store_id', $permissionStoreId)
                    ->orderBy('group')
                    ->orderBy('sort_order')
                    ->get()
                    ->groupBy('group')
                    ->map(function ($items, $group) {
                        return [
                            'group' => $group,
                            'permissions' => $items->map(function ($permission) {
                                return [
                                    'id'    => $permission->id,
                                    'name'  => $permission->name,
                                    'label' => $permission->label,
                                    'slug'  => $permission->slug,
                                ];
                            })->values(),
                        ];
                    })->values()
                : collect();

            /*
            |--------------------------------------------------------------------------
            | CREATE CENTRAL STAFF TOKEN
            |--------------------------------------------------------------------------
            */

            $tokenResult = $admin->createToken('adminToken');
            $plainTextToken = $tokenResult->plainTextToken;
            $expireAt = now()->addHours(24);

            $accessToken = PersonalAccessToken::findToken($plainTextToken);

            if ($accessToken) {
                $accessToken->expires_at = $expireAt;
                $accessToken->save();
            }

            /*
            |--------------------------------------------------------------------------
            | STORE-SPECIFIC STAFF LOG
            |--------------------------------------------------------------------------
            */

            if ($selectedStore) {
                \Log::info('Selected store tenant fields before login connection', [
                    'id' => $selectedStore->id ?? null,
                    'name' => $selectedStore->name ?? null,
                    'tenant_database' => $selectedStore->tenant_database ?? null,
                    'db_username' => $selectedStore->db_username ?? null,
                    'has_encrypted_password' => !empty($selectedStore->db_password_encrypted),
                    'db_host' => $selectedStore->db_host ?? null,
                    'db_port' => $selectedStore->db_port ?? null,
                ]);  
                app(\App\Services\TenantDatabaseManager::class)
                    ->connectForStore($selectedStore);  

                try {
                    StaffLog::record([
                        // Tenant logs retain the legacy store ID.
                        'store_id'    => $selectedStore->legacy_store_id,
                        'user_id'     => $admin->id,
                        'username'    => $admin->email,
                        'action'      => '(Successfully logged in)',
                        'action_type' => 'login',
                        'ip_address'  => $request->ip(),
                    ]);
                } finally {
                    app(\App\Services\TenantDatabaseManager::class)
                        ->disconnect();
                }
            }

            /*
            |--------------------------------------------------------------------------
            | RESPONSE
            |--------------------------------------------------------------------------
            */

            return response()->json([
                'status'      => true,
                'message'     => $isOwner
                    ? 'Admin logged in successfully.'
                    : 'User logged in successfully.',
                'token'       => $plainTextToken,
                'expireAt'    => $expireAt->toDateTimeString(),
                'user'        => new UserResource($admin),
                'stores'      => StoreResource::collection($stores),
                'permissions' => $adminPermissions,
                'isOwner'     => $isOwner,
            ]);
        } catch (\Exception $e) {
            Log::error('Login failed: ' . $e->getMessage(), [
                'exception' => $e,
            ]);

            return response()->json([
                'status'  => false,
                'message' => 'Something went wrong during login.',
            ], 500);
        }
    }


    // public function customerLogin(Request $request)
    // {
    //     try {
    //         $request->validate([
    //             'email'    => 'required|email',
    //             'password' => 'required',
    //         ]);

    //         $email    = $request->email;
    //         $password = $request->password;
    //         $storeId  = $request->header('storeId', $request->input('storeId'));

    //         /*
    //     |--------------------------------------------------------------------------
    //     | CUSTOMER LOGIN (userRole = 2)
    //     |--------------------------------------------------------------------------
    //     */

    //         $customer = User::where('email', $email)
    //             ->where('userRole', 2)
    //             ->where('store_id', '=', $storeId)
    //             ->first();

    //         if (!$customer) {
    //             return response()->json([
    //                 'status'  => false,
    //                 'message' => 'Invalid email or password.',
    //             ], 401);
    //         }

    //         if (!Hash::check($password, $customer->password)) {
    //             return response()->json([
    //                 'status'  => false,
    //                 'message' => 'Invalid email or password.',
    //             ], 401);
    //         }

    //         /*
    //     |--------------------------------------------------------------------------
    //     | STORE ID
    //     |--------------------------------------------------------------------------
    //     */

    //         if (!$storeId) {
    //             return response()->json([
    //                 'status'  => false,
    //                 'message' => 'Store ID is required for customer login.',
    //             ], 422);
    //         }

    //         $store = Store::where('id', $storeId)->first();

    //         if (!$store) {
    //             return response()->json([
    //                 'status'  => false,
    //                 'message' => 'Invalid store.',
    //             ], 404);
    //         }

    //         /*
    //     |--------------------------------------------------------------------------
    //     | FORCE PASSWORD RESET
    //     |--------------------------------------------------------------------------
    //     */

    //         if ($customer->customer && $customer->customer->force_password_reset) {

    //             DB::table('password_reset_tokens')
    //                 ->where('email', $customer->email)
    //                 ->delete();

    //             Password::sendResetLink([
    //                 'email' => $customer->email
    //             ]);

    //             return response()->json([
    //                 'status'               => false,
    //                 'force_password_reset' => true,
    //                 'message'              => 'Password reset is required. A reset link has been sent to your email.',
    //             ], 403);
    //         }

    //         /*
    //     |--------------------------------------------------------------------------
    //     | 2FA CHECK
    //     |--------------------------------------------------------------------------
    //     */

    //         if ($customer->two_factor_enabled) {

    //             if ($customer->two_factor_type === 'email') {
    //                 $this->twoFactorService->sendEmailOtp($customer);
    //             }

    //             $pendingToken = $this->twoFactorService->storePendingLogin($customer);

    //             return response()->json([
    //                 'status'              => true,
    //                 'two_factor_required' => true,
    //                 'two_factor_type'     => $customer->two_factor_type,
    //                 'pending_token'       => $pendingToken,
    //                 'message'             => $customer->two_factor_type === 'email'
    //                     ? 'OTP sent to your email. Please verify to continue.'
    //                     : 'Please enter the code from your authenticator app.',
    //             ], 200);
    //         }

    //         /*
    //         |--------------------------------------------------------------------------
    //         | SINGLE SESSION
    //         |--------------------------------------------------------------------------
    //         | Create the new token first.
    //         | Then remove only the customer's previous customer sessions.
    //         |--------------------------------------------------------------------------
    //         */


    //         $existingTokenIds = $customer->tokens()
    //             ->whereIn('name', ['customerToken', 'customer_login'])
    //             ->pluck('id')
    //             ->toArray();

    //         /*
    //         |--------------------------------------------------------------------------
    //         | Create new customer token
    //         |--------------------------------------------------------------------------
    //         */

    //         $tokenResult    = $customer->createToken('customerToken');
    //         $plainTextToken = $tokenResult->plainTextToken;

    //         $expireAt = now()->addHours(24);

    //         $accessToken = PersonalAccessToken::findToken($plainTextToken);

    //         if ($accessToken) {
    //             $accessToken->expires_at = $expireAt;
    //         }

    //         /*
    //         |--------------------------------------------------------------------------
    //         | Delete previous sessions only
    //         |--------------------------------------------------------------------------
    //         */

    //         if (!empty($existingTokenIds)) {
    //             $customer->tokens()
    //                 ->whereIn('id', $existingTokenIds)
    //                 ->delete();
    //         }

    //         /*
    //         |--------------------------------------------------------------------------
    //         | Save new token expiry
    //         |--------------------------------------------------------------------------
    //         */

    //         if ($accessToken) {
    //             $accessToken->save();
    //         }

    //         /*
    //     |--------------------------------------------------------------------------
    //     | RESPONSE
    //     |--------------------------------------------------------------------------
    //     */

    //         return response()->json([
    //             'status'      => true,
    //             'message'     => 'Customer logged in successfully.',
    //             'token'       => $plainTextToken,
    //             'expireAt'    => $expireAt->toDateTimeString(),
    //             'store'       => new StoreResource($store),
    //             'customer'    => new CustomerResource($customer->customer),
    //             'permissions' => null,
    //         ]);
    //     } catch (\Exception $e) {

    //         Log::error('Customer login failed: ' . $e->getMessage());

    //         return response()->json([
    //             'status'  => false,
    //             'message' => 'Something went wrong during login.',
    //         ], 500);
    //     }
    // }

    
    public function customerLogin(Request $request)
    {
        try {
            $request->validate([
                'email'    => 'required|email',
                'password' => 'required',
            ]);

            $email = $request->email;
            $password = $request->password;

            /*
            |--------------------------------------------------------------------------
            | Resolve store using existing middleware and helpers
            |--------------------------------------------------------------------------
            */

            $store = current_store_registry();
            $storeHash = current_store_hash();
            $storeId = current_store_id();

            if (!$store || !$storeHash) {
                return response()->json([
                    'status'  => false,
                    'message' => 'A valid X-Store-Hash header is required.',
                ], 422);
            }

            if (
                !isset($store->store_hash) ||
                !hash_equals((string) $store->store_hash, $storeHash)
            ) {
                return response()->json([
                    'status'  => false,
                    'message' => 'Invalid store.',
                ], 404);
            }

            if (($store->provisioning_status ?? null) !== 'active') {
                return response()->json([
                    'status'  => false,
                    'message' => 'The store is not active.',
                ], 403);
            }

            if ($storeId === null || $storeId === '') {
                return response()->json([
                    'status'  => false,
                    'message' => 'Store ID could not be resolved.',
                ], 422);
            }

            /*
            |--------------------------------------------------------------------------
            | Connect to the selected tenant BEFORE querying customer users
            |--------------------------------------------------------------------------
            */

            $tenantManager = app(
                \App\Services\TenantDatabaseManager::class
            );

            $tenantManager->connectForStore($store);

            try {
                /*
                |--------------------------------------------------------------------------
                | Authenticate against the tenant users table
                |--------------------------------------------------------------------------
                */

                $customer = User::on('tenant')
                    ->where('email', $email)
                    ->where('userRole', 2)
                    ->where('store_id', $storeId)
                    ->first();

                if (
                    !$customer ||
                    !Hash::check($password, $customer->password)
                ) {
                    return response()->json([
                        'status'  => false,
                        'message' => 'Invalid email or password.',
                    ], 401);
                }

                /*
                |--------------------------------------------------------------------------
                | Load customer profile from the same tenant
                |--------------------------------------------------------------------------
                */

                $customerProfile = null;

                if (!empty($customer->customer_id)) {
                    $customerProfile = \App\Models\Customer::on('tenant')
                        ->where('id', $customer->customer_id)
                        ->first();
                } else {
                    // Fall back to the model relationship if no customer_id
                    // is available directly on the user record.
                    $customerProfile = $customer->customer;
                }

                /*
                |--------------------------------------------------------------------------
                | Force password reset
                |--------------------------------------------------------------------------
                */

                if (
                    $customerProfile &&
                    $customerProfile->force_password_reset
                ) {
                    DB::connection('tenant')
                        ->table('password_reset_tokens')
                        ->where('email', $customer->email)
                        ->delete();

                    Password::sendResetLink([
                        'email' => $customer->email,
                    ]);

                    return response()->json([
                        'status'                => false,
                        'force_password_reset' => true,
                        'message'               => 'Password reset is required. A reset link has been sent to your email.',
                    ], 403);
                }

                /*
                |--------------------------------------------------------------------------
                | Two-factor authentication
                |--------------------------------------------------------------------------
                */

                if ($customer->two_factor_enabled) {
                    if ($customer->two_factor_type === 'email') {
                        $this->twoFactorService->sendEmailOtp($customer);
                    }

                    $pendingToken = $this->twoFactorService
                        ->storePendingLogin($customer);

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
                | Single active customer session
                |--------------------------------------------------------------------------
                */

                $existingTokenIds = $customer->tokens()
                    ->whereIn('name', [
                        'customerToken',
                        'customer_login',
                    ])
                    ->pluck('id')
                    ->toArray();

                $tokenResult = $customer->createToken('customerToken');
                $plainTextToken = $tokenResult->plainTextToken;
                $expireAt = now()->addHours(24);

                // Sanctum stores the SHA-256 hash, not the plain-text token.
                $accessToken = \App\Models\PersonalAccessToken::on('tenant')
                    ->where('token', hash('sha256', $plainTextToken))
                    ->first();

                if ($accessToken) {
                    $accessToken->expires_at = $expireAt;
                    $accessToken->save();
                }

                if (!empty($existingTokenIds)) {
                    $customer->tokens()
                        ->whereIn('id', $existingTokenIds)
                        ->delete();
                }

                /*
                |--------------------------------------------------------------------------
                | Response
                |--------------------------------------------------------------------------
                */

                return response()->json([
                    'status'      => true,
                    'message'     => 'Customer logged in successfully.',
                    'token'       => $plainTextToken,
                    'expireAt'    => $expireAt->toDateTimeString(),
                    // 'store'       => $store,
                    'customer'    => $customerProfile
                        ? new CustomerResource($customerProfile)
                        : null,
                    'permissions' => null,
                ]);
            } finally {
                $tenantManager->disconnect();
            }
        } catch (\Throwable $e) {
            Log::error('Customer login failed', [
                'message' => $e->getMessage(),
                'file'    => $e->getFile(),
                'line'    => $e->getLine(),
            ]);

            return response()->json([
                'status'  => false,
                'message' => 'Something went wrong during login.',
            ], 500);
        }
    }


    public function checkCustomerToken(Request $request)
    {
        $tenantManager = null;

        try {
            /*
            |--------------------------------------------------------------------------
            | Initial diagnostic log
            |--------------------------------------------------------------------------
            */

            Log::info('Customer token check started', [
                'path' => $request->path(),
                'method' => $request->method(),
                'has_bearer_token' => $request->bearerToken() !== null,
                'has_token_header' => $request->hasHeader('token'),
                'has_store_hash' => $request->hasHeader('X-Store-Hash'),
                'store_hash_resolved' => current_store_hash() !== null,
                'store_registry_resolved' => current_store_registry() !== null,
            ]);

            /*
            |--------------------------------------------------------------------------
            | Resolve store and token
            |--------------------------------------------------------------------------
            */

            $store = current_store_registry();
            $storeHash = current_store_hash();
            $storeId = current_store_id();

            $token = $request->bearerToken()
                ?? $request->header('token')
                ?? $request->input('token');

            Log::info('Customer token context resolved', [
                'store_id' => $storeId,
                'registry_id' => $store->id ?? null,
                'legacy_store_id' => $store->legacy_store_id ?? null,
                'store_status' => $store->provisioning_status ?? null,
                'store_hash_present' => !empty($storeHash),
                'token_present' => !empty($token),
            ]);

            if (!$store || !$storeHash) {
                Log::warning('Customer token rejected', [
                    'reason' => 'store_context_missing',
                ]);

                return response()->json([
                    'status' => false,
                    'message' => 'A valid X-Store-Hash header is required.',
                ], 422);
            }

            if (
                empty($store->store_hash) ||
                !hash_equals((string) $store->store_hash, (string) $storeHash)
            ) {
                Log::warning('Customer token rejected', [
                    'reason' => 'store_hash_mismatch',
                    'registry_id' => $store->id ?? null,
                ]);

                return response()->json([
                    'status' => false,
                    'message' => 'Invalid store.',
                ], 404);
            }

            if (($store->provisioning_status ?? null) !== 'active') {
                Log::warning('Customer token rejected', [
                    'reason' => 'store_not_active',
                    'registry_id' => $store->id ?? null,
                    'store_status' => $store->provisioning_status ?? null,
                ]);

                return response()->json([
                    'status' => false,
                    'message' => 'The store is not active.',
                ], 403);
            }

            if ($storeId === null || $storeId === '') {
                Log::warning('Customer token rejected', [
                    'reason' => 'store_id_missing',
                    'registry_id' => $store->id ?? null,
                ]);

                return response()->json([
                    'status' => false,
                    'message' => 'Store ID could not be resolved.',
                ], 422);
            }

            if (!$token) {
                Log::warning('Customer token rejected', [
                    'reason' => 'token_missing',
                    'store_id' => $storeId,
                ]);

                return response()->json([
                    'status' => false,
                    'message' => 'Token expired',
                ], 401);
            }

            /*
            |--------------------------------------------------------------------------
            | Connect to the selected tenant database
            |--------------------------------------------------------------------------
            */

            $tenantManager = app(
                \App\Services\TenantDatabaseManager::class
            );

            $tenantManager->connectForStore($store);

            $tenantDatabase = DB::connection('tenant')->getDatabaseName();

            Log::info('Customer token check tenant connected', [
                'store_id' => $storeId,
                'registry_id' => $store->id ?? null,
                'tenant_database' => $tenantDatabase,
                'tenant_store_attribute' =>
                    request()->attributes->get('tenant_store') !== null,
            ]);

            /*
            |--------------------------------------------------------------------------
            | Parse the Sanctum token
            |--------------------------------------------------------------------------
            |
            | Supports:
            | 1. ID|plaintext-token
            | 2. plaintext-token
            |
            */

            $plainToken = $token;
            $tokenId = null;

            if (str_contains($token, '|')) {
                [$possibleId, $possiblePlainToken] = explode('|', $token, 2);

                if (
                    $possibleId !== '' &&
                    ctype_digit($possibleId) &&
                    $possiblePlainToken !== ''
                ) {
                    $tokenId = $possibleId;
                    $plainToken = $possiblePlainToken;
                }
            }

            $hashedToken = hash('sha256', $plainToken);

            /*
            |--------------------------------------------------------------------------
            | Find the token in the tenant database
            |--------------------------------------------------------------------------
            */

            $tokenQuery = \App\Models\PersonalAccessToken::on('tenant');

            if ($tokenId !== null) {
                $accessToken = $tokenQuery
                    ->whereKey((int) $tokenId)
                    ->where('token', $hashedToken)
                    ->first();
            } else {
                $accessToken = $tokenQuery
                    ->where('token', $hashedToken)
                    ->first();
            }

            Log::info('Customer token lookup result', [
                'found' => $accessToken !== null,
                'lookup_by_id' => $tokenId !== null,
                'token_id' => $tokenId,
                'token_name' => $accessToken?->name,
                'tokenable_type' => $accessToken?->tokenable_type,
                'tokenable_id' => $accessToken?->tokenable_id,
                'tenant_database' => $tenantDatabase,
            ]);

            if (!$accessToken) {
                Log::warning('Customer token rejected', [
                    'reason' => 'token_not_found',
                    'store_id' => $storeId,
                    'tenant_database' => $tenantDatabase,
                    'lookup_by_id' => $tokenId !== null,
                ]);

                return response()->json([
                    'status' => false,
                    'message' => 'Token expired',
                ], 401);
            }

            /*
            |--------------------------------------------------------------------------
            | Validate token expiration
            |--------------------------------------------------------------------------
            */

            if (
                $accessToken->expires_at &&
                $accessToken->expires_at->isPast()
            ) {
                Log::warning('Customer token rejected', [
                    'reason' => 'token_expired',
                    'token_id' => $accessToken->getKey(),
                    'expires_at' => $accessToken->expires_at->toDateTimeString(),
                ]);

                $accessToken->delete();

                return response()->json([
                    'status' => false,
                    'message' => 'Token expired',
                ], 401);
            }

            /*
            |--------------------------------------------------------------------------
            | Validate token name
            |--------------------------------------------------------------------------
            */

            $allowedTokenNames = [
                'customerToken',
                'customer_login',
                'auth_token',
            ];

            if (!in_array($accessToken->name, $allowedTokenNames, true)) {
                Log::warning('Customer token rejected', [
                    'reason' => 'token_name_not_allowed',
                    'token_id' => $accessToken->getKey(),
                    'token_name' => $accessToken->name,
                ]);

                return response()->json([
                    'status' => false,
                    'message' => 'Token expired',
                ], 401);
            }

            /*
            |--------------------------------------------------------------------------
            | Validate token owner type
            |--------------------------------------------------------------------------
            */

            $expectedMorphType = (new User())->getMorphClass();

            if ($accessToken->tokenable_type !== $expectedMorphType) {
                Log::warning('Customer token rejected', [
                    'reason' => 'token_owner_type_mismatch',
                    'token_id' => $accessToken->getKey(),
                    'actual_type' => $accessToken->tokenable_type,
                    'expected_type' => $expectedMorphType,
                ]);

                return response()->json([
                    'status' => false,
                    'message' => 'Token expired',
                ], 401);
            }

            /*
            |--------------------------------------------------------------------------
            | Resolve token owner from the tenant database
            |--------------------------------------------------------------------------
            */

            $user = User::on('tenant')
                ->whereKey($accessToken->tokenable_id)
                ->first();

            if (!$user) {
                Log::warning('Customer token rejected', [
                    'reason' => 'token_owner_not_found',
                    'token_id' => $accessToken->getKey(),
                    'tokenable_id' => $accessToken->tokenable_id,
                    'tenant_database' => $tenantDatabase,
                ]);

                return response()->json([
                    'status' => false,
                    'message' => 'Token expired',
                ], 401);
            }

            /*
            |--------------------------------------------------------------------------
            | Validate customer access
            |--------------------------------------------------------------------------
            */

            $isNormalCustomer = (int) $user->userRole === 2;

            $isLoginAsUser = !empty($user->customer_id)
                && (int) $user->store_id === (int) $storeId;

            Log::info('Customer token owner resolved', [
                'user_id' => $user->getKey(),
                'user_role' => $user->userRole,
                'customer_id' => $user->customer_id,
                'user_store_id' => $user->store_id,
                'requested_store_id' => $storeId,
                'is_normal_customer' => $isNormalCustomer,
                'is_login_as_user' => $isLoginAsUser,
            ]);

            if (!$isNormalCustomer && !$isLoginAsUser) {
                Log::warning('Customer token rejected', [
                    'reason' => 'customer_access_denied',
                    'user_id' => $user->getKey(),
                    'user_role' => $user->userRole,
                    'user_store_id' => $user->store_id,
                    'requested_store_id' => $storeId,
                ]);

                return response()->json([
                    'status' => false,
                    'message' => 'Token expired',
                ], 401);
            }

            /*
            |--------------------------------------------------------------------------
            | Load customer profile from the tenant database
            |--------------------------------------------------------------------------
            */

            $customerProfile = null;

            if (!empty($user->customer_id)) {
                $customerProfile = \App\Models\Customer::on('tenant')
                    ->whereKey($user->customer_id)
                    ->first();
            } else {
                $customerProfile = $user->customer;
            }

            Log::info('Customer token check successful', [
                'user_id' => $user->getKey(),
                'customer_found' => $customerProfile !== null,
                'store_id' => $storeId,
                'tenant_database' => $tenantDatabase,
            ]);

            return response()->json([
                'status' => true,
                'message' => 'Already existed',
                'store' => $store,
                'customer' => $customerProfile
                    ? new CustomerResource($customerProfile)
                    : null,
            ]);
        } catch (\Throwable $e) {
            Log::error('Customer token check failed', [
                'message' => $e->getMessage(),
                'exception' => get_class($e),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'path' => $request->path(),
            ]);

            return response()->json([
                'status' => false,
                'message' => 'Something went wrong during token check.',
            ], 500);
        } finally {
            if ($tenantManager) {
                try {
                    $tenantManager->disconnect();

                    Log::info('Customer token check tenant disconnected');
                } catch (\Throwable $disconnectException) {
                    Log::error('Customer token tenant disconnect failed', [
                        'message' => $disconnectException->getMessage(),
                        'exception' => get_class($disconnectException),
                    ]);
                }
            }
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

            // 2. Is store ke saare users nikalo (Customer ke ilawa)
            $userIds = DB::table('user_permissions')
                ->where('store_id', $storeId)
                ->distinct()
                ->pluck('user_id');

            $users = User::whereIn('id', $userIds)
                ->where('userRole', '!=', 2)          // Customer exclude
                ->where('id', '!=', $currentUser->id) // khud ko exclude
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
                ->where('userRole', '!=', 2)   // Customer exclude, baaki saare roles
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
                ->where('userRole', '!=', 2)   // Customer exclude, baaki saare roles
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
                ->where('userRole', '!=', 2)   // Customer exclude, baaki saare roles
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
                'email'    => 'required|email|exists:users,email',
                'password' => 'required|min:8',
                'token'    => 'required',
            ]);

            $request->merge([
                'password_confirmation' => $request->password,
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

                    $customerId = $user->customer_id ?? null;

                    if (!$customerId && $user->customer) {
                        $customerId = $user->customer->id;
                    }

                    if ($customerId) {
                        \App\Models\Customer::where('id', $customerId)
                            ->where('force_password_reset', 1)
                            ->update([
                                'force_password_reset' => 0,
                            ]);
                    }
                }
            }

            return response()->json([
                'status'  => $status === Password::PASSWORD_RESET,
                'message' => __($status),
            ], $status === Password::PASSWORD_RESET ? 200 : 400);
        } catch (\Exception $e) {
            return response()->json([
                'status'  => false,
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
                    'status'  => false,
                    'message' => 'Store not selected.',
                ], 400);
            }

            $user = User::where('customer_id', $request->customer_id)
                ->where('store_id', $storeId)
                ->first();

            if (!$user) {
                return response()->json([
                    'status'  => false,
                    'message' => 'Customer user not found.',
                ], 404);
            }

            // Purani sari customer sessions expire
            $user->tokens()
                ->whereIn('name', ['customerToken', 'customer_login'])
                ->delete();

            $tokenResult    = $user->createToken('customer_login');
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
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status'  => false,
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

    // public function sendMagicLink(Request $request)
    // {
    //     $request->validate([
    //         'email' => 'required|email',
    //     ]);

    //     $storeId = current_store_id();

    //     if (!$storeId || !Store::where('id', $storeId)->exists()) {
    //         return response()->json([
    //             'status' => false,
    //             'message' => 'Invalid or missing store.',
    //         ], 422);
    //     }

    //     $storeId = (int) $storeId;
    //     $email = strtolower(trim($request->email));

    //     $user = User::whereRaw('LOWER(email) = ?', [$email])
    //         ->where('userRole', 2)
    //         ->where('store_id', $storeId)
    //         ->first();

    //     Log::info('Magic login lookup', [
    //         'email' => $email,
    //         'store_id' => $storeId,
    //         'user_found' => (bool) $user,
    //     ]);    
    //     // Do not reveal whether an account exists.
    //     if (!$user) {
    //         return response()->json([
    //             'status' => true,
    //             'message' =>
    //                 'If an account exists, a sign-in link will be sent.',
    //         ]);
    //     }

    //     // Do not silently bypass your existing 2FA requirement.
    //     if ($user->two_factor_enabled) {
    //         return response()->json([
    //             'status' => false,
    //             'message' =>
    //                 'This account requires additional verification. Please use the existing verification flow.',
    //         ], 403);
    //     }

    //     // Generate a cryptographically secure, one-time token.
    //     $plainToken = Str::random(64);

    //     Cache::put(
    //         'magic_login:' . hash('sha256', $plainToken),
    //         [
    //             'user_id' => $user->id,
    //             'store_id' => $storeId,
    //         ],
    //         now()->addMinutes(15)
    //     );

    //     $loginUrl = rtrim(config('app.frontend_url'), '/')
    //     . '/auth/magic-login?token=' . urlencode($plainToken);

    //     try {
    //         Mail::to($user->email)->send(
    //             new MagicLinkMail($user, $storeId, $loginUrl)
    //         );
    //     } catch (\Throwable $e) {
    //         Cache::forget(
    //             'magic_login:' . hash('sha256', $plainToken)
    //         );

    //         Log::error('Magic login email failed', [
    //             'user_id' => $user->id,
    //             'error' => $e->getMessage(),
    //         ]);

    //         return response()->json([
    //             'status' => false,
    //             'message' => 'Unable to send the sign-in email. Please try again.',
    //         ], 500);
    //     }

    //     return response()->json([
    //         'status' => true,
    //         'message' =>
    //             'If an account exists, a sign-in link will be sent.',
    //     ]);
    // }


    // public function verifyMagicLink(Request $request)
    // {
    //     $request->validate([
    //         'token' => 'required|string',
    //     ]);

    //     $storeId = current_store_id();

    //     if (!$storeId) {
    //         return response()->json([
    //             'status' => false,
    //             'message' => 'Store not selected.',
    //         ], 400);
    //     }

    //     $storeId = (int) $storeId;

    //     if (!Store::where('id', $storeId)->exists()) {
    //         return response()->json([
    //             'status' => false,
    //             'message' => 'Invalid store.',
    //         ], 422);
    //     }

    //     // Retrieve and consume the one-time magic token.
    //     $cacheKey = 'magic_login:' . hash(
    //         'sha256',
    //         $request->input('token')
    //     );

    //     $loginData = Cache::pull($cacheKey);

    //     if (!$loginData) {
    //         return response()->json([
    //             'status' => false,
    //             'message' => 'This sign-in link is invalid, expired, or already used.',
    //         ], 401);
    //     }

    //     // Ensure the token belongs to the current store.
    //     if ((int) $loginData['store_id'] !== $storeId) {
    //         return response()->json([
    //             'status' => false,
    //             'message' => 'Invalid sign-in request.',
    //         ], 401);
    //     }

    //     // Find the customer using the server-side token data.
    //     $user = User::whereKey($loginData['user_id'])
    //         ->where('userRole', 2)
    //         ->where('store_id', $storeId)
    //         ->first();

    //     if (!$user) {
    //         return response()->json([
    //             'status' => false,
    //             'message' => 'Invalid sign-in request.',
    //         ], 401);
    //     }

    //     // Do not bypass two-factor authentication.
    //     if ($user->two_factor_enabled) {
    //         return response()->json([
    //             'status' => false,
    //             'message' => 'Additional verification is required. Please use the existing verification flow.',
    //         ], 403);
    //     }

    //     $store = Store::find($storeId);

    //     if (!$store) {
    //         return response()->json([
    //             'status' => false,
    //             'message' => 'Store not found.',
    //         ], 404);
    //     }

    //     // Preserve the existing password-reset policy.
    //     if ($user->customer && $user->customer->force_password_reset) {
    //         return response()->json([
    //             'status' => false,
    //             'force_password_reset' => true,
    //             'message' => 'Password reset is required before you can continue.',
    //         ], 403);
    //     }

    //     // Match the existing loginAsCustomer() session behavior.
    //     $user->tokens()
    //         ->whereIn('name', ['customerToken', 'customer_login'])
    //         ->delete();

    //     $tokenResult = $user->createToken('customer_login');
    //     $plainTextToken = $tokenResult->plainTextToken;

    //     $expireAt = now()->addMinutes(15);

    //     $accessToken = PersonalAccessToken::findToken(
    //         $plainTextToken
    //     );

    //     if ($accessToken) {
    //         $accessToken->expires_at = $expireAt;
    //         $accessToken->save();
    //     }

    //     return response()->json([
    //         'status' => true,
    //         'message' => 'Customer logged in successfully.',
    //         'token' => $plainTextToken,
    //         'expireAt' => $expireAt->toDateTimeString(),
    //         'store' => new StoreResource($store),
    //         'customer' => new CustomerResource($user->customer),
    //         'permissions' => null,
    //     ]);
    // }



    public function sendMagicLink(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
        ]);

        $storeId = current_store_id();

        if (!$storeId || !Store::where('id', $storeId)->exists()) {
            return response()->json([
                'status' => false,
                'message' => 'Invalid or missing store.',
            ], 422);
        }

        $storeId = (int) $storeId;
        $email = strtolower(trim($request->email));

        $user = User::whereRaw('LOWER(email) = ?', [$email])
            ->where('userRole', 2)
            ->where('store_id', $storeId)
            ->first();

        Log::info('Magic login lookup', [
            'store_id' => $storeId,
            'user_found' => (bool) $user,
        ]);

        // Do not reveal whether an account exists.
        if (!$user) {
            return response()->json([
                'status' => true,
                'message' => 'If an account exists, a sign-in link will be sent.',
            ]);
        }

        // Preserve the existing 2FA requirement.
        if ($user->two_factor_enabled) {
            return response()->json([
                'status' => false,
                'message' => 'This account requires additional verification. Please use the existing verification flow.',
            ], 403);
        }

        // Generate a secure, one-time token.
        $plainToken = Str::random(64);

        $cacheKey = 'magic_login:' . hash('sha256', $plainToken);

        // Magic link expires after 15 minutes.
        Cache::put(
            $cacheKey,
            [
                'user_id' => $user->id,
                'store_id' => $storeId,
            ],
            now()->addMinutes(15)
        );

        // Keep the email URL token-only.
        $store = Store::find($storeId);

        $loginUrl = rtrim($store->base_url, '/')
            . '/?actiontoken=' . urlencode($plainToken);

        try {
            Mail::to($user->email)->send(
                new MagicLinkMail($user, $storeId, $loginUrl)
            );
        } catch (\Throwable $e) {
            Cache::forget($cacheKey);

            Log::error('Magic login email failed', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'status' => false,
                'message' => 'Unable to send the sign-in email. Please try again.',
            ], 500);
        }

        return response()->json([
            'status' => true,
            'message' => 'If an account exists, a sign-in link will be sent.',
        ]);
    }



    public function verifyMagicLink(Request $request)
    {
        $request->validate([
            'token' => 'required|string',
        ]);

        $storeId = current_store_id();

        if (!$storeId) {
            return response()->json([
                'status' => false,
                'message' => 'Store not selected.',
            ], 400);
        }

        $storeId = (int) $storeId;

        if (!Store::where('id', $storeId)->exists()) {
            return response()->json([
                'status' => false,
                'message' => 'Invalid store.',
            ], 422);
        }

        // Retrieve and consume the one-time magic token.
        $cacheKey = 'magic_login:' . hash(
            'sha256',
            $request->input('token')
        );

        $loginData = Cache::pull($cacheKey);

        if (!$loginData) {
            return response()->json([
                'status' => false,
                'message' => 'This sign-in link is invalid, expired, or already used.',
            ], 401);
        }

        // Ensure the token belongs to the current store.
        if ((int) $loginData['store_id'] !== $storeId) {
            return response()->json([
                'status' => false,
                'message' => 'Invalid sign-in request.',
            ], 401);
        }

        // Find the customer using the server-side token data.
        $user = User::whereKey($loginData['user_id'])
            ->where('userRole', 2)
            ->where('store_id', $storeId)
            ->first();

        if (!$user) {
            return response()->json([
                'status' => false,
                'message' => 'Invalid sign-in request.',
            ], 401);
        }

        // Do not bypass two-factor authentication.
        if ($user->two_factor_enabled) {
            return response()->json([
                'status' => false,
                'message' => 'Additional verification is required. Please use the existing verification flow.',
            ], 403);
        }

        $store = Store::find($storeId);

        if (!$store) {
            return response()->json([
                'status' => false,
                'message' => 'Store not found.',
            ], 404);
        }

        // Preserve the existing password-reset policy.
        if ($user->customer && $user->customer->force_password_reset) {
            return response()->json([
                'status' => false,
                'force_password_reset' => true,
                'message' => 'Password reset is required before you can continue.',
            ], 403);
        }

        // Match the existing loginAsCustomer() session behavior.
        $user->tokens()
            ->whereIn('name', ['customerToken', 'customer_login'])
            ->delete();

        $tokenResult = $user->createToken('customer_login');
        $plainTextToken = $tokenResult->plainTextToken;

        $expireAt = now()->addHours(24);

        $accessToken = PersonalAccessToken::findToken(
            $plainTextToken
        );

        if ($accessToken) {
            $accessToken->expires_at = $expireAt;
            $accessToken->save();
        }

        return response()->json([
            'status' => true,
            'message' => 'Customer logged in successfully.',
            'token' => $plainTextToken,
            'expireAt' => $expireAt->toDateTimeString(),
            'store' => new StoreResource($store),
            'customer' => new CustomerResource($user->customer),
            'permissions' => null,
        ]);
    }
}
