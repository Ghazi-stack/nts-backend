<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;
use Illuminate\Notifications\Messages\MailMessage;
use App\Models\Store;
use App\Models\Logo;

class CustomResetPasswordNotification extends Notification
{
    public $token;

    public function __construct($token)
    {
        $this->token = $token;
    }

    public function via($notifiable)
    {
        return ['mail'];
    }

    public function toMail($notifiable)
    {
        // Use the customer's legacy store ID.
        $storeId = $notifiable->store_id ?? current_store_id();

        if (!$storeId) {
            throw new \Exception('Legacy store ID not found.');
        }

        // Always resolve the store from the central registry.
        $store = Store::on('central')
            ->where('legacy_store_id', $storeId)
            ->first();

        if (!$store || empty($store->base_url)) {
            throw new \Exception(
                "Store not found for legacy_store_id: {$storeId}"
            );
        }

        // Use the same legacy ID for the tenant logo.
        $logo = Logo::where('store_id', $storeId)->first();

        $storePath = rtrim($store->base_url, '/');

        $resetUrl = $storePath
            . '/auth/reset-password?token=' . urlencode($this->token)
            . '&email=' . urlencode($notifiable->email);

        // Store's dashboard template ("Password Reset") when live, else the
        // default Blade email. This email is always sent (never blocked by
        // the template toggle) so customers can always reset their password.
        $rendered = app(\App\Services\TransactionalEmailService::class)->renderForMail(
            $storeId,
            'password_reset',
            fn() => \App\Services\TransactionalEmailContext::base($store, $logo?->logo_url) + [
                'customer' => \App\Services\TransactionalEmailContext::customer(
                    null,
                    $notifiable->email ?? null,
                    $notifiable->name ?? null
                ),
                'links'    => ['reset_password' => $resetUrl, 'account' => $storePath],
            ]
        );

        if ($rendered) {
            return (new \App\Mail\TransactionalTemplatedMail(
                $rendered['subject'],
                $rendered['html'],
                $rendered['text'],
                $rendered['headers'],
                storeId: (int) $storeId
            ))->to($notifiable->email);
        }

        return (new MailMessage)
            ->from(
                config('mail.from.address'),
                $store->name ?: config('mail.from.name')
            )
            ->subject('Change your password for ' . $store->name)
            ->view('emails.reset_password', [
                'resetUrl'  => $resetUrl,
                'store'     => $store,
                'logoUrl'   => $logo?->logo_url,
                'storePath' => $storePath,
            ]);

    }
}
