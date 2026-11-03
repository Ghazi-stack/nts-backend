<?php

namespace App\Http\Controllers\api\Admin;

use App\Http\Controllers\Controller;
use App\Services\LocalizationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use InvalidArgumentException;
use Throwable;
use Illuminate\Support\Str;

class LocalizationController extends Controller
{
    protected LocalizationService $localizationService;

    /**
     * Recursively convert response array keys to camelCase.
     */
    private function toCamelCase(array $data): array
    {
        $result = [];

        foreach ($data as $key => $value) {
            $newKey = is_string($key)
                ? Str::camel($key)
                : $key;

            $result[$newKey] = is_array($value)
                ? $this->toCamelCase($value)
                : $value;
        }

        return $result;
    }

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
                'data' => $this->toCamelCase([
                    'country' => $settings->country,
                    'physical_dimensions' => $settings->physical_dimensions,
                    'date_timezone' => $settings->date_timezone,
                ]),
            ]);
        } catch (Throwable $e) {
            return $this->errorResponse($e);
        }
    }
    
    public function update(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'country' => 'required|array:defaultCountry,countryCode',
            'country.defaultCountry' => 'required|string|max:100',
            'country.countryCode' => 'required|string|size:2|alpha',

            'physicalDimensions' => 'required|array:weightUnit,lengthUnit,thousandsToken,decimalToken,decimalPlaces,factoringDimension',
            'physicalDimensions.weightUnit' => 'required|in:oz,lbs,g,kg,tonnes',
            'physicalDimensions.lengthUnit' => 'required|in:in,cm',
            'physicalDimensions.thousandsToken' => 'required|string|size:1',
            'physicalDimensions.decimalToken' => 'required|string|size:1|different:physicalDimensions.thousandsToken',
            'physicalDimensions.decimalPlaces' => 'required|integer|between:0,4',
            'physicalDimensions.factoringDimension' => 'required|in:product_depth,product_width,product_height',

            'dateTimezone' => 'required|array:timezone,dstEnabled,dateFormat,extendedDateTimeFormat',
            'dateTimezone.timezone' => 'required|timezone',
            'dateTimezone.dstEnabled' => 'required|boolean',
            'dateTimezone.dateFormat' => 'required|string',
            'dateTimezone.extendedDateTimeFormat' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed.',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $input = $validator->validated();

            // Convert camelCase request fields to snake_case
            // for the existing service and database structure.
            $validated = [
                'country' => [
                    'default_country' => $input['country']['defaultCountry'],
                    'country_code' => strtoupper($input['country']['countryCode']),
                ],

                'physical_dimensions' => [
                    'weight_unit' => $input['physicalDimensions']['weightUnit'],
                    'length_unit' => $input['physicalDimensions']['lengthUnit'],
                    'thousands_token' => $input['physicalDimensions']['thousandsToken'],
                    'decimal_token' => $input['physicalDimensions']['decimalToken'],
                    'decimal_places' => (int) $input['physicalDimensions']['decimalPlaces'],
                    'factoring_dimension' => $input['physicalDimensions']['factoringDimension'],
                ],

                'date_timezone' => [
                    'timezone' => $input['dateTimezone']['timezone'],
                    'dst_enabled' => (bool) $input['dateTimezone']['dstEnabled'],
                    'date_format' => $input['dateTimezone']['dateFormat'],
                    'extended_date_time_format' => $input['dateTimezone']['extendedDateTimeFormat'],
                ],
            ];

            $settings = $this->localizationService->updateAllSettings(
                $validated
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
        $validator = Validator::make($request->query(), [
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
                'data' => $this->toCamelCase($data),
            ]);
        } catch (Throwable $e) {
            return $this->errorResponse($e);
        }
    }

    
    public function standardDateTimezone(Request $request)
    {
        $validator = Validator::make($request->query(), [
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
                'data' => $this->toCamelCase($data),
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
            'data' => $this->toCamelCase([
                'country' => $settings->country,
                'physical_dimensions' => $settings->physical_dimensions,
                'date_timezone' => $settings->date_timezone,
            ]),
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
            'errors' => (object) [],
        ], $status);
    }
}
