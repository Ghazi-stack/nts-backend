<?php

namespace App\Http\Controllers\api\Admin;

use Exception;
use App\Models\User;
use App\Models\Customer;
use Illuminate\Http\Request;
use App\Exports\CustomerExport;
use App\Imports\CustomerImport;
use Illuminate\Validation\Rule;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Hash;
use Maatwebsite\Excel\Facades\Excel;
use App\Http\Resources\CustomerResource;
use Illuminate\Validation\ValidationException;

class CustomerController extends Controller
{
    // public function index(Request $request)
    // {
    //     try {
    //         $storeId = current_store_id();
    //         if (!$storeId) {
    //             return response()->json([
    //                 'status' => false,
    //                 'message' => 'Store not selected or token invalid.'
    //             ], 400);
    //         }

    //         $query = Customer::where('store_id', $storeId);

    //         // 🔍 Keyword search
    //         if ($request->filled('search')) {
    //             $search = $request->search;
    //             $query->where(function ($q) use ($search) {
    //                 $q->where('name', 'like', "%$search%")
    //                   ->orWhere('email', 'like', "%$search%")
    //                   ->orWhere('phone', 'like', "%$search%")
    //                   ->orWhere('first_name', 'like', "%$search%")
    //                   ->orWhere('last_name', 'like', "%$search%");
    //             });
    //         }

    //         if ($request->filled('starts_with')) {
    //             $query->where('first_name', 'like', $request->starts_with . '%');
    //         }

    //         if ($request->filled('phone')) {
    //             $query->where('phone', 'like', "%{$request->phone}%");
    //         }

    //         if ($request->filled('country')) {
    //             $query->where('country', $request->country);
    //         }

    //         if ($request->filled('state')) {
    //             $query->where('state', $request->state);
    //         }

    //         if ($request->filled('id_from')) {
    //             $query->where('id', '>=', $request->id_from);
    //         }

    //         if ($request->filled('id_to')) {
    //             $query->where('id', '<=', $request->id_to);
    //         }

    //         if ($request->filled('credit_from')) {
    //             $query->where('store_credit', '>=', $request->credit_from);
    //         }

    //         if ($request->filled('credit_to')) {
    //             $query->where('store_credit', '<=', $request->credit_to);
    //         }

    //         if ($request->filled('customer_group')) {
    //             $query->where('customer_group', $request->customer_group);
    //         }

    //         if ($request->filled('created_from')) {
    //             $query->whereDate('created_at', '>=', $request->created_from);
    //         }

    //         if ($request->filled('created_to')) {
    //             $query->whereDate('created_at', '<=', $request->created_to);
    //         }

    //         $sortField = $request->input('sortBy', 'id');
    //         $sortOrder = $request->input('sortOrder', 'asc');
    //         $query->orderBy($sortField, $sortOrder);

    //         $pageSize = $request->input('pageSize', 50);
    //         $customers = $query->paginate($pageSize);

    //         return response()->json([
    //             'status' => true,
    //             'message' => 'Customer list fetched.',
    //             'data' => CustomerResource::collection($customers),
    //             'pagination' => [
    //                 'total' => $customers->total(),
    //                 'page' => $customers->currentPage(),
    //                 'pageSize' => $customers->perPage(),
    //                 'lastPage' => $customers->lastPage()
    //             ]
    //         ]);
    //     } catch (Exception $e) {
    //         return response()->json(['error' => $e->getMessage()], 500);
    //     }
    // }

    public function index(Request $request)
    {
        try {
            $storeId = current_store_id();
            if (!$storeId) {
                return response()->json([
                    'status' => false,
                    'message' => 'Store not selected or token invalid.'
                ], 400);
            }

            $query = Customer::where('store_id', $storeId);

            // 🔍 Unified keyword search (Filter by keyword)
            if ($request->filled('search')) {
                $search = $request->search;
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                      ->orWhere('email', 'like', "%{$search}%")
                      ->orWhere('phone', 'like', "%{$search}%")
                      ->orWhere('first_name', 'like', "%{$search}%")
                      ->orWhere('last_name', 'like', "%{$search}%")
                      ->orWhere('customer_group', 'like', "%{$search}%"); // optional
                });
            }

