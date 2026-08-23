<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
    <head>
        @include('partials.head', ['title' => __('Welcome')])
    </head>
    <body class="min-h-full bg-background text-foreground antialiased selection:bg-primary selection:text-primary-foreground flex flex-col justify-between">
        {{-- Top Navigation Bar --}}
        <header class="sticky top-0 z-40 w-full border-b border-border/80 bg-background/80 backdrop-blur-md">
            <div class="max-w-7xl mx-auto flex h-16 sm:h-20 items-center justify-between px-4 sm:px-6 lg:px-8">
                <div class="flex items-center gap-3">
                    <x-app-logo :href="route('home')"/>
                </div>

                {{-- Right Navigation Controls --}}
                <div class="flex items-center gap-2 sm:gap-3">
                    {{-- Language Switcher --}}
                    <x-locale-switcher/>

                    {{-- Theme Switcher (Light / Dark / System) --}}
                    <x-ui.theme-switch/>

                    {{-- Authentication Actions --}}
                    <div class="flex items-center gap-2 pl-1 sm:pl-2 border-l border-border/60">
                        @if (Route::has('login'))
                            @auth
                                <a
                                    href="{{ route('dashboard') }}"
                                    class="inline-flex items-center gap-2 rounded-lg bg-primary px-3.5 sm:px-4 py-2 text-xs sm:text-sm font-semibold text-primary-foreground shadow-xs hover:bg-primary/90 transition-all cursor-pointer"
                                >
                                    <x-icon name="layout-dashboard" class="h-4 w-4"/>
                                    <span class="hidden xs:inline">{{ __('Dashboard') }}</span>
                                </a>
                            @else
                                <a
                                    href="{{ route('login') }}"
                                    class="inline-flex items-center gap-1.5 rounded-lg border border-border bg-card px-3 sm:px-3.5 py-2 text-xs sm:text-sm font-medium text-foreground hover:bg-secondary transition-colors cursor-pointer shadow-2xs"
                                >
                                    <x-icon name="log-in" class="h-3.5 w-3.5 text-muted-foreground"/>
                                    <span>{{ __('Log in') }}</span>
                                </a>

                                @if (Route::has('register'))
                                    <a
                                        href="{{ route('register') }}"
                                        class="inline-flex items-center gap-1.5 rounded-lg bg-primary px-3.5 sm:px-4 py-2 text-xs sm:text-sm font-semibold text-primary-foreground shadow-xs hover:bg-primary/90 transition-colors cursor-pointer"
                                    >
                                        <span>{{ __('Register') }}</span>
                                        <x-icon name="arrow-right" class="h-3.5 w-3.5"/>
                                    </a>
                                @endif
                            @endauth
                        @endif
                    </div>
                </div>
            </div>
        </header>

        {{-- Main Landing Content --}}
        <main class="flex-1">
            {{-- Hero Section --}}
            <section class="relative overflow-hidden pt-12 pb-16 sm:pt-20 sm:pb-24 lg:pt-28 lg:pb-32">
                {{-- Ambient Background Glows driven by dynamic primary theme color --}}
                <div class="absolute -top-24 left-1/2 -z-10 h-[450px] w-[550px] -translate-x-1/2 rounded-full bg-primary/10 blur-3xl pointer-events-none"></div>
                <div class="absolute top-1/3 -left-28 -z-10 h-80 w-80 rounded-full bg-primary/10 blur-3xl pointer-events-none"></div>
                <div class="absolute top-1/2 -right-28 -z-10 h-80 w-80 rounded-full bg-primary/10 blur-3xl pointer-events-none"></div>

                <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 text-center">
                    {{-- System Badge Pill --}}
                    <div class="inline-flex items-center gap-2 rounded-full border border-primary/25 bg-primary/10 px-4 py-1.5 text-xs sm:text-sm font-semibold text-primary mb-6 shadow-2xs">
                        <span class="flex h-2 w-2 rounded-full bg-primary animate-pulse"></span>
                        <span>{{ __('Management Information System') }}</span>
                    </div>

                    {{-- Main Headline --}}
                    <h1 class="text-3xl sm:text-5xl lg:text-6xl font-extrabold tracking-tight text-foreground max-w-4xl mx-auto leading-tight sm:leading-tight">
                        {{ \App\Models\Setting::siteName() }}
                    </h1>

                    <p class="mt-5 text-base sm:text-xl text-muted-foreground max-w-3xl mx-auto leading-relaxed">
                        {{ \App\Models\Setting::get('general.site_tagline') ?: __('Integrated Management Information System for Civil Services Aspirants & Administration.') }}
                    </p>

                    <p class="mt-2 text-sm sm:text-base text-muted-foreground/80 max-w-2xl mx-auto">
                        {{ __('Streamlining student admissions, batch scheduling, test series evaluation, performance analytics, and institutional governance in one unified platform.') }}
                    </p>

                    {{-- Call to Actions --}}
                    <div class="mt-8 sm:mt-10 flex flex-wrap items-center justify-center gap-3 sm:gap-4">
                        @auth
                            <a
                                href="{{ route('dashboard') }}"
                                class="inline-flex items-center gap-2 rounded-xl bg-primary px-6 py-3.5 text-sm sm:text-base font-semibold text-primary-foreground shadow-md hover:bg-primary/90 transition-all cursor-pointer"
                            >
                                <x-icon name="layout-dashboard" class="h-5 w-5"/>
                                <span>{{ __('Open MIS Dashboard') }}</span>
                            </a>
                        @else
                            <a
                                href="{{ route('login') }}"
                                class="inline-flex items-center gap-2 rounded-xl bg-primary px-6 py-3.5 text-sm sm:text-base font-semibold text-primary-foreground shadow-md hover:bg-primary/90 transition-all cursor-pointer"
                            >
                                <x-icon name="shield-check" class="h-5 w-5"/>
                                <span>{{ __('Sign in to Portal') }}</span>
                            </a>

                            @if (Route::has('register'))
                                <a
                                    href="{{ route('register') }}"
                                    class="inline-flex items-center gap-2 rounded-xl border border-border bg-card px-6 py-3.5 text-sm sm:text-base font-medium text-foreground hover:bg-secondary transition-colors cursor-pointer shadow-2xs"
                                >
                                    <x-icon name="user-plus" class="h-5 w-5 text-muted-foreground"/>
                                    <span>{{ __('Create Student Account') }}</span>
                                </a>
                            @endif
                        @endauth
                    </div>

                    {{-- Quick Stats Row --}}
                    <div class="mt-14 sm:mt-20 grid grid-cols-2 md:grid-cols-4 gap-3 sm:gap-4 max-w-5xl mx-auto">
                        <div class="rounded-2xl border border-border bg-card/70 p-5 text-center shadow-2xs backdrop-blur-xs">
                            <p class="text-2xl sm:text-3xl font-extrabold tracking-tight text-primary">100%</p>
                            <p class="text-xs sm:text-sm font-medium text-muted-foreground mt-1">{{ __('Digital Admissions') }}</p>
                        </div>
                        <div class="rounded-2xl border border-border bg-card/70 p-5 text-center shadow-2xs backdrop-blur-xs">
                            <p class="text-2xl sm:text-3xl font-extrabold tracking-tight text-primary">Multi-Branch</p>
                            <p class="text-xs sm:text-sm font-medium text-muted-foreground mt-1">{{ __('Campus Governance') }}</p>
                        </div>
                        <div class="rounded-2xl border border-border bg-card/70 p-5 text-center shadow-2xs backdrop-blur-xs">
                            <p class="text-2xl sm:text-3xl font-extrabold tracking-tight text-primary">Automated</p>
                            <p class="text-xs sm:text-sm font-medium text-muted-foreground mt-1">{{ __('Test Scorecards & Ranks') }}</p>
                        </div>
                        <div class="rounded-2xl border border-border bg-card/70 p-5 text-center shadow-2xs backdrop-blur-xs">
                            <p class="text-2xl sm:text-3xl font-extrabold tracking-tight text-primary">Enterprise</p>
                            <p class="text-xs sm:text-sm font-medium text-muted-foreground mt-1">{{ __('Security & Audit Logs') }}</p>
                        </div>
                    </div>
                </div>
            </section>

            {{-- Core Features Grid --}}
            <section class="border-t border-border bg-card/30 py-16 sm:py-24 lg:py-28">
                <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
                    <div class="text-center max-w-3xl mx-auto mb-12 sm:mb-16">
                        <span class="inline-flex items-center gap-1.5 rounded-md bg-primary/10 px-3 py-1 text-xs font-semibold text-primary uppercase tracking-wider">
                            {{ __('System Architecture') }}
                        </span>
                        <h2 class="mt-3 text-2xl sm:text-4xl font-extrabold tracking-tight text-foreground">
                            {{ __('Core Capabilities of the Academic Portal') }}
                        </h2>
                        <p class="mt-3 text-sm sm:text-base text-muted-foreground">
                            {{ __('Designed specifically for UPSC / Civil Services preparation institutes with end-to-end administration workflows.') }}
                        </p>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-5 sm:gap-6">
                        {{-- Module 1 --}}
                        <div class="rounded-2xl border border-border bg-card p-6 sm:p-7 shadow-xs hover:border-primary/40 hover:shadow-md transition-all">
                            <div class="flex h-12 w-12 items-center justify-center rounded-xl bg-primary/10 text-primary mb-5 shadow-2xs">
                                <x-icon name="users" class="h-6 w-6"/>
                            </div>
                            <h3 class="text-base sm:text-lg font-bold text-foreground">{{ __('Student Lifecycle & Admissions') }}</h3>
                            <p class="mt-2 text-xs sm:text-sm text-muted-foreground leading-relaxed">
                                {{ __('Complete candidate profile registry, document verification, dynamic registration numbers, and batch enrollment records.') }}
                            </p>
                        </div>

                        {{-- Module 2 --}}
                        <div class="rounded-2xl border border-border bg-card p-6 sm:p-7 shadow-xs hover:border-primary/40 hover:shadow-md transition-all">
                            <div class="flex h-12 w-12 items-center justify-center rounded-xl bg-primary/10 text-primary mb-5 shadow-2xs">
                                <x-icon name="calendar" class="h-6 w-6"/>
                            </div>
                            <h3 class="text-base sm:text-lg font-bold text-foreground">{{ __('Batches & Academic Schedules') }}</h3>
                            <p class="mt-2 text-xs sm:text-sm text-muted-foreground leading-relaxed">
                                {{ __('Timetable management, subject allocation, daily faculty lectures, attendance logs, and study material distribution.') }}
                            </p>
                        </div>

                        {{-- Module 3 --}}
                        <div class="rounded-2xl border border-border bg-card p-6 sm:p-7 shadow-xs hover:border-primary/40 hover:shadow-md transition-all">
                            <div class="flex h-12 w-12 items-center justify-center rounded-xl bg-primary/10 text-primary mb-5 shadow-2xs">
                                <x-icon name="file-check-2" class="h-6 w-6"/>
                            </div>
                            <h3 class="text-base sm:text-lg font-bold text-foreground">{{ __('Test Series & Evaluation') }}</h3>
                            <p class="mt-2 text-xs sm:text-sm text-muted-foreground leading-relaxed">
                                {{ __('Prelims objective mock tests, Mains answer evaluations, detailed student scorecards, and rank percentile calculations.') }}
                            </p>
                        </div>

                        {{-- Module 4 --}}
                        <div class="rounded-2xl border border-border bg-card p-6 sm:p-7 shadow-xs hover:border-primary/40 hover:shadow-md transition-all">
                            <div class="flex h-12 w-12 items-center justify-center rounded-xl bg-primary/10 text-primary mb-5 shadow-2xs">
                                <x-icon name="credit-card" class="h-6 w-6"/>
                            </div>
                            <h3 class="text-base sm:text-lg font-bold text-foreground">{{ __('Fee & Financial Ledger') }}</h3>
                            <p class="mt-2 text-xs sm:text-sm text-muted-foreground leading-relaxed">
                                {{ __('Structured installment plans, instant receipt generation, payment gateway reconciliation, and financial reports.') }}
                            </p>
                        </div>

                        {{-- Module 5 --}}
                        <div class="rounded-2xl border border-border bg-card p-6 sm:p-7 shadow-xs hover:border-primary/40 hover:shadow-md transition-all">
                            <div class="flex h-12 w-12 items-center justify-center rounded-xl bg-primary/10 text-primary mb-5 shadow-2xs">
                                <x-icon name="shield" class="h-6 w-6"/>
                            </div>
                            <h3 class="text-base sm:text-lg font-bold text-foreground">{{ __('Security, Passkeys & 2FA') }}</h3>
                            <p class="mt-2 text-xs sm:text-sm text-muted-foreground leading-relaxed">
                                {{ __('Two-factor authentication, biometric passkeys, granular team roles, and immutable audit trails for complete compliance.') }}
                            </p>
                        </div>

                        {{-- Module 6 --}}
                        <div class="rounded-2xl border border-border bg-card p-6 sm:p-7 shadow-xs hover:border-primary/40 hover:shadow-md transition-all">
                            <div class="flex h-12 w-12 items-center justify-center rounded-xl bg-primary/10 text-primary mb-5 shadow-2xs">
                                <x-icon name="sliders" class="h-6 w-6"/>
                            </div>
                            <h3 class="text-base sm:text-lg font-bold text-foreground">{{ __('Dynamic System Presets') }}</h3>
                            <p class="mt-2 text-xs sm:text-sm text-muted-foreground leading-relaxed">
                                {{ __('Curated theme presets, primary color variations, multi-language localization, and centralized branding controls.') }}
                            </p>
                        </div>
                    </div>
                </div>
            </section>
        </main>

        {{-- Modern Footer --}}
        <footer class="border-t border-border bg-card/60 py-8 sm:py-10 text-xs text-muted-foreground">
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 flex flex-col sm:flex-row items-center justify-between gap-4">
                <div class="flex items-center gap-2.5">
                    <x-app-logo :hideText="true"/>
                    <span class="font-semibold text-sm text-foreground">{{ \App\Models\Setting::appName() }}</span>
                </div>

                <p class="text-center">{{ \App\Models\Setting::copyrightText() }}</p>

                <div class="flex items-center gap-4">
                    <a href="{{ route('home') }}" class="hover:text-foreground transition-colors">{{ __('Home') }}</a>
                    <a href="{{ route('login') }}" class="hover:text-foreground transition-colors">{{ __('Portal Login') }}</a>
                </div>
            </div>
        </footer>

        @livewireScripts
    </body>
</html>
