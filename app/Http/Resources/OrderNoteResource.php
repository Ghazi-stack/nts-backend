<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OrderNoteResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'note' => $this->note,
            'user' => $this->user
                ? [
                    'name' => $this->user->name,
                    'email' => $this->user->email,
                ]
                : [
                    'name' => trim(optional($this->order)->first_name . ' ' . optional($this->order)->last_name),
                    'email' => optional($this->order)->email,
                ],
             'createdAt' => $this->created_at?->toDateString(),
            'updatedAt' => $this->updated_at?->toDateString(),
        ];
    }
}
