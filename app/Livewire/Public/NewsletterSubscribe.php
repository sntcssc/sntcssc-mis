<?php

namespace App\Livewire\Public;

use App\Models\Subscriber;
use App\Services\SubscriberService;
use Livewire\Component;

class NewsletterSubscribe extends Component
{
    public string $type = 'email'; // email, whatsapp, both

    public string $email = '';

    public string $phone = '';

    public string $country_code = '+91';

    public string $name = '';

    public string $honeypot = '';

    public bool $subscribed = false;

    public string $successMessage = '';

    public function setType(string $type): void
    {
        $this->type = in_array($type, ['email', 'whatsapp', 'both'], true) ? $type : 'email';
        $this->resetErrorBag();
    }

    public function subscribe(SubscriberService $service): void
    {
        // Anti-bot honeypot check
        if (! empty($this->honeypot)) {
            return;
        }

        $rules = [];
        if ($this->type === 'email' || $this->type === 'both') {
            $rules['email'] = ['required', 'email', 'max:255'];
        }
        if ($this->type === 'whatsapp' || $this->type === 'both') {
            $rules['phone'] = ['required', 'string', 'min:8', 'max:20'];
            $rules['country_code'] = ['required', 'string', 'max:10'];
        }
        if (! empty($this->name)) {
            $rules['name'] = ['nullable', 'string', 'max:100'];
        }

        $this->validate($rules);

        try {
            $service->subscribe([
                'type' => $this->type,
                'email' => ! empty($this->email) ? $this->email : null,
                'phone' => ! empty($this->phone) ? $this->phone : null,
                'country_code' => $this->country_code,
                'name' => $this->name,
                'source' => Subscriber::SOURCE_WELCOME,
                'tags' => ['welcome_page', $this->type],
                'ip_address' => request()->ip(),
                'user_agent' => request()->userAgent(),
            ]);

            $this->subscribed = true;
            $this->successMessage = $this->type === 'whatsapp'
                ? __('Thank you! You are now subscribed to WhatsApp alerts and announcements.')
                : ($this->type === 'both'
                    ? __('Thank you! You have successfully subscribed to Email newsletters and WhatsApp alerts.')
                    : __('Thank you! You have successfully subscribed to our newsletter updates.'));

            $this->reset(['email', 'phone', 'name', 'honeypot']);
        } catch (\Throwable $e) {
            $this->addError('subscription', __('An error occurred while processing your subscription. Please try again.'));
        }
    }

    public function render()
    {
        return view('livewire.public.newsletter-subscribe');
    }
}
