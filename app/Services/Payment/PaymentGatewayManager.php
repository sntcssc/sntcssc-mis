<?php

namespace App\Services\Payment;

use App\Models\Setting;
use App\Services\Payment\Contracts\PaymentGatewayInterface;
use App\Services\Payment\Gateways\CCAVenueGateway;
use App\Services\Payment\Gateways\OfflineGateway;
use App\Services\Payment\Gateways\PhonePeGateway;
use App\Services\Payment\Gateways\RazorpayGateway;
use InvalidArgumentException;

class PaymentGatewayManager
{
    /**
     * @var array<string, PaymentGatewayInterface>
     */
    protected array $gateways = [];

    public function __construct()
    {
        $this->register(new RazorpayGateway);
        $this->register(new PhonePeGateway);
        $this->register(new CCAVenueGateway);
        $this->register(new OfflineGateway);
    }

    public function register(PaymentGatewayInterface $gateway): void
    {
        $this->gateways[$gateway->id()] = $gateway;
    }

    public function getGateway(string $driver): PaymentGatewayInterface
    {
        if (! isset($this->gateways[$driver])) {
            throw new InvalidArgumentException("Payment gateway driver [{$driver}] is not registered.");
        }

        return $this->gateways[$driver];
    }

    /**
     * Get all registered gateways.
     *
     * @return array<string, PaymentGatewayInterface>
     */
    public function all(): array
    {
        return $this->gateways;
    }

    /**
     * Get all active and ready gateways available for student checkout.
     *
     * @return array<string, PaymentGatewayInterface>
     */
    public function getAvailableGateways(): array
    {
        $available = [];
        $masterEnabled = (bool) Setting::get('payment.enabled', true);

        if ($masterEnabled) {
            foreach ($this->gateways as $id => $gateway) {
                if ($gateway->isOnline() && $gateway->isEnabled() && $gateway->isConfigured()) {
                    $available[$id] = $gateway;
                }
            }
        }

        // Offline gateway is available when enabled OR as safety fallback if no online gateways are active
        $offlineGateway = $this->gateways['offline'];
        $offlineEnabled = (bool) Setting::get('payment.offline_enabled', true);
        if ($offlineEnabled || empty($available)) {
            $available['offline'] = $offlineGateway;
        }

        return $available;
    }

    public function hasOnlineGateways(): bool
    {
        $masterEnabled = (bool) Setting::get('payment.enabled', false);
        if (! $masterEnabled) {
            return false;
        }

        foreach ($this->gateways as $gateway) {
            if ($gateway->isOnline() && $gateway->isEnabled() && $gateway->isConfigured()) {
                return true;
            }
        }

        return false;
    }

    public function getDefaultGateway(): PaymentGatewayInterface
    {
        $available = $this->getAvailableGateways();
        $preferredDriver = (string) Setting::get('payment.driver', 'razorpay');

        if (isset($available[$preferredDriver])) {
            return $available[$preferredDriver];
        }

        if (! empty($available)) {
            return reset($available);
        }

        return $this->gateways['offline'];
    }

    public function getOfflineGateway(): OfflineGateway
    {
        /** @var OfflineGateway $gateway */
        $gateway = $this->gateways['offline'];

        return $gateway;
    }
}
