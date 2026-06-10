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

        $storeUrls = [
            4  => 'https://new-town-spares.vercel.app',
            10 => 'https://server-blink.vercel.app',
        ];
        $storePath = $storeUrls[$storeId] ?? ($store?->base_url ?? '#');

        $resetUrl = $storePath . '/reset-password?token=' . $this->token . '&email=' . urlencode($notifiable->email);

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
