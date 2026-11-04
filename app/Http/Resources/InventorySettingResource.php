<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InventorySettingResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            'id'                                => $this->id,
            'storeId'                           => $this->store_id,

            'productNoLongerSold'               => $this->product_no_longer_sold,
            'optionNoLongerSold'                => $this->option_no_longer_sold,
            'updateStockLevels'                 => $this->update_stock_levels,
            'autoAdjustStockOnRefundCancel'     => (bool) $this->auto_adjust_stock_on_refund_cancel,

            'stockLevelDisplay'                 => $this->stock_level_display,
            'outOfStockMessage'                 => $this->out_of_stock_message,
            'showOosOnListing'                  => (bool) $this->show_oos_on_listing,

            'showQuantityOnBackorder'           => (bool) $this->show_quantity_on_backorder,
            'showBackorderMessage'              => (bool) $this->show_backorder_message,
            'backorderMessages'                 => $this->backorder_messages ?? [],

            'showQuantityOnHand'                => (bool) $this->show_quantity_on_hand,
            'showBackorderAvailabilityPrompt'   => (bool) $this->show_backorder_availability_prompt,
            'moreAvailableForBackorder'         => $this->more_available_for_backorder,
            'showShippingExpectation'           => (bool) $this->show_shipping_expectation,
            'shippingExpectationMessage'        => $this->shipping_expectation_message,

            'lowStockEmail'                     => $this->low_stock_email,
            'outOfStockEmail'                   => $this->out_of_stock_email,

            'createdAt'                         => $this->created_at,
            'updatedAt'                         => $this->updated_at,
        ];
    }
}
