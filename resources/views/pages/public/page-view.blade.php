<?php

use App\Models\Page;
use App\Models\Setting;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Request;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts.public')] #[Title('Page')] class extends Component {
    public string $slug = '';

    public function mount(?string $slug = null): void
    {
        $this->slug = $slug ?: (string) (Request::route('slug') ?: Request::segment(1));

        // Strip any 'pages/' prefix if present
        $this->slug = str_replace('pages/', '', $this->slug);

        // Verify page exists and is published
        $page = $this->page;

        if (! $page) {
            abort(404, __('Page not found or is currently unpublished.'));
        }

        // Increment view count quietly
        try {
            $page->timestamps = false;
            $page->increment('view_count');
            $page->timestamps = true;
        } catch (\Throwable) {
            // Ignore view count failure
        }
    }

    #[Computed]
    public function page(): ?Page
    {
        return Page::with('translations')
            ->published()
            ->where('slug', $this->slug)
            ->first();
    }

    #[Computed]
    public function allPages()
    {
        return Page::with('translations')
            ->published()
            ->ordered()
            ->get();
    }
}; ?>

<div class="min-h-screen bg-background text-foreground antialiased selection:bg-primary selection:text-primary-foreground flex flex-col justify-between">
    @php
        $currentPage = $this->page;
        $currentLocale = app()->getLocale();
        $translation = $currentPage ? $currentPage->translation($currentLocale) : null;
        $pageTitle = $translation?->meta_title ?: ($translation?->title ?: ucfirst(str_replace('-', ' ', $slug)));
        $pageDescription = $translation?->meta_description ?: Setting::get('seo.meta_description', '');
        $pageKeywords = $translation?->meta_keywords ?: Setting::get('seo.meta_keywords', '');
    @endphp

    {{-- SEO Meta & Head Tags Injection --}}
    @include('partials.head', [
        'title' => $pageTitle,
        'description' => $pageDescription,
        'keywords' => $pageKeywords,
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
                    <a href="{{ route('public.contact') }}" class="hover:text-foreground transition-colors">{{ __('Contact Us') }}</a>
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

    {{-- Main Page Content --}}
    <main class="flex-1 py-10 sm:py-16">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            {{-- Breadcrumbs & Top Meta --}}
            <div class="flex flex-wrap items-center justify-between gap-3 text-xs sm:text-sm text-muted-foreground mb-8">
                <nav class="flex items-center gap-2">
                    <a href="{{ route('home') }}" class="hover:text-foreground transition-colors">{{ __('Home') }}</a>
                    <span>/</span>
                    <span class="text-foreground font-medium truncate max-w-[200px] sm:max-w-xs">{{ $this->page?->title }}</span>
                </nav>

                <div class="flex items-center gap-3">
                    <span class="inline-flex items-center gap-1.5 rounded-full bg-primary/10 px-2.5 py-1 text-xs font-semibold text-primary">
                        <x-icon name="clock" class="h-3.5 w-3.5"/>
                        <span>{{ $this->page?->reading_time ?? 1 }} {{ __('min read') }}</span>
                    </span>
                    <span class="text-xs text-muted-foreground">
                        {{ __('Updated') }}: {{ $this->page?->updated_at?->format('d M Y') }}
                    </span>
                </div>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-12 gap-8 lg:gap-12">
                {{-- Article Main Column --}}
                <article class="lg:col-span-8 bg-card border border-border rounded-2xl p-6 sm:p-10 shadow-xs">
                    <header class="border-b border-border/80 pb-6 mb-8">
                        <h1 class="text-2xl sm:text-4xl font-extrabold tracking-tight text-foreground leading-tight">
                            {{ $this->page?->title }}
                        </h1>
                        @if ($this->page?->meta_description)
                            <p class="mt-3 text-sm sm:text-base text-muted-foreground leading-relaxed">
                                {{ $this->page->meta_description }}
                            </p>
                        @endif
                    </header>

                    {{-- Rich HTML Content with Typography Styling --}}
                    <div class="prose dark:prose-invert max-w-none text-foreground leading-relaxed space-y-4 [&>h2]:text-xl [&>h2]:font-bold [&>h2]:tracking-tight [&>h2]:mt-8 [&>h2]:mb-3 [&>h2]:text-foreground [&>h3]:text-lg [&>h3]:font-semibold [&>h3]:mt-6 [&>h3]:mb-2 [&>h3]:text-foreground [&>p]:text-sm [&>p]:sm:text-base [&>p]:text-muted-foreground [&>p]:leading-relaxed [&>ul]:list-disc [&>ul]:pl-5 [&>ul]:space-y-1.5 [&>ul]:text-sm [&>ul]:sm:text-base [&>ul]:text-muted-foreground [&>ol]:list-decimal [&>ol]:pl-5 [&>ol]:space-y-1.5 [&>ol]:text-sm [&>ol]:sm:text-base [&>ol]:text-muted-foreground [&>table]:w-full [&>table]:text-left [&>table]:text-xs [&>table]:sm:text-sm [&>table]:border-collapse [&>table]:my-6 [&>table_th]:bg-muted [&>table_th]:p-3 [&>table_th]:font-semibold [&>table_th]:border [&>table_th]:border-border [&>table_td]:p-3 [&>table_td]:border [&>table_td]:border-border [&>blockquote]:border-l-4 [&>blockquote]:border-primary [&>blockquote]:pl-4 [&>blockquote]:italic [&>blockquote]:text-muted-foreground [&>code]:bg-muted [&>code]:px-1.5 [&>code]:py-0.5 [&>code]:rounded-md [&>code]:text-xs [&>code]:font-mono">
                        {!! $this->page?->content !!}
                    </div>

                    {{-- Footer of Article / Share & Feedback --}}
                    <footer class="mt-12 pt-6 border-t border-border flex flex-wrap items-center justify-between gap-4">
                        <div class="flex items-center gap-2 text-xs text-muted-foreground">
                            <x-icon name="shield-check" class="h-4 w-4 text-primary"/>
                            <span>{{ __('Official verified policy of :app', ['app' => Setting::siteName()]) }}</span>
                        </div>

                        <a
                            href="{{ route('public.contact') }}"
                            class="inline-flex items-center gap-1.5 text-xs font-semibold text-primary hover:underline cursor-pointer"
                        >
                            <x-icon name="message-square" class="h-3.5 w-3.5"/>
                            <span>{{ __('Have questions? Contact our desk') }} &rarr;</span>
                        </a>
                    </footer>
                </article>

                {{-- Sidebar Navigation Column --}}
                <aside class="lg:col-span-4 space-y-6">
                    {{-- Policy Pages Navigation Card --}}
                    <div class="bg-card border border-border rounded-2xl p-5 sm:p-6 shadow-2xs">
                        <h3 class="text-sm font-bold uppercase tracking-wider text-muted-foreground mb-4 flex items-center gap-2">
                            <x-icon name="file-text" class="h-4 w-4 text-primary"/>
                            <span>{{ __('Institutional Policies') }}</span>
                        </h3>

                        <ul class="space-y-1.5">
                            @foreach ($this->allPages as $item)
                                <li>
                                    <a
                                        href="{{ route('public.page', $item->slug) }}"
                                        class="flex items-center justify-between rounded-lg px-3 py-2.5 text-xs sm:text-sm font-medium transition-all {{ $item->slug === $this->slug ? 'bg-primary/10 text-primary font-semibold border-l-2 border-primary' : 'text-muted-foreground hover:bg-secondary hover:text-foreground' }}"
                                    >
                                        <span class="truncate">{{ $item->title }}</span>
                                        <x-icon name="chevron-right" class="h-3.5 w-3.5 shrink-0 opacity-60"/>
                                    </a>
                                </li>
                            @endforeach
                        </ul>
                    </div>

                    {{-- Quick Help / Contact Assistance Card --}}
                    <div class="bg-gradient-to-br from-primary/10 via-card to-card border border-primary/20 rounded-2xl p-5 sm:p-6 shadow-2xs">
                        <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-primary/20 text-primary mb-3">
                            <x-icon name="phone" class="h-5 w-5"/>
                        </div>
                        <h4 class="text-sm font-bold text-foreground">{{ __('Need Assistance or Clarifications?') }}</h4>
                        <p class="mt-1.5 text-xs text-muted-foreground leading-relaxed">
                            {{ __('Our admissions and academic helpdesk is available during office hours to answer your queries.') }}
                        </p>
                        <div class="mt-4 pt-3 border-t border-border/60 space-y-2 text-xs">
                            <p class="flex items-center gap-2 text-foreground font-medium">
                                <x-icon name="mail" class="h-3.5 w-3.5 text-primary shrink-0"/>
                                <span>{{ Setting::get('general.site_email', 'info@sntcssc.in') }}</span>
                            </p>
                            <p class="flex items-center gap-2 text-foreground font-medium">
                                <x-icon name="phone" class="h-3.5 w-3.5 text-primary shrink-0"/>
                                <span>{{ Setting::get('general.site_mobile', '+91 90000 00000') }}</span>
                            </p>
                        </div>
                        <div class="mt-4">
                            <a
                                href="{{ route('public.contact') }}"
                                class="w-full inline-flex items-center justify-center gap-2 rounded-lg bg-primary px-3.5 py-2 text-xs font-semibold text-primary-foreground shadow-xs hover:bg-primary/90 transition-all cursor-pointer"
                            >
                                <x-icon name="send" class="h-3.5 w-3.5"/>
                                <span>{{ __('Open Contact Form') }}</span>
                            </a>
                        </div>
                    </div>
                </aside>
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
                <a href="{{ route('public.contact') }}" class="hover:text-foreground transition-colors">{{ __('Contact Us') }}</a>
                <a href="{{ route('login') }}" class="hover:text-foreground transition-colors">{{ __('Portal Login') }}</a>
            </div>
        </div>
    </footer>
</div>
