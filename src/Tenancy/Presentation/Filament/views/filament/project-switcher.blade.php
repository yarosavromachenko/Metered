{{-- The panel's scope control: one select, everything this person can reach. --}}
<div class="flex items-center gap-2 px-2">
    <span class="text-xs font-medium text-gray-500 dark:text-gray-400">Project</span>

    <select
        wire:model.live="projectId"
        aria-label="Project"
        class="rounded-lg border-gray-300 bg-white py-1.5 pe-8 ps-3 text-sm text-gray-900 shadow-sm dark:border-white/10 dark:bg-white/5 dark:text-white"
    >
        @foreach ($options as $id => $label)
            <option value="{{ $id }}">{{ $label }}</option>
        @endforeach
    </select>
</div>
