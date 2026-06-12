<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use App\Models\User;
use App\Models\Store;
use App\Models\Logo;

class PasswordResetSuccessMail extends Mailable
{
    use Queueable, SerializesModels;

    public $user;
    public $logoUrl;
    public $storePath;
    public $store;

    public function __construct(User $user)
    {
        $this->user = $user;

        $storeId = $user->store_id ?? 10;
        $this->store = Store::find($storeId);

        $logo = Logo::where('store_id', $storeId)->first();
        $this->logoUrl = $logo ? $logo->logo_url : null;

        $storeUrls = [
            4  => 'https://new-town-spares.vercel.app',
            10 => 'https://server-blink.vercel.app',
        ];
        $this->storePath = $storeUrls[$storeId] ?? ($this->store?->base_url ?? '#');
    }

    public function build()
    {
        return $this->subject('Your Password Was Successfully Reset')
            ->view('emails.reset_password_successful')
            ->with([
                'name'      => $this->user->name,
                'email'     => $this->user->email,
                'logoUrl'   => $this->logoUrl,
                'storePath' => $this->storePath,
                'store'     => $this->store,
            ]);
    }
}
