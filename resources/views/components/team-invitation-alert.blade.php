@props([
    'invitation',
    'action',
])

<div data-test="team-invitation-alert">
    <div class="flex gap-3 rounded-lg border border-cyan-500/20 bg-cyan-500/5 px-4 py-3 text-sm text-cyan-700 dark:text-cyan-300">
        <x-icon name="info" class="mt-0.5 h-4 w-4 shrink-0 text-cyan-500"/>

        <div>
            {{ __(':action to join the ":team" team.', ['action' => $action, 'team' => $invitation['teamName']]) }}
        </div>
    </div>
</div>
