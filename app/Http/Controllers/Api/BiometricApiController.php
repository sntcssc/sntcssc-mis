<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BiometricDevice;
use App\Models\BiometricLog;
use App\Services\Attendance\AttendanceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class BiometricApiController extends Controller
{
    public function __construct(
        protected AttendanceService $attendanceService
    ) {}

    /**
     * Authenticate device from request token.
     */
    protected function authenticateDevice(Request $request): ?BiometricDevice
    {
        $token = $request->bearerToken() ?: $request->header('X-Device-Token') ?: $request->input('device_token');

        if (! $token) {
            return null;
        }

        return BiometricDevice::where('api_token', $token)->first();
    }

    /**
     * Push biometric punches from devices/machines.
     */
    public function pushPunches(Request $request): JsonResponse
    {
        $device = $this->authenticateDevice($request);
        if (! $device) {
            return response()->json([
                'status' => 'error',
                'message' => 'Unauthorized biometric device. Invalid or missing API token.',
            ], 401);
        }

        $punches = $request->input('punches');
        if (! is_array($punches)) {
            // Single punch format
            $punches = [
                [
                    'biometric_pin' => $request->input('biometric_pin') ?: $request->input('user_id'),
                    'card_number' => $request->input('card_number'),
                    'punch_time' => $request->input('punch_time', Carbon::now()->toDateTimeString()),
                    'punch_type' => $request->input('punch_type', 'auto'),
                ],
            ];
        }

        $createdCount = 0;
        foreach ($punches as $p) {
            if (empty($p['biometric_pin']) && empty($p['card_number'])) {
                continue;
            }

            BiometricLog::create([
                'biometric_device_id' => $device->id,
                'biometric_pin' => $p['biometric_pin'] ?? null,
                'card_number' => $p['card_number'] ?? null,
                'punch_time' => ! empty($p['punch_time']) ? Carbon::parse($p['punch_time']) : Carbon::now(),
                'punch_type' => $p['punch_type'] ?? 'auto',
                'raw_payload' => $p,
                'is_processed' => false,
            ]);

            $createdCount++;
        }

        // Update device heartbeat and status
        $device->updateQuietly([
            'last_heartbeat_at' => Carbon::now(),
            'status' => 'online',
        ]);

        // Trigger immediate processing of punches
        $this->attendanceService->processBiometricPunches(100);

        return response()->json([
            'status' => 'success',
            'message' => "Successfully recorded {$createdCount} biometric punches.",
            'received' => $createdCount,
        ]);
    }

    /**
     * Device health heartbeat check.
     */
    public function heartbeat(Request $request): JsonResponse
    {
        $device = $this->authenticateDevice($request);
        if (! $device) {
            return response()->json([
                'status' => 'error',
                'message' => 'Unauthorized device.',
            ], 401);
        }

        $device->updateQuietly([
            'last_heartbeat_at' => Carbon::now(),
            'status' => 'online',
        ]);

        return response()->json([
            'status' => 'ok',
            'device' => $device->device_name,
            'server_time' => Carbon::now()->toIso8601String(),
        ]);
    }
}
