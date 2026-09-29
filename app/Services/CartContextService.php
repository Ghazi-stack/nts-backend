<?php

namespace App\Services;

use App\Models\UserCartContext;

class CartContextService
{
    /**
     * Get the current quote token for the current identity.
     *
     * Logged-in user:
     *   store_id + user_id
     *
     * Guest:
     *   store_id + session_id
     */
    public function getCurrentQuoteToken(
        int $storeId,
        $user = null,
        ?string $sessionId = null
    ): ?string {
        $query = UserCartContext::query()
            ->where('store_id', $storeId);

        if ($user) {
            $query->where('user_id', $user->id);
        } else {
            if (!$sessionId) {
                return null;
            }

            $query->whereNull('user_id')
                ->where('session_id', $sessionId);
        }

        return $query->value('quote_token');
    }

    /**
     * Set the current draft for the current identity.
     */
    public function setCurrentDraft(
        int $storeId,
        $user = null,
        ?string $sessionId = null,
        string $quoteToken = ''
    ): UserCartContext {
        if ($user) {
            return UserCartContext::updateOrCreate(
                [
                    'store_id' => $storeId,
                    'user_id'  => $user->id,
                ],
                [
                    'session_id' => null,
                    'quote_token' => $quoteToken,
                ]
            );
        }

        return UserCartContext::updateOrCreate(
            [
                'store_id'   => $storeId,
                'session_id' => $sessionId,
            ],
            [
                'user_id'    => null,
                'quote_token' => $quoteToken,
            ]
        );
    }

    /**
     * Switch the current identity back to the normal cart.
     */
    public function setNormalCart(
        int $storeId,
        $user = null,
        ?string $sessionId = null
    ): UserCartContext {
        if ($user) {
            return UserCartContext::updateOrCreate(
                [
                    'store_id' => $storeId,
                    'user_id'  => $user->id,
                ],
                [
                    'session_id' => null,
                    'quote_token' => null,
                ]
            );
        }

        return UserCartContext::updateOrCreate(
            [
                'store_id'   => $storeId,
                'session_id' => $sessionId,
            ],
            [
                'user_id'    => null,
                'quote_token' => null,
            ]
        );
    }

    /**
     * Check whether the current identity has an active draft.
     */
    public function isDraftActive(
        int $storeId,
        $user = null,
        ?string $sessionId = null
    ): bool {
        return !empty(
            $this->getCurrentQuoteToken(
                $storeId,
                $user,
                $sessionId
            )
        );
    }
}