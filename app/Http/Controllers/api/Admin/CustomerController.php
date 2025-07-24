<?php

namespace App\Http\Controllers\api\Admin;

use Exception;
use App\Models\Customer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use App\Http\Controllers\Controller;
use App\Http\Resources\CustomerResource;
use App\Imports\CustomerImport;
use App\Exports\CustomerExport;
use Maatwebsite\Excel\Facades\Excel;

class CustomerController extends Controller
{
    public function index(Request $request)
    {
        try {
            $query = Customer::where('store_id', current_store_id());

            // 🔍 Keyword search
            if ($request->filled('search')) {
                $search = $request->search;
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'like', "%$search%")
                      ->orWhere('email', 'like', "%$search%")
                      ->orWhere('phone', 'like', "%$search%")
                      ->orWhere('first_name', 'like', "%$search%")
                      ->orWhere('last_name', 'like', "%$search%");
                });
            }

            // Starts with first name
            if ($request->filled('starts_with')) {
                $query->where('first_name', 'like', $request->starts_with . '%');
            }

            // 📞 Phone filter
            if ($request->filled('phone')) {
                $query->where('phone', 'like', "%{$request->phone}%");
            }

            // 🌍 Country / State
            if ($request->filled('country')) {
                $query->where('country', $request->country);
            }
            if ($request->filled('state')) {
                $query->where('state', $request->state);
            }

            // 🔢 ID range
            if ($request->filled('id_from')) {
                $query->where('id', '>=', $request->id_from);
            }
            if ($request->filled('id_to')) {
                $query->where('id', '<=', $request->id_to);
            }

            // 💰 Store credit range
            if ($request->filled('credit_from')) {
                $query->where('store_credit', '>=', $request->credit_from);
            }
            if ($request->filled('credit_to')) {
                $query->where('store_credit', '<=', $request->credit_to);
            }

            // 🏷️ Customer group
            if ($request->filled('customer_group')) {
                $query->where('customer_group', $request->customer_group);
            }

            // 📅 Date range
            if ($request->filled('created_from')) {
                $query->whereDate('created_at', '>=', $request->created_from);
            }
            if ($request->filled('created_to')) {
                $query->whereDate('created_at', '<=', $request->created_to);
            }

            // Sort
            $sortField = $request->input('sortBy', 'id');
            $sortOrder = $request->input('sortOrder', 'asc');
            $query->orderBy($sortField, $sortOrder);

            // Pagination
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
            $customer = Customer::where('store_id', current_store_id())->findOrFail($id);
            return response()->json([
                'status' => true,
                'message' => 'Customer retrieved.',
                'data' => new CustomerResource($customer)
            ]);
        } catch (Exception $e) {
            return response()->json(['error' => 'Customer not found.'], 404);
        }
    }

    public function store(Request $request)
    {
        try {
            $validated = $request->validate([
                'first_name'            => 'nullable|string|max:255',
                'last_name'             => 'nullable|string|max:255',
                'email'                 => 'nullable|email|unique:customers,email',
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
                'password'              => 'nullable|string|min:7'
            ]);

            $validated['store_id'] = current_store_id();
            $validated['name'] = trim(($validated['first_name'] ?? '') . ' ' . ($validated['last_name'] ?? ''));

            if (!empty($validated['password'])) {
                $validated['password'] = Hash::make($validated['password']);
            }

            $customer = Customer::create($validated);

            return response()->json([
                'status' => true,
                'message' => 'Customer created.',
                'data' => new CustomerResource($customer)
            ], 201);
        } catch (ValidationException $e) {
            return response()->json(['errors' => $e->errors()], 422);
        } catch (Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function update(Request $request, $id)
    {
        try {
            $customer = Customer::where('store_id', current_store_id())->findOrFail($id);

            $validated = $request->validate([
                'first_name'            => 'sometimes|string|max:255',
                'last_name'             => 'sometimes|string|max:255',
                'email'                 => 'sometimes|email|unique:customers,email,' . $id,
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
                'password'              => 'nullable|string|min:7'
            ]);

            if ($request->filled('first_name') || $request->filled('last_name')) {
                $validated['name'] = trim(($request->first_name ?? $customer->first_name) . ' ' . ($request->last_name ?? $customer->last_name));
            }

            if (!empty($validated['password'])) {
                $validated['password'] = Hash::make($validated['password']);
            }

            $customer->update($validated);

            return response()->json([
                'status' => true,
                'message' => 'Customer updated.',
                'data' => new CustomerResource($customer)
            ]);
        } catch (ValidationException $e) {
            return response()->json(['errors' => $e->errors()], 422);
        } catch (Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function destroy(Request $request, $id = null)
    {
        try {
            if ($id) {
                $customer = Customer::where('store_id', current_store_id())->findOrFail($id);
                $customer->delete();
                return response()->json([
                    'status' => true,
                    'message' => 'Customer deleted.',
                    'deletedIds' => [$id]
                ]);
            }

            $request->validate([
                'ids' => 'required|array|min:1',
                'ids.*' => 'exists:customers,id'
            ]);

            $deleted = [];
            $failed = [];

            foreach ($request->ids as $cid) {
                try {
                    $customer = Customer::where('store_id', current_store_id())->findOrFail($cid);
                    $customer->delete();
                    $deleted[] = $cid;
                } catch (Exception $e) {
                    $failed[] = ['id' => $cid, 'error' => $e->getMessage()];
                }
            }

            return response()->json([
                'status' => true,
                'message' => 'Bulk delete completed.',
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
        $fileName = 'customers_export_' . now()->format('Ymd_His') . '.xlsx';
        return Excel::download(new CustomerExport, $fileName);
    }
}
