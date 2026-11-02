<?php

namespace App\Http\Controllers\api\Admin;

use App\Http\Controllers\Controller;
use App\Services\LocalizationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use InvalidArgumentException;
use Throwable;

class LocalizationController extends Controller
{
    protected LocalizationService $localizationService;

    public function __construct(LocalizationService $localizationService)
    {
        $this->localizationService = $localizationService;
    }

    /**
     * GET: Fetch all localization settings.
     */
    public function index()
    {
        try {
            $settings = $this->localizationService->getSettings();

            return response()->json([
                'success' => true,
                'message' => 'Localization settings retrieved successfully.',
                'data' => [
                    'country' => $settings->country,
                    'physical_dimensions' => $settings->physical_dimensions,
                    'date_timezone' => $settings->date_timezone,
                ],
            ]);
        } catch (Throwable $e) {
            return $this->errorResponse($e);
        }
    }

    
    public function update(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'country' => 'required|array',
            'country.default_country' => 'required|string|max:100',
            'country.country_code' => 'required|string|size:2|alpha',

            'physical_dimensions' => 'required|array',
            'physical_dimensions.weight_unit' => 'required|in:oz,lbs,g,kg,tonnes',
            'physical_dimensions.length_unit' => 'required|in:in,cm',
            'physical_dimensions.thousands_token' => 'required|string|size:1',
            'physical_dimensions.decimal_token' => 'required|string|size:1|different:physical_dimensions.thousands_token',
            'physical_dimensions.decimal_places' => 'required|integer|between:0,4',
            'physical_dimensions.factoring_dimension' => 'required|in:product_depth,product_width,product_height',

            'date_timezone' => 'required|array',
            'date_timezone.timezone' => 'required|timezone',
            'date_timezone.dst_enabled' => 'required|boolean',
            'date_timezone.date_format' => 'required|string',
            'date_timezone.extended_date_time_format' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed.',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $settings = $this->localizationService->updateAllSettings(
                $validator->validated()
            );

            return $this->successResponse(
                'Localization settings updated successfully.',
                $settings
            );
        } catch (Throwable $e) {
            return $this->errorResponse($e);
        }
    }

    
    public function standardPhysicalDimensions(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'country' => 'required|string|size:2|alpha',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed.',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $data = $this->localizationService
                ->getStandardPhysicalDimensions(
                    strtoupper($validator->validated()['country'])
                );

            return response()->json([
                'success' => true,
                'message' => 'Standard physical dimensions retrieved successfully.',
                'data' => $data,
            ]);
        } catch (Throwable $e) {
            return $this->errorResponse($e);
        }
    }

    
    public function standardDateTimezone(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'country' => 'required|string|size:2|alpha',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed.',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $data = $this->localizationService
                ->getStandardDateTimezone(
                    strtoupper($validator->validated()['country'])
                );

            return response()->json([
                'success' => true,
                'message' => 'Standard date and timezone formats retrieved successfully.',
                'data' => $data,
            ]);
        } catch (Throwable $e) {
            return $this->errorResponse($e);
        }
    }



    /**
     * Standard success response.
     */
    private function successResponse(string $message, $settings)
    {
        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => [
                'country' => $settings->country,
                'physical_dimensions' => $settings->physical_dimensions,
                'date_timezone' => $settings->date_timezone,
            ],
        ]);
    }

    /**
     * Standard error response.
     */
    private function errorResponse(Throwable $e)
    {
        $status = $e instanceof InvalidArgumentException ? 422 : 500;

        report($e);

        return response()->json([
            'success' => false,
            'message' => $status === 422
                ? $e->getMessage()
                : 'Unable to process localization settings.',
        ], $status);
    }
}