            // 🔠 Starts with first name
            if ($request->filled('starts_with')) {
                $query->where('first_name', 'like', $request->starts_with . '%');
            }

            // ☎️ Phone filtering
            if ($request->filled('phone')) {
                $query->where('phone', 'like', "%{$request->phone}%");
            }

            // 🌍 Country/state filter (if available in your table)
            if ($request->filled('country')) {
                $query->where('country', $request->country);
            }

            if ($request->filled('state')) {
                $query->where('state', $request->state);
            }

            // 🔢 ID range filtering
            if ($request->filled('id_from')) {
                $query->where('id', '>=', $request->id_from);
            }

            if ($request->filled('id_to')) {
                $query->where('id', '<=', $request->id_to);
            }

            // 💳 Store credit range filtering
            if ($request->filled('credit_from')) {
                $query->where('store_credit', '>=', $request->credit_from);
            }

            if ($request->filled('credit_to')) {
                $query->where('store_credit', '<=', $request->credit_to);
            }

            // 👥 Customer group filtering
            if ($request->filled('customer_group')) {
                $query->where('customer_group', $request->customer_group);
            }

            // 📅 Created date range filtering
            if ($request->filled('created_from')) {
                $query->whereDate('created_at', '>=', $request->created_from);
            }

            if ($request->filled('created_to')) {
                $query->whereDate('created_at', '<=', $request->created_to);
            }

            // 🔃 Sorting
            $sortField = $request->input('sortBy', 'id');
            $sortOrder = $request->input('sortOrder', 'asc');
            $query->orderBy($sortField, $sortOrder);

            // 📄 Pagination
            $pageSize = $request->input('pageSize', 50);
            $customers = $query->paginate($pageSize);

