<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\Subscriber;
use App\Services\SubscriberService;
use Illuminate\Http\Request;
use Illuminate\View\View;

class UnsubscribeController extends Controller
{
    public function show(string $token): View
    {
        $subscriber = Subscriber::where('unsubscribe_token', $token)->first();

        return view('pages.public.unsubscribe', [
            'subscriber' => $subscriber,
            'token' => $token,
        ]);
    }

    public function process(Request $request, string $token, SubscriberService $service)
    {
        $request->validate([
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        $subscriber = $service->unsubscribe($token, $request->input('reason'));

        return redirect()->route('unsubscribe.show', $token)->with('success', __('You have been successfully unsubscribed.'));
    }
}
