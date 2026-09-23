<?php

namespace App\Http\Controllers\api\Admin;

use Exception;
use App\Models\User;
use App\Models\Order;
use App\Models\Customer;
use Illuminate\Http\Request;
use App\Exports\CustomerExport;
use App\Imports\CustomerImport;
use App\Models\CustomerAddress;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\DB;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Hash;
use Maatwebsite\Excel\Facades\Excel;
use App\Http\Resources\OrderResource;
use App\Http\Resources\MyOrderResource;
use App\Http\Resources\CustomerResource;
use Illuminate\Validation\ValidationException;

class CustomerController extends Controller
{

    public function index(Request $request)
    {
        try {
            $storeId = current_store_id();

            if (!$storeId) {
                return response()->json([
                    'status'  => false,
                    'message' => 'Store not selected or token invalid.',
                ], 400);
            }

            $query = Customer::with([
                'addresses',
                'billingInformations',
                'orders.items.product.images',
                'orders.customer',
                'orders.billingInformation',
                'orders.notes',
                'orders.payment',
            ])->where('store_id', $storeId);

            $search = $request->input('searchKeywords', $request->input('search'));

            if (!empty($search)) {
                $query->where(function ($q) use ($search) {
                    $q->where(DB::raw("CONCAT(first_name,' ',last_name)"), 'like', "%{$search}%")
                        ->orWhere('first_name', 'like', "%{$search}%")
                        ->orWhere('last_name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%")
                        ->orWhere('phone', 'like', "%{$search}%")
                        ->orWhere('customer_group', 'like', "%{$search}%")
                        ->orWhere('company_name', 'like', "%{$search}%");
                });
            }

            if ($request->filled('startsWith')) {
                $query->where('first_name', 'like', $request->input('startsWith') . '%');
            }

            if ($request->filled('phone')) {
                $query->where('phone', 'like', '%' . $request->input('phone') . '%');
            }

            if ($request->filled('country')) {
                $query->where('country', $request->input('country'));
            }

            if ($request->filled('stateProvince')) {
                $query->where('state', $request->input('stateProvince'));
            }

            if ($request->filled('customerIdFrom')) {
                $query->where('id', '>=', $request->input('customerIdFrom'));
            }

            if ($request->filled('customerIdTo')) {
                $query->where('id', '<=', $request->input('customerIdTo'));
            }

            if ($request->filled('creditFrom')) {
                $query->where('store_credit', '>=', $request->input('creditFrom'));
            }

            if ($request->filled('creditTo')) {
                $query->where('store_credit', '<=', $request->input('creditTo'));
            }

            if ($request->filled('customerGroup')) {
                $query->where('customer_group', $request->input('customerGroup'));
            }

            if ($request->filled('dateJoined')) {
                switch ($request->input('dateJoined')) {
                    case 'last_24_hours':
                        $query->where('created_at', '>=', now()->subHours(24));
                        break;
                    case 'last_7_days':
                        $query->whereDate('created_at', '>=', now()->subDays(7)->toDateString());
                        break;
                    case 'last_30_days':
                        $query->whereDate('created_at', '>=', now()->subDays(30)->toDateString());
                        break;
                    case 'today':
                        $query->whereDate('created_at', today());
                        break;
                    case 'yesterday':
                        $query->whereDate('created_at', today()->subDay());
                        break;
                    default:
                        return response()->json([
                            'status'  => false,
                            'message' => 'Invalid date filter.',
                        ], 422);
                }
            }

            if ($request->filled('orderFrom') || $request->filled('orderTo')) {
                $query->whereHas('orders', function ($q) use ($request) {
                    $q->where('is_draft', 0);

                    if ($request->filled('orderFrom')) {
                        $q->where('id', '>=', $request->input('orderFrom'));
                    }

                    if ($request->filled('orderTo')) {
                        $q->where('id', '<=', $request->input('orderTo'));
                    }
                });
            }

            if ($request->filled('sortBy')) {
                $query->orderBy($request->input('sortBy', 'id'), $request->input('sortOrder', 'asc'));
            } else {
                $query->orderBy('id', 'desc');
            }

            $customers = $query->paginate($request->input('pageSize', 50));

            $customers->getCollection()->transform(function ($customer) {
                $orders = $customer->orders
                    ->where('is_draft', 0)
                    ->sortByDesc('id')
                    ->values();

                $currentOrders = $orders->filter(function ($order) {
                    return !in_array($order->status, [
                        'Completed',
                        'Cancelled',
                        'Refunded',
                        'Declined',
                    ]);
                })->values();

                $pastOrders = $orders->filter(function ($order) {
                    return in_array($order->status, [
                        'Completed',
                        'Cancelled',
                        'Refunded',
                        'Declined',
                    ]);
                })->values();

                return [
                    'id'                  => $customer->id,
                    'firstName'           => $customer->first_name,
                    'lastName'            => $customer->last_name,
                    'email'               => $customer->email,
                    'phone'               => $customer->phone,
                    'companyName'         => $customer->company_name,
                    'address'             => $customer->address,
                    'addressLine2'        => $customer->address_line_2,
                    'city'                => $customer->city,
                    'state'               => $customer->state,
                    'zip'                 => $customer->zip,
                    'country'             => $customer->country,
                    'customerGroup'       => $customer->customer_group,
                    'storeCredit'         => $customer->store_credit,
                    'receiveReviewEmails' => $customer->receive_review_emails,
                    'joinDate'            => optional($customer->created_at)->format('M d, Y'),
                    'addresses'           => $customer->addresses,
                    'billingInformations' => $customer->billingInformations,
                    'totalOrders'         => $orders->count(),
                    'currentOrders'       => OrderResource::collection($currentOrders),
                    'pastOrders'          => OrderResource::collection($pastOrders),
                ];
            });

            return response()->json([
                'status'     => true,
                'message'    => 'Customer list fetched.',
                'data'       => $customers->items(),
                'pagination' => [
                    'total'    => $customers->total(),
                    'page'     => $customers->currentPage(),
                    'pageSize' => $customers->perPage(),
                    'lastPage' => $customers->lastPage(),
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status'  => false,
                'message' => 'Something went wrong.',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }
    public function show($id)
    {
        try {
            $storeId = current_store_id();
            if (!$storeId) {
                return response()->json([
                    'status' => false,
                    'message' => 'Store not selected or token invalid.'
                ], 400);
            }

            $customer = Customer::with(['addresses', 'billingInformations'])
                ->where('store_id', $storeId)
                ->findOrFail($id);
            return response()->json([
                'status' => true,
                'message' => 'Customer retrieved.',
                'data' => new CustomerResource($customer)
            ]);
        } catch (Exception $e) {
            return response()->json(['error' => 'Customer not found.'], 404);
        }
    }

    // public function store(Request $request)
    // {
    //     try {
    //         $storeId = current_store_id();
    //         if (!$storeId) {
    //             return response()->json([
    //                 'status' => false,
    //                 'message' => 'Store not selected or token invalid.'
    //             ], 400);
    //         }

    //         $validated = $request->validate([
    //             'firstName'            => 'nullable|string|max:255',
    //             'lastName'             => 'nullable|string|max:255',
    //             'email'                => [
    //                 'nullable',
    //                 'email',
    //                 Rule::unique('customers')->where(function ($query) use ($storeId) {
    //                     return $query->where('store_id', $storeId);
    //                 }),
    //             ],
    //             'phone'                 => 'nullable|string|max:20',
    //             'address'               => 'nullable|string',
    //             'companyName'           => 'nullable|string|max:255',
    //             'customerGroup'         => 'nullable|string|max:255',
    //             'storeCredit'           => 'nullable|numeric',
    //             'country'               => 'nullable|string|max:100',
    //             'state'                 => 'nullable|string|max:100',
    //             'taxExemptCode'         => 'nullable|string|max:50',
    //             'receiveReviewEmails'   => 'nullable|boolean',
    //             'forcePasswordReset'    => 'nullable|boolean',
    //             'password'              => 'nullable|string|min:7|confirmed',
    //         ]);

    //         $validated['store_id'] = $storeId;

    //         // ✅ Rename to match DB columns
    //         $validated['first_name'] = $validated['firstName'] ?? null;
    //         $validated['last_name'] = $validated['lastName'] ?? null;
    //         $validated['company_name'] = $validated['companyName'] ?? null;
    //         $validated['customer_group'] = $validated['customerGroup'] ?? null;
    //         $validated['store_credit'] = $validated['storeCredit'] ?? null;
    //         $validated['tax_exempt_code'] = $validated['taxExemptCode'] ?? null;

    //         // ✅ Remove camelCase keys
    //         unset(
    //             $validated['firstName'],
    //             $validated['lastName'],
    //             $validated['companyName'],
    //             $validated['customerGroup'],
    //             $validated['storeCredit'],
    //             $validated['taxExemptCode']
    //         );

    //         // ✅ Don't set name in $validated (no such column in customers)
    //         // $validated['name'] = ...  ← REMOVE this line

    //         // ✅ Handle password only for User
    //         if (!empty($validated['password'])) {
    //             $rawPassword = $validated['password']; // keep original for user table
    //             unset($validated['password']); // Don't save password to customer
    //         }

    //         $customer = Customer::create($validated);

    //         // 🔑 Create corresponding User (for authentication)
    //             if (!empty($validated['email']) && !empty($rawPassword ?? null)) {
    //             $fullName = trim(($customer->first_name ?? '') . ' ' . ($customer->last_name ?? ''));

    //             User::create([
    //                 'name'        => $fullName,
    //                 'email'       => $customer->email,
    //                 'password'    => Hash::make($rawPassword),
    //                 'customer_id' => $customer->id,
    //                 'store_id'    => $storeId
    //             ]);
    //         }

    //         return response()->json([
    //             'status' => true,
    //             'message' => 'Customer created.',
    //             'customer' => new CustomerResource($customer)
    //         ], 201);

    //     } catch (ValidationException $e) {
    //         return response()->json(['errors' => $e->errors()], 422);
    //     } catch (\Exception $e) {
    //         return response()->json(['error' => $e->getMessage()], 500);
    //     }
    // }



    public function store(Request $request)
    {
        try {
            $storeId = $request->header('storeId', $request->input('storeId')) ?: current_store_id();

            if (!$storeId) {
                return response()->json([
                    'status'  => false,
                    'message' => 'Store ID is required.',
                ], 400);
            }

            $validated = $request->validate([
                'firstName'            => 'nullable|string|max:255',
                'lastName'             => 'nullable|string|max:255',
                'email'                => [
                    'nullable',
                    'email',
                    Rule::unique('customers')->where(fn($q) => $q->where('store_id', $storeId)),
                ],
                'phone'                 => 'nullable|string|max:20',
                'companyName'           => 'nullable|string|max:255',
                'customerGroup'         => 'nullable|string|max:255',
                'storeCredit'           => 'nullable|numeric',
                'country'               => 'nullable|string|max:100',
                'state'                 => 'nullable|string|max:100',
                'city'                  => 'nullable|string|max:100',
                'address'               => 'nullable|string|max:255',
                'taxExemptCode'         => 'nullable|string|max:50',
                'receiveReviewEmails'   => 'nullable|boolean',
                'forcePasswordReset'    => 'nullable|boolean',
                'password'              => 'nullable|string|confirmed',
                'addresses'             => 'nullable|array',
                'addresses.*.addressLine1' => 'required|string|max:255',
                'addresses.*.addressLine2' => 'nullable|string|max:255',
                'addresses.*.city'         => 'nullable|string|max:100',
                'addresses.*.state'        => 'nullable|string|max:100',
                'addresses.*.zip'          => 'nullable|string|max:20',
                'addresses.*.country'      => 'nullable|string|max:100',
                'addresses.*.firstName'    => 'nullable|string|max:255',
                'addresses.*.lastName'     => 'nullable|string|max:255',
                'addresses.*.phone'        => 'nullable|string|max:20',
                'addresses.*.companyName'  => 'nullable|string|max:255',
                'addresses.*.type'         => 'nullable|string|max:50',
                'addresses.*.isDefault'    => 'nullable|boolean',
            ]);

            $validated['store_id'] = $storeId;

            $map = [
                'firstName'           => 'first_name',
                'lastName'            => 'last_name',
                'companyName'         => 'company_name',
                'customerGroup'       => 'customer_group',
                'storeCredit'         => 'store_credit',
                'taxExemptCode'       => 'tax_exempt_code',
                'receiveReviewEmails' => 'receive_review_emails',
                'forcePasswordReset'  => 'force_password_reset',
            ];

            foreach ($map as $k => $v) {
                if (isset($validated[$k])) {
                    $validated[$v] = $validated[$k];
                    unset($validated[$k]);
                }
            }

            $rawPassword = $validated['password'] ?? null;
            unset($validated['password']);

            $addresses = $validated['addresses'] ?? [];
            unset($validated['addresses']);

            $flatAddress = $validated['address'] ?? null;
            unset($validated['address']);

            $city        = $validated['city'] ?? '';
            $state       = $validated['state'] ?? '';
            $country     = $validated['country'] ?? '';
            $phone       = $validated['phone'] ?? '';
            $companyName = $validated['company_name'] ?? '';

            $customer = Customer::create($validated);

            $firstName = $customer->first_name ?? '';
            $lastName  = $customer->last_name ?? '';

            if ($flatAddress) {
                $customer->addresses()->create([
                    'store_id'       => $storeId,
                    'first_name'     => $firstName,
                    'last_name'      => $lastName,
                    'company_name'   => $companyName,
                    'phone_number'   => $phone,
                    'address_line_1' => $flatAddress,
                    'address_line_2' => '',
                    'city'           => $city,
                    'state'          => $state,
                    'zip'            => '',
                    'country'        => $country,
                    'is_default'     => true,
                    'type'           => 'shipping',
                ]);

                $customer->update(['address' => $flatAddress]);
            }

            foreach ($addresses as $index => $addr) {
                $customer->addresses()->create([
                    'store_id'       => $storeId,
                    'first_name'     => $addr['firstName'] ?? $firstName,
                    'last_name'      => $addr['lastName'] ?? $lastName,
                    'company_name'   => $addr['companyName'] ?? $companyName,
                    'phone_number'   => $addr['phone'] ?? $phone,
                    'address_line_1' => $addr['addressLine1'] ?? '',
                    'address_line_2' => $addr['addressLine2'] ?? '',
                    'city'           => $addr['city'] ?? '',
                    'state'          => $addr['state'] ?? '',
                    'zip'            => $addr['zip'] ?? '',
                    'country'        => $addr['country'] ?? '',
                    'is_default'     => $addr['isDefault'] ?? ($index === 0 && !$flatAddress),
                    'type'           => $addr['type'] ?? 'shipping',
                ]);
            }

            if (!empty($customer->email) && $rawPassword) {
                User::create([
                    'name'        => trim(($customer->first_name ?? '') . ' ' . ($customer->last_name ?? '')),
                    'email'       => $customer->email,
                    'password'    => Hash::make($rawPassword),
                    'customer_id' => $customer->id,
                    'store_id'    => $storeId,
                    'userRole'    => 2,
                ]);
            }

            $customer = Customer::with('addresses')->find($customer->id);

            return response()->json([
                'status'  => true,
                'message' => 'Customer created successfully',
                'data'    => new CustomerResource($customer)
            ], 201);
        } catch (ValidationException $e) {
            return response()->json(['errors' => $e->errors()], 422);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function update(Request $request, $id)
    {
        try {
            $storeId = current_store_id();
            if (!$storeId) {
                return response()->json([
                    'status' => false,
                    'message' => 'Store not selected or token invalid.'
                ], 400);
            }

            $customer = Customer::with('addresses')->where('store_id', $storeId)->findOrFail($id);

            $validated = $request->validate([
                'firstName'            => 'sometimes|string|max:255',
                'lastName'             => 'sometimes|string|max:255',
                'email'                => [
                    'sometimes',
                    'email',
                    Rule::unique('customers')->ignore($id)->where(fn($q) => $q->where('store_id', $storeId)),
                ],
                'phone'                 => 'nullable|string|max:20',
                'companyName'           => 'nullable|string|max:255',
                'customerGroup'         => 'nullable|string|max:255',
                'storeCredit'           => 'nullable|numeric',
                'country'               => 'nullable|string|max:100',
                'state'                 => 'nullable|string|max:100',
                'address'               => 'nullable|string|max:255',
                'taxExemptCode'         => 'nullable|string|max:50',
                'receiveReviewEmails'   => 'nullable|boolean',
                'forcePasswordReset'    => 'nullable|boolean',
                'password'              => 'nullable|string|confirmed',
                // New Fields
                'analytics'            => 'nullable|string|max:255',
                'functional'           => 'nullable|string|max:255',
                'targeting'            => 'nullable|string|max:255',
                'notes'                => 'nullable|string',

                'addresses'             => 'nullable|array',
                'addresses.*.id'        => 'nullable|exists:customer_addresses,id',
                'addresses.*.addressLine1' => 'required|string|max:255',
                'addresses.*.addressLine2' => 'nullable|string|max:255',
                'addresses.*.city'         => 'nullable|string|max:100',
                'addresses.*.state'        => 'nullable|string|max:100',
                'addresses.*.zip'          => 'nullable|string|max:20',
                'addresses.*.country'      => 'nullable|string|max:100',
            ]);

            $map = [
                'firstName' => 'first_name',
                'lastName' => 'last_name',
                'companyName' => 'company_name',
                'customerGroup' => 'customer_group',
                'storeCredit' => 'store_credit',
                'taxExemptCode' => 'tax_exempt_code',
                'receiveReviewEmails' => 'receive_review_emails',
                'forcePasswordReset'  => 'force_password_reset',
            ];

            foreach ($map as $k => $v) {
                if (isset($validated[$k])) {
                    $validated[$v] = $validated[$k];
                    unset($validated[$k]);
                }
            }

            $rawPassword = $validated['password'] ?? null;
            unset($validated['password']);

            $addresses = $validated['addresses'] ?? [];
            unset($validated['addresses']);

            $flatAddress = $validated['address'] ?? null;
            unset($validated['address']);

            $customer->update($validated);

            // Flat address handle karo
            if ($flatAddress) {
                $customer->update(['address' => $flatAddress]);

                $existingAddress = $customer->addresses()->first();
                if ($existingAddress) {
                    $existingAddress->update([
                        'address_line_1' => $flatAddress,
                        'state'          => $validated['state'] ?? $existingAddress->state,
                        'country'        => $validated['country'] ?? $existingAddress->country,
                    ]);
                } else {
                    $customer->addresses()->create([
                        'address_line_1' => $flatAddress,
                        'address_line_2' => null,
                        'city'           => null,
                        'state'          => $validated['state'] ?? null,
                        'zip'            => null,
                        'country'        => $validated['country'] ?? null,
                    ]);
                }
            }

            // Multiple addresses array se update ya create karo
            foreach ($addresses as $addr) {
                if (!empty($addr['id'])) {
                    $addressModel = $customer->addresses()->find($addr['id']);
                    if ($addressModel) {
                        $addressModel->update([
                            'address_line_1' => $addr['addressLine1'] ?? null,
                            'address_line_2' => $addr['addressLine2'] ?? null,
                            'city'           => $addr['city'] ?? null,
                            'state'          => $addr['state'] ?? null,
                            'zip'            => $addr['zip'] ?? null,
                            'country'        => $addr['country'] ?? null,
                        ]);
                    }
                } else {
                    $customer->addresses()->create([
                        'address_line_1' => $addr['addressLine1'] ?? null,
                        'address_line_2' => $addr['addressLine2'] ?? null,
                        'city'           => $addr['city'] ?? null,
                        'state'          => $addr['state'] ?? null,
                        'zip'            => $addr['zip'] ?? null,
                        'country'        => $addr['country'] ?? null,
                    ]);
                }
            }

            // Update user if exists
            $user = $customer->user;
            if ($user) {
                $userUpdate = ['name' => trim($customer->first_name . ' ' . $customer->last_name)];
                if (isset($validated['email'])) {
                    $userUpdate['email'] = $validated['email'];
                }
                if ($rawPassword) {
                    $userUpdate['password'] = Hash::make($rawPassword);
                }
                $user->update($userUpdate);
            }

            $customer = Customer::with('addresses')->find($customer->id);

            return response()->json([
                'status' => true,
                'message' => 'Customer updated successfully',
                'data' => new CustomerResource($customer)
            ]);
        } catch (ValidationException $e) {
            return response()->json(['errors' => $e->errors()], 422);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function updateAccount(Request $request)
    {
        try {
            $user = auth()->user(); // logged-in user
            if (!$user) {
                return response()->json([
                    'status' => false,
                    'message' => 'User not authenticated.'
                ], 401);
            }

            $customer = $user->customer; // associated customer
            if (!$customer) {
                return response()->json([
                    'status' => false,
                    'message' => 'Customer record not found.'
                ], 404);
            }

            // Validation
            $validated = $request->validate([
                'firstName'        => 'nullable|string|max:255',
                'lastName'         => 'nullable|string|max:255',
                'email'            => [
                    'nullable',
                    'email',
                    Rule::unique('users')->ignore($user->id),
                ],
                'phone'            => 'nullable|string|max:20',
                'companyName'      => 'nullable|string|max:255',
                'currentPassword'  => 'nullable|required_with:password|string|min:7',
                'password'         => 'nullable|string|confirmed',
            ]);

            // Password check
            if (!empty($validated['password'])) {
                if (!Hash::check($validated['currentPassword'], $user->password)) {
                    return response()->json([
                        'status' => false,
                        'message' => 'Current password is incorrect.'
                    ], 403);
                }
                $user->password = Hash::make($validated['password']);
            }

            // Update user email
            if (!empty($validated['email'])) {
                $user->email = $validated['email'];
            }

            // Update customer fields
            $customer->first_name   = $validated['firstName'] ?? $customer->first_name;
            $customer->last_name    = $validated['lastName'] ?? $customer->last_name;
            $customer->company_name = $validated['companyName'] ?? $customer->company_name;
            $customer->phone        = $validated['phone'] ?? $customer->phone;
            $customer->save();

            // Sync name to User table
            $user->name = trim($customer->first_name . ' ' . $customer->last_name);
            $user->save();

            return response()->json([
                'status' => true,
                'message' => 'Account updated successfully.',
                'customer' => new CustomerResource($customer),
            ], 200);
        } catch (ValidationException $e) {
            return response()->json(['errors' => $e->errors()], 422);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    // public function myOrders(Request $request)
    // {
    //     try {
    //         $user = auth()->user(); // Logged-in user
    //         if (!$user) {
    //             return response()->json([
    //                 'status' => false,
    //                 'message' => 'User not authenticated.'
    //             ], 401);
    //         }

    //         $customer = $user->customer; // Associated customer
    //         if (!$customer) {
    //             return response()->json([
    //                 'status' => false,
    //                 'message' => 'Customer record not found.'
    //             ], 404);
    //         }

    //         // Fetch orders of logged-in customer
    //         $orders = Order::where('customer_id', $customer->id)
    //                         ->orderBy('created_at', 'desc')
    //                         ->get();

    //         return response()->json([
    //             'status' => true,
    //             'orders' => OrderResource::collection($orders),
    //         ], 200);

    //     } catch (\Exception $e) {
    //         return response()->json([
    //             'status' => false,
    //             'error' => $e->getMessage()
    //         ], 500);
    //     }
    // }


    public function myOrders(Request $request)
    {
        try {
            $user = auth()->user();
            if (!$user) {
                return response()->json([
                    'status'  => false,
                    'message' => 'User not authenticated.'
                ], 401);
            }

            $orders = Order::with([
                'items.product.images',
                'returnRequest',
            ])
                ->where('user_id', $user->id)
                ->orderBy('created_at', 'desc')
                ->get();

            // product-less items hatao taake resource null->id na read kare
            $orders->each(function ($order) {
                $order->setRelation(
                    'items',
                    $order->items->filter(fn($item) => $item->product !== null)->values()
                );
            });

            $result = [];
            foreach ($orders as $order) {
                try {
                    $result[] = (new MyOrderResource($order))->resolve();
                } catch (\Throwable $e) {
                    // yeh order skip — baqi orders chalte rahein
                    continue;
                }
            }

            return response()->json([
                'status' => true,
                'orders' => $result,
            ], 200);
        } catch (\Throwable $e) {
            return response()->json([
                'status'  => false,
                'message' => 'Unable to load orders right now. Please try again.'
            ], 500);
        }
    }

    public function destroy(Request $request, $id = null)
    {
        try {
            $storeId = current_store_id();
            if (!$storeId) {
                return response()->json([
                    'status' => false,
                    'message' => 'Store not selected or token invalid.'
                ], 400);
            }

            // 🔹 Single delete
            if ($id) {
                $customer = Customer::where('store_id', $storeId)->findOrFail($id);

                // 🧨 Delete linked user if exists
                if ($customer->user) {
                    $customer->user->delete();
                }

                $customer->delete();

                return response()->json([
                    'status' => true,
                    'message' => 'Customer deleted.',
                    'deletedIds' => [$id]
                ]);
            }

            // 🔹 Bulk delete
            $request->validate([
                'ids' => 'required|array|min:1',
                'ids.*' => 'exists:customers,id'
            ]);

            $deleted = [];
            $failed = [];

            foreach ($request->ids as $cid) {
                try {
                    $customer = Customer::where('store_id', $storeId)->findOrFail($cid);

                    // 🧨 Delete linked user if exists
                    if ($customer->user) {
                        $customer->user->delete();
                    }

                    $customer->delete();
                    $deleted[] = $cid;
                } catch (Exception $e) {
                    $failed[] = ['id' => $cid, 'error' => $e->getMessage()];
                }
            }

            $message = count($deleted) === 1
                ? 'Customer deleted.'
                : 'Customers deleted.';

            return response()->json([
                'status' => true,
                'message' => $message,
                'deletedIds' => $deleted,
                'failed' => $failed
            ]);
        } catch (Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function importCSV(Request $request)
    {
        try {
            $request->validate([
                'file' => 'required|file|mimes:xlsx,xls,csv,txt'
            ]);

            $storeId = current_store_id();
            if (!$storeId) {
                return response()->json(['error' => 'Store not selected.'], 400);
            }

            Excel::import(new CustomerImport($storeId), $request->file('file'));

            return response()->json([
                'status' => true,
                'message' => 'Customers imported successfully.'
            ]);
        } catch (\Maatwebsite\Excel\Validators\ValidationException $e) {
            return response()->json([
                'error' => 'Validation failed.',
                'failures' => $e->failures()
            ], 422);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function exportCSV()
    {
        $storeId = current_store_id();
        if (!$storeId) {
            return response()->json([
                'status' => false,
                'message' => 'Store not selected or token invalid.'
            ], 400);
        }

        $fileName = 'customers_export_' . now()->format('Ymd_His') . '.xlsx';
        return Excel::download(new CustomerExport($storeId), $fileName);
    }

    public function search(Request $request)
    {
        $storeId = current_store_id();

        if (!$storeId) {
            return response()->json(['error' => 'Store not selected or token invalid.'], 400);
        }

        $q = $request->input('query');

        if (!$q || strlen($q) < 2) {
            return response()->json(['error' => 'Search query too short.'], 422);
        }

        $results = Customer::where('store_id', $storeId)
            ->where(function ($qBuilder) use ($q) {
                $qBuilder->where('name', 'like', "%{$q}%")
                    ->orWhere('email', 'like', "%{$q}%")
                    ->orWhere('phone', 'like', "%{$q}%");
            })
            ->limit(10)
            ->get();

        return response()->json([
            'status' => true,
            'data' => CustomerResource::collection($results)
        ]);
    }

    public function advancedSearch(Request $request)
    {
        try {
            $storeId = current_store_id();
            if (!$storeId) {
                return response()->json([
                    'status'  => false,
                    'message' => 'Store not selected or token invalid.'
                ], 400);
            }

            $query = Customer::where('store_id', $storeId)
                ->withCount(['orders as total_orders' => function ($q) {
                    $q->where('is_draft', 0);
                }]);

            if ($request->filled('searchKeywords')) {
                $search = $request->input('searchKeywords');
                $query->where(function ($q) use ($search) {
                    $q->orWhere(DB::raw("CONCAT(first_name, ' ', last_name)"), 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%")
                        ->orWhere('phone', 'like', "%{$search}%")
                        ->orWhere('first_name', 'like', "%{$search}%")
                        ->orWhere('last_name', 'like', "%{$search}%")
                        ->orWhere('customer_group', 'like', "%{$search}%")
                        ->orWhere('company_name', 'like', "%{$search}%");
                });
            }

            if ($request->filled('startsWith')) {
                $query->where('first_name', 'like', $request->input('startsWith') . '%');
            }

            if ($request->filled('phone')) {
                $query->where('phone', 'like', "%{$request->input('phone')}%");
            }

            if ($request->filled('country')) {
                $query->where('country', $request->input('country'));
            }

            if ($request->filled('stateProvince')) {
                $query->where('state', $request->input('stateProvince'));
            }

            if ($request->filled('customerIdFrom')) {
                $query->where('id', '>=', $request->input('customerIdFrom'));
            }

            if ($request->filled('customerIdTo')) {
                $query->where('id', '<=', $request->input('customerIdTo'));
            }

            if ($request->filled('creditFrom')) {
                $query->where('store_credit', '>=', $request->input('creditFrom'));
            }

            if ($request->filled('creditTo')) {
                $query->where('store_credit', '<=', $request->input('creditTo'));
            }

            if ($request->filled('customerGroup')) {
                $query->where('customer_group', $request->input('customerGroup'));
            }

            if ($request->filled('dateJoined')) {
                switch ($request->input('dateJoined')) {
                    case 'last_24_hours':
                        $query->where('created_at', '>=', now()->subHours(24));
                        break;
                    case 'last_7_days':
                        $query->whereDate('created_at', '>=', now()->subDays(7)->toDateString());
                        break;
                    case 'last_30_days':
                        $query->whereDate('created_at', '>=', now()->subDays(30)->toDateString());
                        break;
                    case 'today':
                        $query->whereDate('created_at', today());
                        break;
                    case 'yesterday':
                        $query->whereDate('created_at', today()->subDay());
                        break;
                    default:
                        return response()->json([
                            'status'  => false,
                            'message' => 'Invalid date filter.'
                        ], 422);
                }
            }

            $sortField = $request->input('sortBy', 'id');
            $sortOrder = $request->input('sortOrder', 'asc');
            $query->orderBy($sortField, $sortOrder);

            $pageSize  = $request->input('pageSize', 50);
            $customers = $query->paginate($pageSize);

            $items = $customers->getCollection();
            $data  = collect(CustomerResource::collection($items)->resolve())
                ->map(function ($row) use ($items) {
                    $customer = $items->firstWhere('id', $row['id']);

                    $row['totalOrders'] = (int) ($customer->total_orders ?? 0);
                    $row['joinDate']    = optional($customer->created_at)->format('M d, Y');

                    return $row;
                })
                ->values();

            return response()->json([
                'status'  => true,
                'message' => 'Advanced search result.',
                'data'    => $data,
                'pagination' => [
                    'total'    => $customers->total(),
                    'page'     => $customers->currentPage(),
                    'pageSize' => $customers->perPage(),
                    'lastPage' => $customers->lastPage()
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status'  => false,
                'message' => 'Something went wrong.',
                'error'   => $e->getMessage()
            ], 500);
        }
    }

    public function deleteMyAddress(Request $request, $addressId)
    {
        try {
            $user = auth()->user();
            if (!$user || !$user->customer) {
                return response()->json([
                    'status' => false,
                    'message' => 'User not authenticated or customer not found.'
                ], 401);
            }

            $customer = $user->customer;

            // Find the address that belongs to this customer
            $address = $customer->addresses()->findOrFail($addressId);

            $address->delete();

            return response()->json([
                'status' => true,
                'message' => 'Address deleted successfully.',
                'deletedId' => $addressId
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }
    public function getMyAddresses(Request $request)
    {

        try {
            $user = auth()->user();
            if (!$user || !$user->customer) {
                return response()->json([
                    'status' => false,
                    'message' => 'User not authenticated or customer not found.'
                ], 401);
            }

            $customer = $user->customer;

            $addresses = $customer->addresses()->get()->map(function ($address) use ($customer) {
                return [
                    'addressId'      => $address->id,
                    'addressLine1'   => $address->address_line_1,
                    'addressLine2'   => $address->address_line_2,
                    'city'           => $address->city,
                    'country'          => $address->country,
                    'state'          => $address->state,
                    'zip'            => $address->zip,
                    'customerName'   => $customer->first_name . ' ' . $customer->last_name,
                ];
            });

            return response()->json([
                'status' => true,
                'addresses' => $addresses
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
