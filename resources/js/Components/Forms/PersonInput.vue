<script setup>
/**
 * A plain text input that also suggests staff from the IMIS people directory as
 * you type. The text is always free: choosing a suggestion only fills the box
 * (and emits `pick` so the page can fill a position or office too). If the
 * directory is off or IMIS is unreachable there are simply no suggestions and
 * this behaves exactly like an <input>.
 */
import { onBeforeUnmount, ref } from 'vue';

const props = defineProps({
    modelValue: { type: String, default: '' },
    id: { type: String, required: true },
    inputClass: { type: String, default: '' },
    placeholder: { type: String, default: '' },
    ariaLabel: { type: String, default: null },
});

const emit = defineEmits(['update:modelValue', 'pick']);

const results = ref([]);
const open = ref(false);
const active = ref(-1);
let available = true;
let timer = null;
let latest = 0;

const search = async (text) => {
    const ticket = ++latest;

    try {
        const response = await fetch(`/people/search?${new URLSearchParams({ q: text })}`, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
        if (!response.ok) return;

        const body = await response.json();
        available = body.available;

        // A slower, older answer must not overwrite a newer one.
        if (ticket !== latest) return;

        results.value = body.results;
        active.value = -1;
        open.value = body.results.length > 0;
    } catch {
        // Suggestions are optional: stay quiet.
    }
};

const onInput = (event) => {
    const text = event.target.value;
    emit('update:modelValue', text);

    clearTimeout(timer);
    latest++;

    if (!available || text.trim().length < 2) {
        results.value = [];
        open.value = false;
        return;
    }

    timer = setTimeout(() => search(text.trim()), 250);
};

const choose = (person) => {
    emit('update:modelValue', person.name);
    emit('pick', person);
    open.value = false;
    results.value = [];
};

const onKeydown = (event) => {
    if (!open.value) return;

    if (event.key === 'ArrowDown') {
        event.preventDefault();
        active.value = (active.value + 1) % results.value.length;
    } else if (event.key === 'ArrowUp') {
        event.preventDefault();
        active.value = (active.value - 1 + results.value.length) % results.value.length;
    } else if (event.key === 'Enter' && active.value >= 0) {
        event.preventDefault();
        choose(results.value[active.value]);
    } else if (event.key === 'Escape') {
        open.value = false;
    }
};

// Delay so a click on a suggestion lands before the list closes.
const onBlur = () => setTimeout(() => (open.value = false), 150);

onBeforeUnmount(() => clearTimeout(timer));
</script>

<template>
    <div class="relative">
        <input
            :id="id"
            :value="modelValue"
            type="text"
            autocomplete="off"
            role="combobox"
            aria-autocomplete="list"
            :aria-expanded="open"
            :aria-controls="`${id}-suggestions`"
            :aria-activedescendant="active >= 0 ? `${id}-option-${active}` : undefined"
            :aria-label="ariaLabel"
            :placeholder="placeholder"
            :class="inputClass"
            @input="onInput"
            @keydown="onKeydown"
            @blur="onBlur"
        />
        <ul
            v-show="open"
            :id="`${id}-suggestions`"
            role="listbox"
            class="absolute z-20 mt-1 max-h-64 w-full overflow-auto rounded-md border border-border bg-surface py-1 text-sm shadow-lg"
        >
            <li
                v-for="(person, index) in results"
                :id="`${id}-option-${index}`"
                :key="person.name + index"
                role="option"
                :aria-selected="index === active"
                class="cursor-pointer px-3 py-2"
                :class="index === active ? 'bg-surface-muted' : 'hover:bg-surface-muted'"
                @mousedown.prevent="choose(person)"
            >
                <div class="font-medium text-text">{{ person.name }}</div>
                <div v-if="person.position || person.office" class="text-xs text-text-muted">{{ [person.position, person.office].filter(Boolean).join(' · ') }}</div>
            </li>
        </ul>
    </div>
</template>
