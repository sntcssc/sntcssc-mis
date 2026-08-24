<?php

use App\Models\ContactDepartment;
use App\Models\ContactSubject;
use App\Models\ContactSubmission;
use App\Models\Setting;
use App\Services\AuditLogService;
use App\Support\Toast;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Request;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts.public')] #[Title('Contact Us')] class extends Component {
    public array $form = [
        'name' => '',
        'email' => '',
        'mobile' => '',
        'whatsapp' => '',
        'department_id' => '',
        'subject_id' => '',
        'custom_subject' => '',
        'message' => '',
    ];

    // Anti-spam honeypot
    public string $website_url_hp = '';

    // Success state
    public bool $isSubmitted = false;
    public ?string $submittedReferenceNo = null;

    #[Computed]
    public function departments()
    {
        return ContactDepartment::active()->ordered()->get();
    }

    #[Computed]
    public function subjects()
    {
        $deptId = $this->form['department_id'] ?: null;

        return ContactSubject::active()
            ->when($deptId, fn ($q) => $q->where('department_id', $deptId))
            ->ordered()
            ->get();
    }

    public function updatedFormDepartmentId(): void
    {
        $this->form['subject_id'] = '';
    }

    public function submit(): void
    {
        // Bot honeypot trap
        if (! empty($this->website_url_hp)) {
            $this->isSubmitted = true;
            $this->submittedReferenceNo = 'SNT-REQ-'.date('Y').'-'.strtoupper(bin2hex(random_bytes(3)));

            return;
        }

        $this->validate([
            'form.name' => ['required', 'string', 'min:2', 'max:100'],
            'form.email' => ['required', 'email', 'max:150'],
            'form.mobile' => ['required', 'string', 'min:8', 'max:25'],
            'form.whatsapp' => ['nullable', 'string', 'max:25'],
            'form.department_id' => ['nullable', 'exists:contact_departments,id'],
            'form.subject_id' => ['nullable', 'exists:contact_subjects,id'],
            'form.custom_subject' => ['nullable', 'string', 'max:200'],
            'form.message' => ['required', 'string', 'min:10', 'max:3000'],
        ], [
            'form.name.required' => __('Please enter your full name.'),
            'form.email.required' => __('Please provide a valid email address.'),
            'form.mobile.required' => __('Please enter your mobile phone number.'),
            'form.message.required' => __('Please write your message or inquiry.'),
            'form.message.min' => __('Your message must be at least 10 characters long.'),
        ]);

        try {
            $submission = DB::transaction(function () {
                $refNo = ContactSubmission::generateReferenceNumber();

                $submission = ContactSubmission::create([
                    'reference_no' => $refNo,
                    'name' => trim($this->form['name']),
                    'email' => strtolower(trim($this->form['email'])),
                    'mobile' => trim($this->form['mobile']),
                    'whatsapp' => ! empty($this->form['whatsapp']) ? trim($this->form['whatsapp']) : null,
                    'department_id' => ! empty($this->form['department_id']) ? (int) $this->form['department_id'] : null,
                    'subject_id' => ! empty($this->form['subject_id']) ? (int) $this->form['subject_id'] : null,
                    'custom_subject' => ! empty($this->form['custom_subject']) ? trim($this->form['custom_subject']) : null,
                    'message' => trim($this->form['message']),
                    'ip_address' => Request::ip(),
                    'user_agent' => Request::userAgent(),
                    'status' => ContactSubmission::STATUS_NEW,
                    'priority' => ContactSubmission::PRIORITY_MEDIUM,
                ]);

                AuditLogService::log(
                    event: 'contact.submitted',
                    description: "Public contact inquiry submitted by {$submission->name} ({$submission->email}) with reference {$submission->reference_no}",
                    auditable: $submission
                );

                return $submission;
            });

            $this->submittedReferenceNo = $submission->reference_no;
            $this->isSubmitted = true;

            // Reset form
            $this->reset('form');

            Toast::dispatch($this, 'success', __('Your inquiry has been submitted successfully!'));
        } catch (\Throwable $e) {
            Toast::dispatch($this, 'error', __('An error occurred while submitting your inquiry. Please try again.'));
        }
    }

    public function resetSubmission(): void
    {
        $this->isSubmitted = false;
        $this->submittedReferenceNo = null;
    }
}; ?>

