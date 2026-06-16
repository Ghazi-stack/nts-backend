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
        $storeId = $notifiable->store_id ?? 10;

        $store = Store::find($storeId);

        $logo = Logo::where('store_id', $storeId)->first();

        if (!$store || empty($store->base_url)) {
            throw new \Exception('Store URL not found.');
        }

        $storePath = rtrim($store->base_url, '/');

        $resetUrl = $storePath .
            '/auth/reset-password?token=' . $this->token .
            '&email=' . urlencode($notifiable->email);

        return (new MailMessage)
            ->subject('Change your password for ' . ($store?->name ?? 'Our Store'))
            ->view('emails.reset_password', [
                'resetUrl'  => $resetUrl,
                'store'     => $store,
                'logoUrl'   => $logo ? $logo->logo_url : null,
                'storePath' => $storePath,
            ]);
    }
}
