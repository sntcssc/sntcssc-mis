<div class="rounded-3xl border border-primary/20 bg-card/90 backdrop-blur-md p-6 sm:p-10 shadow-xl relative overflow-hidden">
    {{-- Decorative Ambient Glow --}}
    <div class="absolute -right-20 -top-20 h-60 w-60 rounded-full bg-primary/10 blur-3xl pointer-events-none"></div>

    <div class="relative z-10 max-w-2xl mx-auto text-center space-y-6">
        <div>
            <div class="inline-flex items-center gap-2 rounded-full border border-primary/30 bg-primary/10 px-3.5 py-1 text-xs font-bold text-primary uppercase tracking-wider mb-3">
                <x-icon name="sparkles" class="h-3.5 w-3.5"/>
                <span>{{ __('Stay Updated & Informed') }}</span>
            </div>
            <h3 class="text-2xl sm:text-3xl font-extrabold tracking-tight text-foreground">
                {{ __('Subscribe to Notifications & Newsletter') }}
            </h3>
            <p class="text-xs sm:text-sm text-muted-foreground mt-2 max-w-lg mx-auto">
                {{ __('Receive instant alerts for upcoming admissions, syllabus releases, test series results, and civil services guidance directly to your inbox or WhatsApp.') }}
            </p>
        </div>

        @if ($subscribed)
            <div class="p-5 rounded-2xl bg-emerald-500/10 border border-emerald-500/30 text-emerald-700 dark:text-emerald-300 animate-in fade-in zoom-in-95 space-y-2">
                <div class="flex items-center justify-center gap-2 font-bold text-sm sm:text-base">
                    <x-icon name="check-circle-2" class="h-5 w-5 text-emerald-600 dark:text-emerald-400"/>
                    <span>{{ __('Subscription Confirmed!') }}</span>
                </div>
                <p class="text-xs text-muted-foreground">{{ $successMessage }}</p>
                <button
                    type="button"
                    wire:click="$set('subscribed', false)"
                    class="text-xs text-primary hover:underline font-medium mt-2 cursor-pointer"
                >
                    {{ __('Subscribe another contact or update preferences') }}
                </button>
            </div>
        @else
            {{-- Tab Switcher --}}
            <div class="inline-flex p-1 rounded-xl bg-secondary/80 border border-border">
                <button
                    type="button"
                    wire:click="setType('email')"
                    class="px-4 py-2 text-xs font-semibold rounded-lg transition-all flex items-center gap-2 {{ $type === 'email' ? 'bg-primary text-primary-foreground shadow-2xs' : 'text-muted-foreground hover:text-foreground' }}"
                >
                    <x-icon name="mail" class="h-3.5 w-3.5"/>
                    <span>{{ __('Email Newsletter') }}</span>
                </button>

                <button
                    type="button"
                    wire:click="setType('whatsapp')"
                    class="px-4 py-2 text-xs font-semibold rounded-lg transition-all flex items-center gap-2 {{ $type === 'whatsapp' ? 'bg-emerald-600 text-white shadow-2xs' : 'text-muted-foreground hover:text-foreground' }}"
                >
                    <x-icon name="smartphone" class="h-3.5 w-3.5"/>
                    <span>{{ __('WhatsApp Alerts') }}</span>
                </button>

                <button
                    type="button"
                    wire:click="setType('both')"
                    class="px-4 py-2 text-xs font-semibold rounded-lg transition-all flex items-center gap-2 {{ $type === 'both' ? 'bg-primary text-primary-foreground shadow-2xs' : 'text-muted-foreground hover:text-foreground' }}"
                >
                    <x-icon name="layers" class="h-3.5 w-3.5"/>
                    <span class="hidden sm:inline">{{ __('Both (Email & WhatsApp)') }}</span>
                    <span class="sm:hidden">{{ __('Both') }}</span>
                </button>
            </div>

            {{-- Subscription Form --}}
            <form wire:submit="subscribe" class="space-y-4 text-left">
                {{-- Anti-Spam Honeypot field (hidden from real users) --}}
                <input type="text" wire:model="honeypot" class="sr-only" tabindex="-1" autocomplete="off"/>

                <div class="grid grid-cols-1 {{ $type === 'both' ? 'sm:grid-cols-2' : '' }} gap-3">
                    @if ($type === 'email' || $type === 'both')
                        <div>
                            <label class="text-[11px] font-semibold uppercase tracking-wider text-muted-foreground block mb-1">
                                {{ __('Email Address') }} <span class="text-destructive">*</span>
                            </label>
                            <div class="relative">
                                <x-icon name="mail" class="absolute left-3 top-3 h-4 w-4 text-muted-foreground pointer-events-none"/>
                                <input
                                    type="email"
                                    wire:model="email"
                                    placeholder="your.email@example.com"
                                    class="w-full pl-9 pr-3.5 py-2.5 text-xs bg-background rounded-xl border border-border focus:outline-hidden focus:ring-2 focus:ring-primary/20 focus:border-primary text-foreground placeholder:text-muted-foreground shadow-2xs"
                                />
                            </div>
                            @error('email') <p class="text-xs text-destructive mt-1">{{ $message }}</p> @enderror
                        </div>
                    @endif

                    @if ($type === 'whatsapp' || $type === 'both')
                        <div>
                            <label class="text-[11px] font-semibold uppercase tracking-wider text-muted-foreground block mb-1">
                                {{ __('WhatsApp Number') }} <span class="text-destructive">*</span>
                            </label>
                            <div class="flex gap-2">
                                <select
                                    wire:model="country_code"
                                    class="py-2.5 px-2.5 text-xs bg-background rounded-xl border border-border focus:outline-hidden focus:ring-2 focus:ring-primary/20 text-foreground font-mono w-24 shrink-0 shadow-2xs"
                                >
                                    <option value="+91">+91 (IN)</option>
                                    <option value="+1">+1 (US)</option>
                                    <option value="+44">+44 (UK)</option>
                                    <option value="+971">+971 (UAE)</option>
                                    <option value="+880">+880 (BD)</option>
                                </select>
                                <div class="relative flex-1">
                                    <x-icon name="phone" class="absolute left-3 top-3 h-4 w-4 text-muted-foreground pointer-events-none"/>
                                    <input
                                        type="tel"
                                        wire:model="phone"
                                        placeholder="9876543210"
                                        class="w-full pl-9 pr-3.5 py-2.5 text-xs bg-background rounded-xl border border-border focus:outline-hidden focus:ring-2 focus:ring-primary/20 focus:border-primary text-foreground placeholder:text-muted-foreground font-mono shadow-2xs"
                                    />
                                </div>
                            </div>
                            @error('phone') <p class="text-xs text-destructive mt-1">{{ $message }}</p> @enderror
                        </div>
                    @endif
                </div>

                <div class="flex flex-col sm:flex-row items-center gap-3">
                    <input
                        type="text"
                        wire:model="name"
                        placeholder="{{ __('Your Name (Optional)') }}"
                        class="w-full sm:w-1/2 px-3.5 py-2.5 text-xs bg-background rounded-xl border border-border focus:outline-hidden focus:ring-2 focus:ring-primary/20 text-foreground placeholder:text-muted-foreground shadow-2xs"
                    />

                    <button
                        type="submit"
                        wire:loading.attr="disabled"
                        class="w-full sm:w-1/2 py-2.5 px-6 rounded-xl {{ $type === 'whatsapp' ? 'bg-emerald-600 hover:bg-emerald-700' : 'bg-primary hover:bg-primary/90' }} text-white font-semibold text-xs shadow-md transition-all flex items-center justify-center gap-2 cursor-pointer shrink-0 disabled:opacity-50"
                    >
                        <span wire:loading.remove wire:target="subscribe" class="flex items-center gap-2">
                            <x-icon name="send" class="h-3.5 w-3.5"/>
                            <span>{{ __('Subscribe Now') }}</span>
                        </span>
                        <span wire:loading wire:target="subscribe" class="flex items-center gap-2">
                            <x-icon name="refresh-cw" class="h-3.5 w-3.5 animate-spin"/>
                            <span>{{ __('Subscribing...') }}</span>
                        </span>
                    </button>
                </div>

                @error('subscription') <p class="text-xs text-destructive text-center mt-1">{{ $message }}</p> @enderror

                <p class="text-[11px] text-muted-foreground text-center pt-1">
                    {{ __('Zero spam. You can unsubscribe at any time with a single click.') }}
                </p>
            </form>
        @endif
    </div>
</div>