<div class="min-h-screen bg-background text-foreground antialiased selection:bg-primary selection:text-primary-foreground flex flex-col justify-between">
    @include('partials.head', [
        'title' => __('Contact Us') . ' — ' . Setting::siteName(),
        'description' => __('Get in touch with Satyendranath Tagore Civil Services Study Centre for admissions, counseling, courses, and guidance.'),
    ])

    {{-- Top Navigation Bar --}}
    <header class="sticky top-0 z-40 w-full border-b border-border/80 bg-background/80 backdrop-blur-md">
        <div class="max-w-7xl mx-auto flex h-16 sm:h-20 items-center justify-between px-4 sm:px-6 lg:px-8">
            <div class="flex items-center gap-3">
                <x-app-logo :href="route('home')"/>
            </div>

            {{-- Right Navigation Controls --}}
            <div class="flex items-center gap-2 sm:gap-4">
                <nav class="hidden md:flex items-center gap-4 text-xs sm:text-sm font-medium text-muted-foreground mr-1">
                    <a href="{{ route('home') }}" class="hover:text-foreground transition-colors">{{ __('Home') }}</a>
                    <a href="{{ route('public.page', 'about-us') }}" class="hover:text-foreground transition-colors">{{ __('About Us') }}</a>
                </nav>

                <x-locale-switcher/>
                <x-ui.theme-switch/>

                <div class="flex items-center gap-2 pl-1 sm:pl-2 border-l border-border/60">
                    @auth
                        @php($dashUrl = auth()->user()?->currentTeam ? route('dashboard', auth()->user()->currentTeam->slug) : url('/dashboard'))
                        <a
                            href="{{ $dashUrl }}"
                            class="inline-flex items-center gap-1.5 rounded-lg bg-primary px-3.5 py-2 text-xs sm:text-sm font-semibold text-primary-foreground shadow-xs hover:bg-primary/90 transition-all cursor-pointer"
                        >
                            <x-icon name="layout-dashboard" class="h-4 w-4"/>
                            <span class="hidden xs:inline">{{ __('Dashboard') }}</span>
                        </a>
                    @else
                        <a
                            href="{{ route('login') }}"
                            class="inline-flex items-center gap-1.5 rounded-lg border border-border bg-card px-3 py-2 text-xs sm:text-sm font-medium text-foreground hover:bg-secondary transition-colors cursor-pointer shadow-2xs"
                        >
                            <x-icon name="log-in" class="h-3.5 w-3.5 text-muted-foreground"/>
                            <span>{{ __('Log in') }}</span>
                        </a>
                    @endauth
                </div>
            </div>
        </div>
    </header>

    {{-- Main Contact Page Content --}}
    <main class="flex-1 py-10 sm:py-16">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            {{-- Header Section --}}
            <div class="text-center max-w-3xl mx-auto mb-10 sm:mb-14">
                <div class="inline-flex items-center gap-2 rounded-full border border-primary/25 bg-primary/10 px-4 py-1.5 text-xs sm:text-sm font-semibold text-primary mb-4 shadow-2xs">
                    <x-icon name="mail" class="h-4 w-4"/>
                    <span>{{ __('Admissions & Academic Helpdesk') }}</span>
                </div>
                <h1 class="text-3xl sm:text-5xl font-extrabold tracking-tight text-foreground leading-tight">
                    {{ __('Get in Touch with Our Team') }}
                </h1>
                <p class="mt-4 text-sm sm:text-base text-muted-foreground leading-relaxed">
                    {{ __('Have inquiries about UPSC / Civil Services admissions, classroom batches, fee structures, or guidance? Fill out the form below or visit our campus.') }}
                </p>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-12 gap-8 lg:gap-12 items-start">
                {{-- Left Column: Contact Form --}}
                <div class="lg:col-span-7 bg-card border border-border rounded-2xl p-6 sm:p-10 shadow-xs">
                    @if ($isSubmitted)
                        {{-- Success Confirmation Card --}}
                        <div class="text-center py-8 space-y-4">
                            <div class="mx-auto flex h-16 w-16 items-center justify-center rounded-full bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 mb-2">
                                <x-icon name="shield-check" class="h-8 w-8"/>
                            </div>
                            <h3 class="text-xl sm:text-2xl font-bold text-foreground">
                                {{ __('Thank you! Your inquiry has been received.') }}
                            </h3>
                            <p class="text-sm text-muted-foreground max-w-md mx-auto leading-relaxed">
                                {{ __('Our admissions and counseling desk will review your inquiry and get back to you shortly via email or phone.') }}
                            </p>

                            <div class="bg-muted border border-border rounded-xl p-4 max-w-md mx-auto text-center mt-6">
                                <p class="text-xs text-muted-foreground uppercase font-semibold tracking-wider mb-1">{{ __('Your Tracking Reference Number') }}</p>
                                <p class="text-lg font-mono font-bold text-primary select-all">{{ $submittedReferenceNo }}</p>
                                <p class="text-[11px] text-muted-foreground mt-1">{{ __('Please save this reference number for all future communications.') }}</p>
                            </div>

                            <div class="pt-6">
                                <button
                                    type="button"
                                    wire:click="resetSubmission"
                                    class="inline-flex items-center gap-2 rounded-xl bg-primary px-5 py-2.5 text-sm font-semibold text-primary-foreground shadow-xs hover:bg-primary/90 transition-all cursor-pointer"
                                >
                                    <x-icon name="send" class="h-4 w-4"/>
                                    <span>{{ __('Submit Another Inquiry') }}</span>
                                </button>
                            </div>
                        </div>
                    @else
                        {{-- Interactive Contact Form --}}
                        <form wire:submit="submit" class="space-y-5">
                            {{-- Bot honeypot field (hidden) --}}
                            <div class="hidden" aria-hidden="true">
                                <input type="text" wire:model="website_url_hp" tabindex="-1" autocomplete="off">
                            </div>

                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                {{-- Full Name --}}
                                <div>
                                    <label class="text-[11px] font-semibold uppercase tracking-wider text-muted-foreground block mb-1.5">
                                        {{ __('Full Name') }} <span class="text-destructive">*</span>
                                    </label>
                                    <input
                                        type="text"
                                        wire:model="form.name"
                                        placeholder="{{ __('e.g. Subhas Chandra Bose') }}"
                                        class="h-10 w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs outline-none placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50"
                                        required
                                    />
                                    @error('form.name') <p class="mt-1 text-xs text-destructive">{{ $message }}</p> @enderror
                                </div>

                                {{-- Email Address --}}
                                <div>
                                    <label class="text-[11px] font-semibold uppercase tracking-wider text-muted-foreground block mb-1.5">
                                        {{ __('Email Address') }} <span class="text-destructive">*</span>
                                    </label>
                                    <input
                                        type="email"
                                        wire:model="form.email"
                                        placeholder="{{ __('name@example.com') }}"
                                        class="h-10 w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs outline-none placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50"
                                        required
                                    />
                                    @error('form.email') <p class="mt-1 text-xs text-destructive">{{ $message }}</p> @enderror
                                </div>
                            </div>

                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                {{-- Mobile Number --}}
                                <div>
                                    <label class="text-[11px] font-semibold uppercase tracking-wider text-muted-foreground block mb-1.5">
                                        {{ __('Mobile Number') }} <span class="text-destructive">*</span>
                                    </label>
                                    <input
                                        type="tel"
                                        wire:model="form.mobile"
                                        placeholder="{{ __('+91 98765 43210') }}"
                                        class="h-10 w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs outline-none placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50"
                                        required
                                    />
                                    @error('form.mobile') <p class="mt-1 text-xs text-destructive">{{ $message }}</p> @enderror
                                </div>

                                {{-- WhatsApp Number --}}
                                <div>
                                    <label class="text-[11px] font-semibold uppercase tracking-wider text-muted-foreground block mb-1.5">
                                        {{ __('WhatsApp Number') }} <span class="text-muted-foreground/60 font-normal">({{ __('Optional') }})</span>
                                    </label>
                                    <input
                                        type="tel"
                                        wire:model="form.whatsapp"
                                        placeholder="{{ __('Same as mobile or WhatsApp #') }}"
                                        class="h-10 w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs outline-none placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50"
                                    />
                                    @error('form.whatsapp') <p class="mt-1 text-xs text-destructive">{{ $message }}</p> @enderror
                                </div>
                            </div>

                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                {{-- Pre-defined Department --}}
                                <div>
                                    <label class="text-[11px] font-semibold uppercase tracking-wider text-muted-foreground block mb-1.5">
                                        {{ __('Department / Inquiry Category') }}
                                    </label>
                                    <select
                                        wire:model.live="form.department_id"
                                        class="h-10 w-full rounded-md border border-input bg-card px-3 py-2 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50"
                                    >
                                        <option value="">{{ __('Select Department (Optional)') }}</option>
                                        @foreach ($this->departments as $dept)
                                            <option value="{{ $dept->id }}">{{ $dept->name }}</option>
                                        @endforeach
                                    </select>
                                    @error('form.department_id') <p class="mt-1 text-xs text-destructive">{{ $message }}</p> @enderror
                                </div>

                                {{-- Pre-defined Subject --}}
                                <div>
                                    <label class="text-[11px] font-semibold uppercase tracking-wider text-muted-foreground block mb-1.5">
                                        {{ __('Specific Topic / Subject') }}
                                    </label>
                                    <select
                                        wire:model="form.subject_id"
                                        class="h-10 w-full rounded-md border border-input bg-card px-3 py-2 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50"
                                    >
                                        <option value="">{{ __('Select Topic / Subject') }}</option>
                                        @foreach ($this->subjects as $subj)
                                            <option value="{{ $subj->id }}">{{ $subj->name }}</option>
                                        @endforeach
                                    </select>
                                    @error('form.subject_id') <p class="mt-1 text-xs text-destructive">{{ $message }}</p> @enderror
                                </div>
                            </div>

                            {{-- Custom Subject fallback --}}
                            <div>
                                <label class="text-[11px] font-semibold uppercase tracking-wider text-muted-foreground block mb-1.5">
                                    {{ __('Subject / Title of Request') }} <span class="text-muted-foreground/60 font-normal">({{ __('Optional brief title') }})</span>
                                </label>
                                <input
                                    type="text"
                                    wire:model="form.custom_subject"
                                    placeholder="{{ __('e.g. Query regarding Prelims Mock Test Series schedule') }}"
                                    class="h-10 w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs outline-none placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50"
                                />
                                @error('form.custom_subject') <p class="mt-1 text-xs text-destructive">{{ $message }}</p> @enderror
                            </div>

                            {{-- Message Textarea --}}
                            <div>
                                <label class="text-[11px] font-semibold uppercase tracking-wider text-muted-foreground block mb-1.5">
                                    {{ __('Your Message / Detailed Query') }} <span class="text-destructive">*</span>
                                </label>
                                <textarea
                                    wire:model="form.message"
                                    rows="5"
                                    placeholder="{{ __('Please describe your query with any relevant details, batch names, or requirements…') }}"
                                    class="w-full rounded-md border border-input bg-transparent px-3 py-2.5 text-sm shadow-xs outline-none placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50"
                                    required
                                ></textarea>
                                @error('form.message') <p class="mt-1 text-xs text-destructive">{{ $message }}</p> @enderror
                            </div>

                            {{-- Submit Button --}}
                            <div class="pt-2">
                                <button
                                    type="submit"
                                    wire:loading.attr="disabled"
                                    class="w-full sm:w-auto inline-flex items-center justify-center gap-2 rounded-xl bg-primary px-7 py-3 text-sm font-semibold text-primary-foreground shadow-md hover:bg-primary/90 transition-all cursor-pointer disabled:opacity-50"
                                >
                                    <x-icon name="send" class="h-4 w-4" wire:loading.remove/>
                                    <span class="inline-block h-4 w-4 animate-spin rounded-full border-2 border-primary-foreground border-t-transparent" wire:loading></span>
                                    <span>{{ __('Submit Inquiry') }}</span>
                                </button>
                            </div>
                        </form>
                    @endif
                </div>

                {{-- Right Column: Institution Info & Embedded Map --}}
                <div class="lg:col-span-5 space-y-6">
                    {{-- Office Information Card --}}
                    <div class="bg-card border border-border rounded-2xl p-6 sm:p-7 shadow-2xs space-y-5">
                        <h3 class="text-base font-bold text-foreground flex items-center gap-2">
                            <x-icon name="building-2" class="h-5 w-5 text-primary"/>
                            <span>{{ __('Campus & Office Details') }}</span>
                        </h3>

                        <div class="space-y-4 text-xs sm:text-sm">
                            {{-- Address --}}
                            <div class="flex items-start gap-3">
                                <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-primary/10 text-primary mt-0.5">
                                    <x-icon name="map-pin" class="h-4 w-4"/>
                                </span>
                                <div>
                                    <p class="font-semibold text-foreground">{{ __('Campus Address') }}</p>
                                    <p class="text-muted-foreground mt-0.5 leading-relaxed">
                                        {{ Setting::get('general.site_address', 'SNT CSSC, Main Campus, Kolkata, West Bengal, India') }}
                                    </p>
                                </div>
                            </div>

                            {{-- Phone & Mobile --}}
                            <div class="flex items-start gap-3">
                                <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-primary/10 text-primary mt-0.5">
                                    <x-icon name="phone" class="h-4 w-4"/>
                                </span>
                                <div>
                                    <p class="font-semibold text-foreground">{{ __('Telephone & Helpdesk') }}</p>
                                    <p class="text-muted-foreground mt-0.5">
                                        {{ Setting::get('general.site_phone', '033 0000 0000') }} / {{ Setting::get('general.site_mobile', '+91 90000 00000') }}
                                    </p>
                                </div>
                            </div>

                            {{-- Email --}}
                            <div class="flex items-start gap-3">
                                <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-primary/10 text-primary mt-0.5">
                                    <x-icon name="mail" class="h-4 w-4"/>
                                </span>
                                <div>
                                    <p class="font-semibold text-foreground">{{ __('Official Email') }}</p>
                                    <p class="text-muted-foreground mt-0.5">
                                        <a href="mailto:{{ Setting::get('general.site_email', 'info@sntcssc.in') }}" class="text-primary hover:underline">
                                            {{ Setting::get('general.site_email', 'info@sntcssc.in') }}
                                        </a>
                                    </p>
                                </div>
                            </div>

                            {{-- Office Hours --}}
                            <div class="flex items-start gap-3">
                                <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-primary/10 text-primary mt-0.5">
                                    <x-icon name="clock" class="h-4 w-4"/>
                                </span>
                                <div>
                                    <p class="font-semibold text-foreground">{{ __('Office Hours & Working Days') }}</p>
                                    <p class="text-muted-foreground mt-0.5">
                                        {{ Setting::get('general.site_timing', '10:00 AM – 6:00 PM') }} ({{ Setting::get('general.site_open_days', 'Monday – Saturday') }})
                                    </p>
                                </div>
                            </div>
                        </div>
                    </div>

                    {{-- Embedded Google Map --}}
                    <div class="bg-card border border-border rounded-2xl overflow-hidden shadow-2xs">
                        <div class="p-4 border-b border-border flex items-center justify-between">
                            <span class="text-xs font-bold uppercase tracking-wider text-muted-foreground flex items-center gap-1.5">
                                <x-icon name="map" class="h-4 w-4 text-primary"/>
                                <span>{{ __('Campus Location Map') }}</span>
                            </span>
                            <span class="text-[11px] text-muted-foreground">Kolkata, WB</span>
                        </div>
                        <div class="aspect-4/3 w-full bg-muted">
                            <iframe
                                title="SNTCSSC Campus Location"
                                src="https://www.google.com/maps/embed?pb=!1m18!1m12!1m3!1d117925.21689712753!2d88.26495116345914!3d22.53556488349581!2m3!1f0!2f0!3f0!3m2!1i1024!2i768!4f13.1!3m3!1m2!1s0x39f882db4908f667%3A0x43e330e68f6c2cbc!2sKolkata%2C%20West%20Bengal!5e0!3m2!1sen!2sin!4v1700000000000!5m2!1sen!2sin"
                                width="100%"
                                height="100%"
                                style="border:0;"
                                allowfullscreen=""
                                loading="lazy"
                                referrerpolicy="no-referrer-when-downgrade"
                                class="w-full h-full min-h-[260px]"
                            ></iframe>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </main>

    {{-- Footer --}}
    <footer class="border-t border-border bg-card/60 py-8 sm:py-10 text-xs text-muted-foreground">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 flex flex-col sm:flex-row items-center justify-between gap-4">
            <div class="flex items-center gap-2.5">
                <x-app-logo :hideText="true"/>
                <span class="font-semibold text-sm text-foreground">{{ Setting::appName() }}</span>
            </div>

            <p class="text-center">{{ Setting::copyrightText() }}</p>

            <div class="flex items-center gap-4">
                <a href="{{ route('home') }}" class="hover:text-foreground transition-colors">{{ __('Home') }}</a>
                <a href="{{ route('public.page', 'about-us') }}" class="hover:text-foreground transition-colors">{{ __('About Us') }}</a>
                <a href="{{ route('login') }}" class="hover:text-foreground transition-colors">{{ __('Portal Login') }}</a>
            </div>
        </div>
    </footer>
</div>