            return response()->json([
                'status' => true,
                'message' => 'Customer list fetched.',
                'data' => CustomerResource::collection($customers),
                'pagination' => [
                    'total' => $customers->total(),
                    'page' => $customers->currentPage(),
                    'pageSize' => $customers->perPage(),
                    'lastPage' => $customers->lastPage()
                ]
            ]);
        } catch (Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
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

            $customer = Customer::where('store_id', $storeId)->findOrFail($id);
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
    //             'first_name'            => 'nullable|string|max:255',
    //             'last_name'             => 'nullable|string|max:255',
    //             'email' => [
    //             'nullable',
    //             'email',
    //             Rule::unique('customers')->where(function ($query) use ($storeId) {
    //                 return $query->where('store_id', $storeId);
    //             }),
    //         ],
    //             'phone'                 => 'nullable|string|max:20',
    //             'address'               => 'nullable|string',
    //             'company_name'          => 'nullable|string|max:255',
    //             'customer_group'        => 'nullable|string|max:255',
    //             'store_credit'          => 'nullable|numeric',
    //             'country'               => 'nullable|string|max:100',
    //             'state'                 => 'nullable|string|max:100',
    //             'tax_exempt_code'       => 'nullable|string|max:50',
    //             'receive_review_emails' => 'nullable|boolean',
    //             'force_password_reset'  => 'nullable|boolean',
    //             'password'              => 'nullable|string|min:7',
    //             'password'              => 'nullable|string|min:7|confirmed',
    //         ]);

    //         $validated['store_id'] = $storeId;
    //         $validated['name'] = trim(($validated['first_name'] ?? '') . ' ' . ($validated['last_name'] ?? ''));

    //         if (!empty($validated['password'])) {
    //             $validated['password'] = Hash::make($validated['password']);
    //         }

    //         $customer = Customer::create($validated);

    //         return response()->json([
    //             'status' => true,
    //             'message' => 'Customer created.',
    //             'data' => new CustomerResource($customer)
    //         ], 201);
    //     } catch (ValidationException $e) {
    //         return response()->json(['errors' => $e->errors()], 422);
    //     } catch (Exception $e) {
    //         return response()->json(['error' => $e->getMessage()], 500);
    //     }
    // }

    public function store(Request $request)
    {
        try {
            $storeId = current_store_id();
            if (!$storeId) {
                return response()->json([
                    'status' => false,
                    'message' => 'Store not selected or token invalid.'
                ], 400);
            }
        
            $validated = $request->validate([
                'first_name'            => 'nullable|string|max:255',
                'last_name'             => 'nullable|string|max:255',
                'email'                 => [
                    'nullable',
                    'email',
                    Rule::unique('customers')->where(function ($query) use ($storeId) {
                        return $query->where('store_id', $storeId);
                    }),
                ],
                'phone'                 => 'nullable|string|max:20',
                'address'               => 'nullable|string',
                'company_name'          => 'nullable|string|max:255',
                'customer_group'        => 'nullable|string|max:255',
                'store_credit'          => 'nullable|numeric',
                'country'               => 'nullable|string|max:100',
                'state'                 => 'nullable|string|max:100',
                'tax_exempt_code'       => 'nullable|string|max:50',
                'receive_review_emails' => 'nullable|boolean',
                'force_password_reset'  => 'nullable|boolean',
                'password'              => 'nullable|string|min:7|confirmed',
            ]);
        
            $validated['store_id'] = $storeId;
            $validated['name'] = trim(($validated['first_name'] ?? '') . ' ' . ($validated['last_name'] ?? ''));
        
            if (!empty($validated['password'])) {
                $rawPassword = $validated['password']; // keep original for user table
                $validated['password'] = Hash::make($validated['password']);
            }
        
            $customer = Customer::create($validated);
        
            // 🔑 Create corresponding User (for authentication)
            if (!empty($validated['email']) && !empty($rawPassword ?? null)) {
                User::create([
                    'name'        => $validated['name'],
                    'email'       => $validated['email'],
                    'password'    => Hash::make($rawPassword),
                    'phone' => $validated['phone'] ?? null, // Send null if not available
                    'customer_id' => $customer->id,
                    'store_id'    => $storeId
                ]);
            }
        
            return response()->json([
                'status' => true,
                'message' => 'Customer created.',
                'data' => new CustomerResource($customer)
            ], 201);
        
        } catch (ValidationException $e) {
            return response()->json(['errors' => $e->errors()], 422);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    // public function update(Request $request, $id)
    // {
    //     try {
    //         $storeId = current_store_id();
    //         if (!$storeId) {
    //             return response()->json([
    //                 'status' => false,
    //                 'message' => 'Store not selected or token invalid.'
    //             ], 400);
    //         }

    //         $customer = Customer::where('store_id', $storeId)->findOrFail($id);

    //         $validated = $request->validate([
    //             'first_name'            => 'sometimes|string|max:255',
    //             'last_name'             => 'sometimes|string|max:255',
    //             'email'                 => 'sometimes|email|unique:customers,email,' . $id,
    //             'phone'                 => 'nullable|string|max:20',
    //             'address'               => 'nullable|string',
    //             'company_name'          => 'nullable|string|max:255',
    //             'customer_group'        => 'nullable|string|max:255',
    //             'store_credit'          => 'nullable|numeric',
    //             'country'               => 'nullable|string|max:100',
    //             'state'                 => 'nullable|string|max:100',
    //             'tax_exempt_code'       => 'nullable|string|max:50',
    //             'receive_review_emails' => 'nullable|boolean',
    //             'force_password_reset'  => 'nullable|boolean',
    //             'password'              => 'nullable|string|min:7'
    //         ]);

    //         if ($request->filled('first_name') || $request->filled('last_name')) {
    //             $validated['name'] = trim(($request->first_name ?? $customer->first_name) . ' ' . ($request->last_name ?? $customer->last_name));
    //         }

    //         if (!empty($validated['password'])) {
    //             $validated['password'] = Hash::make($validated['password']);
    //         }

    //         $customer->update($validated);

    //         return response()->json([
    //             'status' => true,
    //             'message' => 'Customer updated.',
    //             'data' => new CustomerResource($customer)
    //         ]);
    //     } catch (ValidationException $e) {
    //         return response()->json(['errors' => $e->errors()], 422);
    //     } catch (Exception $e) {
    //         return response()->json(['error' => $e->getMessage()], 500);
    //     }
    // }

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

            $customer = Customer::where('store_id', $storeId)->findOrFail($id);

            $validated = $request->validate([
                'first_name'            => 'sometimes|string|max:255',
                'last_name'             => 'sometimes|string|max:255',
                'email'                 => [
                    'sometimes',
                    'email',
                    Rule::unique('customers')->ignore($id)->where(function ($query) use ($storeId) {
                        return $query->where('store_id', $storeId);
                    }),
                ],
                'phone'                 => 'nullable|string|max:20',
                'address'               => 'nullable|string',
                'company_name'          => 'nullable|string|max:255',
                'customer_group'        => 'nullable|string|max:255',
                'store_credit'          => 'nullable|numeric',
                'country'               => 'nullable|string|max:100',
                'state'                 => 'nullable|string|max:100',
                'tax_exempt_code'       => 'nullable|string|max:50',
                'receive_review_emails' => 'nullable|boolean',
                'force_password_reset'  => 'nullable|boolean',
                'password'              => 'nullable|string|min:7',
            ]);

            // Update full name if either part is present
            if ($request->filled('first_name') || $request->filled('last_name')) {
                $validated['name'] = trim(($request->first_name ?? $customer->first_name) . ' ' . ($request->last_name ?? $customer->last_name));
            }

            $rawPassword = null;

            if (!empty($validated['password'])) {
                $rawPassword = $validated['password'];
                $validated['password'] = Hash::make($validated['password']);
            }

            $customer->update($validated);

            // 🔄 Also update linked user if exists
            $user = $customer->user;

            if ($user) {
                $userUpdate = [];

                if (isset($validated['name'])) {
                    $userUpdate['name'] = $validated['name'];
                }

                if (isset($validated['email'])) {
                    $userUpdate['email'] = $validated['email'];
                }

                if ($rawPassword) {
                    $userUpdate['password'] = Hash::make($rawPassword);
                }

                if (!empty($userUpdate)) {
                    $user->update($userUpdate);
                }
            }

            return response()->json([
                'status' => true,
                'message' => 'Customer updated.',
                'data' => new CustomerResource($customer)
            ]);

        } catch (ValidationException $e) {
            return response()->json(['errors' => $e->errors()], 422);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }


    // public function destroy(Request $request, $id = null)
    // {
    //     try {
    //         $storeId = current_store_id();
    //         if (!$storeId) {
    //             return response()->json([
    //                 'status' => false,
    //                 'message' => 'Store not selected or token invalid.'
    //             ], 400);
    //         }

    //         if ($id) {
    //             $customer = Customer::where('store_id', $storeId)->findOrFail($id);
    //             $customer->delete();
    //             return response()->json([
    //                 'status' => true,
    //                 'message' => 'Customer deleted.',
    //                 'deletedIds' => [$id]
    //             ]);
    //         }

    //         $request->validate([
    //             'ids' => 'required|array|min:1',
    //             'ids.*' => 'exists:customers,id'
    //         ]);

    //         $deleted = [];
    //         $failed = [];

    //         foreach ($request->ids as $cid) {
    //             try {
    //                 $customer = Customer::where('store_id', $storeId)->findOrFail($cid);
    //                 $customer->delete();
    //                 $deleted[] = $cid;
    //             } catch (Exception $e) {
    //                 $failed[] = ['id' => $cid, 'error' => $e->getMessage()];
    //             }
    //         }
    //           $message = count($deleted) === 1 
    //         ? 'Your customer deleted.' 
    //         : 'Bulk delete completed.';

    //         return response()->json([
    //             'status' => true,
    //             'message' => $message,
    //             'deletedIds' => $deleted,
    //             'failed' => $failed
    //         ]);
    //     } catch (Exception $e) {
    //         return response()->json(['error' => $e->getMessage()], 500);
    //     }
    // }

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
        } catch (Exception $e) {
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

}
