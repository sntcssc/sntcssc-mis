# Changelog: Fix Meeting Share Modal Alpine Syntax Error on Copy Join Link & Full Invitation

**Date:** 2026-11-10  
**Type:** Bug Fix / Alpine.js & Blade Component Integration  
**Status:** Completed & Verified  

---

## Issue Description

When opening the meeting invitation modal (`openShareModal(id)`) in the Online Meetings hub (`/meetings` handled by [`resources/views/pages/portal/⚡meetings.blade.php`](file:///c:/Users/nilan/Downloads/sntcssc-mis/resources/views/pages/portal/⚡meetings.blade.php)), the browser console produced the following JavaScript syntax errors:

```
Alpine Expression Error: Invalid or unexpected token
Expression: "copyText(@js($joinUrl), true)"
Alpine Expression Error: Invalid or unexpected token
Expression: "copyText(@js($shareText), false)"

Uncaught SyntaxError: Invalid or unexpected token
```

Clicking the **Copy Link** or **Copy Invite** buttons failed because Alpine could not evaluate the `@click` expressions.

---

## Root Cause Analysis

1. **Unparsed Blade Directives in Blade Component Tag Attributes:**
   The copy buttons in the modal were declared using the Blade component `<x-ui.button>`:
   ```blade
   <x-ui.button
       type="button"
       @click="copyText(@js($joinUrl), true)"
       variant="secondary"
       size="sm"
       icon="link"
   >
   ```
   In Laravel Blade, anonymous and class component compilers parse tag attributes (`<x-...>`) into an attribute bag without running directive replacements. Directives such as `@js(...)` are only compiled when evaluated at the template body level, not within `<x-...>` component attribute strings.
   
2. **Alpine JavaScript Syntax Evaluation Failure:**
   Because `@js(...)` was not compiled by Blade, the literal string:
   `copyText(@js($joinUrl), true)`
   was rendered into the HTML DOM.
   When Alpine.js parsed and initialized the component's event listeners, the browser JavaScript runtime encountered `@` as an invalid token inside JavaScript code, throwing `Uncaught SyntaxError: Invalid or unexpected token`.

---

## Solution & Implementation

1. **Component State Encapsulation in Alpine `x-data`:**
   In [`resources/views/pages/portal/⚡meetings.blade.php`](file:///c:/Users/nilan/Downloads/sntcssc-mis/resources/views/pages/portal/⚡meetings.blade.php):
   - Defined `joinUrl: {{ Js::from($joinUrl) }}` and `shareText: {{ Js::from($shareText) }}` directly within the root modal element's `x-data` store using standard Blade echo interpolation `{{ Js::from(...) }}`.
   - Added dedicated Alpine helper methods:
     ```javascript
     copyLink() {
         this.copyText(this.joinUrl, true);
     },
     copyInvite() {
         this.copyText(this.shareText, false);
     },
     ```
2. **Simplified Button Click Directives:**
   Refactored the `<x-ui.button>` components to call the parameterless Alpine methods:
   - Changed `@click="copyText(@js($joinUrl), true)"` to `@click="copyLink()"`.
   - Changed `@click="copyText(@js($shareText), false)"` to `@click="copyInvite()"`.
   This eliminates all embedded Blade directives and string quoting complexity inside component attributes.

---

## Verification & Automated Testing

1. **Automated Feature Test:**
   Added a dedicated test case in [`tests/Feature/OnlineMeetingTest.php`](file:///c:/Users/nilan/Downloads/sntcssc-mis/tests/Feature/OnlineMeetingTest.php):
   - `openShareModal renders valid HTML without unparsed Blade directives in attributes`
   - Verified that the rendered HTML of the Livewire component:
     - Contains the `share-meeting-modal` DOM.
     - Contains clean `@click="copyLink()"` and `@click="copyInvite()"` attributes.
     - Strictly does not contain unparsed `@js(` tokens.
2. **Pest Execution:**
   ```bash
   php artisan test --compact --filter=openShareModal
   ```
   **Result:** PASSED (1 test, 4 assertions).
3. **Pint Code Formatting:**
   ```bash
   vendor/bin/pint tests/Feature/OnlineMeetingTest.php --format agent
   ```
   **Result:** PASSED (All style standards met).

