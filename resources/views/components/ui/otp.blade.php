@props([
    'length' => 6,
    'name' => 'code',
    'model' => null,
])

<div
    x-data="{
        digits: Array.from({ length: {{ $length }} }, () => ''),

        boxes() {
            return this.$root.querySelectorAll('[data-otp-box]');
        },

        box(index) {
            return this.boxes()[index];
        },

        syncHidden() {
            this.$root.querySelector('[data-otp-hidden]').value = this.digits.join('');
        },

        onInput(index) {
            const value = this.digits[index].replace(/[^0-9]/g, '').slice(-1);
            this.digits[index] = value;
            this.syncHidden();

            if (value && index < {{ $length }} - 1) {
                this.box(index + 1)?.focus();
            }
        },

        onKeydown(index, event) {
            if (event.key === 'Backspace' && ! this.digits[index] && index > 0) {
                this.box(index - 1)?.focus();
            }

            if (event.key === 'ArrowLeft' && index > 0) {
                this.box(index - 1)?.focus();
            }

            if (event.key === 'ArrowRight' && index < {{ $length }} - 1) {
                this.box(index + 1)?.focus();
            }
        },

        onPaste(event, index) {
            event.preventDefault();
            const pasted = (event.clipboardData.getData('text') || '').replace(/[^0-9]/g, '').slice(0, {{ $length }} - index).split('');

            pasted.forEach((char, offset) => {
                this.digits[index + offset] = char;
            });

            this.syncHidden();
            this.box(Math.min(index + pasted.length, {{ $length }} - 1))?.focus();
        },

        init() {
            this.box(0)?.focus();
        },
    }"
    {{ $attributes }}
>
    <div class="flex items-center justify-center gap-2">
        <template x-for="(digit, index) in digits" :key="index">
            <input
                type="text"
                inputmode="numeric"
                autocomplete="one-time-code"
                maxlength="1"
                data-otp-box
                x-model="digits[index]"
                x-on:input="onInput(index)"
                x-on:keydown="onKeydown(index, $event)"
                x-on:paste="onPaste($event, index)"
                :class="digit ? 'border-primary/50' : 'border-input'"
                class="h-13 w-11 sm:h-14 sm:w-13 text-center text-xl font-bold rounded-xl border-2 bg-secondary/30 outline-none transition-colors focus:border-primary focus:ring-2 focus:ring-primary/20"
            />
        </template>
    </div>

    <input
        type="hidden"
        name="{{ $name }}"
        data-otp-hidden
        value=""
        @if ($model) wire:model="{{ $model }}" @endif
    />
</div>
