<x-filament-panels::page>
    <div class="space-y-6">
        {{-- Header with legend --}}
        <div class="rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
            <div class="flex flex-wrap items-center gap-4">
                <span class="text-sm font-medium text-gray-700 dark:text-gray-300">Legend:</span>
                <div class="flex items-center gap-2">
                    <div class="h-4 w-4 rounded bg-emerald-500 flex items-center justify-center">
                        <x-heroicon-s-check class="h-3 w-3 text-white" />
                    </div>
                    <span class="text-sm text-gray-600 dark:text-gray-400">Has Permission</span>
                </div>
                <div class="flex items-center gap-2">
                    <div class="h-4 w-4 rounded bg-gray-200 dark:bg-gray-700 flex items-center justify-center">
                        <x-heroicon-o-minus class="h-3 w-3 text-gray-400 dark:text-gray-500" />
                    </div>
                    <span class="text-sm text-gray-600 dark:text-gray-400">No Permission</span>
                </div>
                <span class="text-sm text-gray-500 dark:text-gray-400 ml-4 italic">Click any cell to toggle</span>
            </div>
        </div>

        {{-- Matrix Table --}}
        <div class="overflow-x-auto rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
            <table class="w-full divide-y divide-gray-200 dark:divide-gray-700">
                <thead>
                    <tr class="bg-gray-50 dark:bg-gray-800">
                        <th class="fi-ta-header-cell px-4 py-3 text-left" style="min-width: 200px;">
                            <span class="text-xs font-semibold uppercase tracking-wider text-gray-600 dark:text-gray-400">Permission</span>
                        </th>
                        @foreach($roles as $roleId => $roleName)
                            <th class="fi-ta-header-cell px-3 py-3 text-center" style="min-width: 100px;">
                                <span class="inline-flex items-center rounded-full px-2 py-1 text-xs font-medium
                                    @if($roleName === 'studio_user') bg-emerald-100 text-emerald-700 dark:bg-emerald-900 dark:text-emerald-300
                                    @elseif($roleName === 'client_user') bg-sky-100 text-sky-700 dark:bg-sky-900 dark:text-sky-300
                                    @elseif($roleName === 'guest_user') bg-amber-100 text-amber-700 dark:bg-amber-900 dark:text-amber-300
                                    @elseif($roleName === 'vendor_user') bg-gray-100 text-gray-700 dark:bg-gray-700 dark:text-gray-300
                                    @else bg-purple-100 text-purple-700 dark:bg-purple-900 dark:text-purple-300
                                    @endif
                                ">
                                    {{ str_replace('_', ' ', $roleName) }}
                                </span>
                            </th>
                        @endforeach
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                    @foreach($categories as $category => $categoryPermissions)
                        {{-- Category Header Row --}}
                        <tr
                            x-data="{ open: true }"
                            @click="open = ! open"
                            class="bg-gray-100 dark:bg-gray-800/50 cursor-pointer"
                        >
                            <td colspan="{{ count($roles) + 1 }}" class="px-4 py-2">
                                <div class="flex items-center justify-between">
                                    <div class="flex items-center gap-2">
                                        <x-heroicon-o-chevron-right
                                            class="h-4 w-4 text-gray-500 transition-all duration-200"
                                            x-bind:class="{ 'rotate-90': open }"
                                        />
                                        <span class="text-sm font-semibold text-gray-700 dark:text-gray-300">
                                            {{ $category }}
                                            <span class="ml-2 text-xs font-normal text-gray-500">({{ count($categoryPermissions) }} permissions)</span>
                                        </span>
                                    </div>
                                    <div class="flex gap-2" @click.stop>
                                        @foreach($roles as $roleId => $roleName)
                                            <div class="flex flex-col items-center" style="width: 100px; min-width: 100px;">
                                                <div class="flex gap-1">
                                                    <button
                                                        wire:click="grantAllInCategory({{ $roleId }}, '{{ $category }}')"
                                                        wire:loading.attr="disabled"
                                                        class="text-[10px] font-bold text-emerald-600 hover:text-emerald-700 dark:text-emerald-400"
                                                        title="Grant all in {{ $category }} for {{ $roleName }}"
                                                    >
                                                        ALL
                                                    </button>
                                                    <span class="text-[10px] text-gray-300">|</span>
                                                    <button
                                                        wire:click="revokeAllInCategory({{ $roleId }}, '{{ $category }}')"
                                                        wire:loading.attr="disabled"
                                                        class="text-[10px] font-bold text-rose-600 hover:text-rose-700 dark:text-rose-400"
                                                        title="Revoke all in {{ $category }} for {{ $roleName }}"
                                                    >
                                                        NONE
                                                    </button>
                                                </div>
                                            </div>
                                        @endforeach
                                    </div>
                                </div>
                            </td>
                        </tr>
                        <tbody x-show="open" x-collapse>

                        {{-- Permission Rows --}}
                        @foreach($categoryPermissions as $permission)
                            <tr class="hover:bg-gray-50 dark:hover:bg-gray-800/30 transition-colors">
                                <td class="px-4 py-2 text-sm">
                                    <div class="flex flex-col">
                                        <span class="font-medium text-gray-900 dark:text-gray-100">{{ $permission['description'] }}</span>
                                        <span class="text-xs text-gray-500 dark:text-gray-500 font-mono">{{ $permission['name'] }}</span>
                                    </div>
                                </td>
                                @foreach($roles as $roleId => $roleName)
                                    <td class="px-3 py-2 text-center" style="width: 100px; min-width: 100px;">
                                        <button
                                            wire:click="togglePermission({{ $roleId }}, {{ $permission['id'] }})"
                                            wire:loading.attr="disabled"
                                            wire:loading.class="opacity-50 cursor-wait"
                                            class="inline-flex items-center justify-center h-8 w-8 rounded-lg transition-all duration-150 ease-in-out {{ in_array($permission['id'], $matrix[$roleId] ?? [])
                                                    ? 'bg-emerald-500 hover:bg-emerald-600 text-white shadow-sm'
                                                    : 'bg-gray-200 hover:bg-gray-300 dark:bg-gray-700 dark:hover:bg-gray-600 text-gray-400 dark:text-gray-500'
                                                }}"
                                            title="{{ in_array($permission['id'], $matrix[$roleId] ?? []) ? 'Click to revoke' : 'Click to grant' }}"
                                        >
                                            @if(in_array($permission['id'], $matrix[$roleId] ?? []))
                                                <x-heroicon-s-check class="h-5 w-5" />
                                            @else
                                                <x-heroicon-o-minus class="h-4 w-4" />
                                            @endif
                                        </button>
                                    </td>
                                @endforeach
                            </tr>
                        @endforeach
                        </tbody>
                    @endforeach
                </tbody>
            </table>
        </div>

        {{-- Summary Stats --}}
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
            @foreach($roles as $roleId => $roleName)
                @php
                    $permCount = count($matrix[$roleId] ?? []);
                    $totalPerms = array_sum(array_map('count', $categories));
                    $percentage = $totalPerms > 0 ? round(($permCount / $totalPerms) * 100) : 0;
                @endphp
                <div class="rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-sm font-medium text-gray-500 dark:text-gray-400">{{ str_replace('_', ' ', ucwords($roleName)) }}</p>
                            <p class="text-2xl font-semibold text-gray-900 dark:text-white">{{ $permCount }}</p>
                            <p class="text-xs text-gray-500">of {{ $totalPerms }} permissions</p>
                        </div>
                        <div class="h-12 w-12 rounded-full flex items-center justify-center
                            @if($roleName === 'studio_user') bg-emerald-100 text-emerald-600 dark:bg-emerald-900 dark:text-emerald-400
                            @elseif($roleName === 'client_user') bg-sky-100 text-sky-600 dark:bg-sky-900 dark:text-sky-400
                            @elseif($roleName === 'guest_user') bg-amber-100 text-amber-600 dark:bg-amber-900 dark:text-amber-400
                            @else bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-400
                            @endif
                        ">
                            <span class="text-sm font-bold">{{ $percentage }}%</span>
                        </div>
                    </div>
                    <div class="mt-3 h-2 w-full rounded-full bg-gray-200 dark:bg-gray-700">
                        <div
                            class="h-2 rounded-full transition-all duration-300
                                @if($roleName === 'studio_user') bg-emerald-500
                                @elseif($roleName === 'client_user') bg-sky-500
                                @elseif($roleName === 'guest_user') bg-amber-500
                                @else bg-gray-500
                                @endif
                            "
                            style="width: {{ $percentage }}%"
                        ></div>
                    </div>
                </div>
            @endforeach
        </div>
    </div>

    @push('scripts')
        <script>
            document.addEventListener('permission-toggled', () => {
                new FilamentNotification()
                    .title('Permissions updated')
                    .success()
                    .send()
            });
        </script>
    @endpush
</x-filament-panels::page>