@php
    // $monitorScreen (nullable), $accessPoints (all active points), $selectedIds (ordered ids
    // already on this screen, empty on create) are passed in by create.blade.php/edit.blade.php.
    $pointsById = $accessPoints->keyBy('id');
@endphp

<div class="bg-white rounded-lg shadow border border-gray-200 p-5">
    <div class="mb-5">
        <label for="name" class="block text-sm font-medium text-gray-700 mb-1">Название экрана</label>
        <input type="text" name="name" id="name" required maxlength="255"
            value="{{ old('name', $monitorScreen->name ?? '') }}"
            placeholder="Например: Вход в офис"
            class="w-full rounded-lg border-gray-300 text-sm focus:border-indigo-500 focus:ring-indigo-500">
        @error('name')
            <p class="text-xs text-red-600 mt-1">{{ $message }}</p>
        @enderror
    </div>

    <div class="mb-2">
        <span class="block text-sm font-medium text-gray-700 mb-1">Турникеты слева направо</span>
        <p class="text-xs text-gray-400 mb-3">Кликните по точке слева, чтобы добавить справа. Стрелками можно поменять порядок блоков на экране.</p>
    </div>

    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
        <div>
            <p class="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-2">Доступные точки</p>
            <ul id="availablePoints" class="border border-gray-200 rounded-lg divide-y divide-gray-100 max-h-80 overflow-y-auto">
                @foreach($accessPoints as $ap)
                    <li data-id="{{ $ap->id }}" data-name="{{ $ap->name }}" class="available-point-item px-3 py-2 text-sm text-gray-700 hover:bg-indigo-50 cursor-pointer flex items-center justify-between">
                        <span>{{ $ap->name }}</span>
                        <span class="text-indigo-500 text-xs">Добавить →</span>
                    </li>
                @endforeach
            </ul>
        </div>

        <div>
            <p class="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-2">Выбрано</p>
            <ul id="selectedPoints" class="border border-gray-200 rounded-lg divide-y divide-gray-100 min-h-[3rem]">
                {{-- filled by JS from the initial selection + on every add/remove/reorder --}}
            </ul>
            <p id="selectedEmptyHint" class="text-xs text-gray-400 mt-2">Пока ничего не выбрано.</p>
        </div>
    </div>
    @error('access_point_ids')
        <p class="text-xs text-red-600 mt-2">{{ $message }}</p>
    @enderror

    <div id="hiddenInputs"></div>
</div>

<script>
    (function () {
        const pointsById = @json($pointsById->map(fn ($ap) => ['id' => $ap->id, 'name' => $ap->name]));
        let selected = @json(array_values(old('access_point_ids', $selectedIds)));
        selected = selected.map((id) => parseInt(id, 10)).filter((id) => pointsById[id]);

        // Access point names come from RusGuard, not from this form — never trust them enough
        // to interpolate raw into innerHTML.
        const escapeHtml = (s) => String(s).replace(/[&<>"']/g, (c) => ({
            '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
        }[c]));

        const availableList = document.getElementById('availablePoints');
        const selectedList = document.getElementById('selectedPoints');
        const emptyHint = document.getElementById('selectedEmptyHint');
        const hiddenInputs = document.getElementById('hiddenInputs');

        function render() {
            // Available column: hide anything already selected.
            availableList.querySelectorAll('.available-point-item').forEach((li) => {
                const id = parseInt(li.dataset.id, 10);
                li.classList.toggle('hidden', selected.includes(id));
            });

            // Selected column: rebuild in order, with remove/reorder controls.
            selectedList.innerHTML = '';
            selected.forEach((id, index) => {
                const point = pointsById[id];
                if (!point) return;

                const li = document.createElement('li');
                li.className = 'px-3 py-2 text-sm text-gray-700 flex items-center justify-between gap-2';
                li.innerHTML = `
                    <span class="flex items-center gap-2 min-w-0">
                        <span class="flex-shrink-0 w-5 h-5 flex items-center justify-center text-[11px] font-bold bg-indigo-100 text-indigo-700 rounded-full">${index + 1}</span>
                        <span class="truncate">${escapeHtml(point.name)}</span>
                    </span>
                    <span class="flex items-center gap-1 flex-shrink-0">
                        <button type="button" data-action="up" data-id="${id}" class="px-1.5 py-0.5 text-xs text-gray-400 hover:text-gray-700 disabled:opacity-30" ${index === 0 ? 'disabled' : ''}>↑</button>
                        <button type="button" data-action="down" data-id="${id}" class="px-1.5 py-0.5 text-xs text-gray-400 hover:text-gray-700 disabled:opacity-30" ${index === selected.length - 1 ? 'disabled' : ''}>↓</button>
                        <button type="button" data-action="remove" data-id="${id}" class="px-1.5 py-0.5 text-xs text-red-400 hover:text-red-600">✕</button>
                    </span>
                `;
                selectedList.appendChild(li);
            });

            emptyHint.classList.toggle('hidden', selected.length > 0);

            // Sync hidden inputs so the plain <form> submit carries the order.
            hiddenInputs.innerHTML = '';
            selected.forEach((id) => {
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = 'access_point_ids[]';
                input.value = id;
                hiddenInputs.appendChild(input);
            });
        }

        availableList.addEventListener('click', (e) => {
            const li = e.target.closest('.available-point-item');
            if (!li) return;
            const id = parseInt(li.dataset.id, 10);
            if (!selected.includes(id)) {
                selected.push(id);
                render();
            }
        });

        selectedList.addEventListener('click', (e) => {
            const btn = e.target.closest('button[data-action]');
            if (!btn) return;
            const id = parseInt(btn.dataset.id, 10);
            const index = selected.indexOf(id);
            if (index === -1) return;

            if (btn.dataset.action === 'remove') {
                selected.splice(index, 1);
            } else if (btn.dataset.action === 'up' && index > 0) {
                [selected[index - 1], selected[index]] = [selected[index], selected[index - 1]];
            } else if (btn.dataset.action === 'down' && index < selected.length - 1) {
                [selected[index + 1], selected[index]] = [selected[index], selected[index + 1]];
            }
            render();
        });

        render();
    })();
</script>
